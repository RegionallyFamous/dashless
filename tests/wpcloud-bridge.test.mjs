import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { mkdir, mkdtemp, readFile, realpath, symlink, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";
const harness = path.resolve("tests/helpers/wpcloud-bridge-harness.php");
const { createWpCloudReleaseManifest, validateDeployment } = await import("../server/lib/frontend.mjs");

async function phpHarness(input) {
  return new Promise((resolve, reject) => {
    const child = spawn("php", [harness], { stdio: ["pipe", "pipe", "pipe"] });
    const stdout = [];
    const stderr = [];
    child.stdout.on("data", (chunk) => stdout.push(chunk));
    child.stderr.on("data", (chunk) => stderr.push(chunk));
    child.once("error", reject);
    child.once("close", (code) => {
      if (code !== 0) reject(new Error(`PHP harness exited ${code}: ${Buffer.concat(stderr).toString("utf8")}`));
      else resolve(JSON.parse(Buffer.concat(stdout).toString("utf8")));
    });
    child.stdin.end(JSON.stringify(input));
  });
}

async function createRelease(uploads, releaseId, contents) {
  const release = path.join(uploads, "dashless", "releases", releaseId);
  await mkdir(path.join(release, "stories", "hello"), { recursive: true });
  await writeFile(path.join(release, "index.html"), contents.home);
  await writeFile(path.join(release, "404.html"), "missing");
  await writeFile(path.join(release, "stories", "hello", "index.html"), contents.story);
  const deployment = validateDeployment({ kind: "wpcloud", public_url: "https://example.com", host: "ssh.wp.cloud", user: "dashless" });
  await createWpCloudReleaseManifest({ distPath: release, deployment, releaseId });
  return release;
}

test("the PHP bridge verifies activation and atomically rolls back", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "dashless-php-bridge-"));
  const uploads = path.join(root, "uploads");
  const firstId = "20260808T120000000Z-aaaaaa";
  const secondId = "20260808T120001000Z-bbbbbb";
  await createRelease(uploads, firstId, { home: "first", story: "first story" });
  await createRelease(uploads, secondId, { home: "second", story: "second story" });

  const first = await phpHarness({ action: "activate", uploads_basedir: uploads, release_id: firstId, public_url: "https://example.com" });
  assert.equal(first.ok, true);
  assert.equal(first.data.release_id, firstId);

  const second = await phpHarness({ action: "activate", uploads_basedir: uploads, options: first.options, release_id: secondId, public_url: "https://example.com" });
  assert.equal(second.ok, true);
  assert.equal(second.data.previous_release_id, firstId);

  const rolledBack = await phpHarness({ action: "rollback", uploads_basedir: uploads, options: second.options });
  assert.equal(rolledBack.ok, true);
  assert.equal(rolledBack.data.rolled_back, true);
  assert.equal(rolledBack.data.release_id, firstId);
  assert.equal(rolledBack.options.dashless_wpcloud_release.previous.id, secondId);
});

test("the PHP bridge refuses tampered releases without changing the active pointer", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "dashless-php-tamper-"));
  const uploads = path.join(root, "uploads");
  const currentId = "20260808T120000000Z-cccccc";
  const tamperedId = "20260808T120001000Z-dddddd";
  await createRelease(uploads, currentId, { home: "current", story: "current story" });
  const tampered = await createRelease(uploads, tamperedId, { home: "candidate", story: "candidate story" });
  const current = await phpHarness({ action: "activate", uploads_basedir: uploads, release_id: currentId, public_url: "https://example.com" });
  await writeFile(path.join(tampered, "index.html"), "changed after manifest");
  const result = await phpHarness({ action: "activate", uploads_basedir: uploads, options: current.options, release_id: tamperedId, public_url: "https://example.com" });
  assert.equal(result.ok, false);
  assert.equal(result.error.code, "dashless_release_incomplete");
  assert.equal(result.options.dashless_wpcloud_release.id, currentId);
});

test("the PHP bridge contains routes within the release and never serves PHP", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "dashless-php-paths-"));
  const release = path.join(root, "release");
  await mkdir(path.join(release, "stories", "hello"), { recursive: true });
  await writeFile(path.join(release, "stories", "hello", "index.html"), "story");
  await writeFile(path.join(release, "secret.php"), "<?php echo 'secret';");
  const outside = path.join(root, "outside.html");
  await writeFile(outside, "outside");
  await symlink(outside, path.join(release, "escape.html"));

  const story = await phpHarness({ action: "resolve", release_directory: release, request_path: "/stories/hello/" });
  assert.equal(story.ok, true);
  assert.equal(story.data.file, await realpath(path.join(release, "stories", "hello", "index.html")));
  for (const requestPath of ["/../outside.html", "/escape.html", "/secret.php"]) {
    const denied = await phpHarness({ action: "resolve", release_directory: release, request_path: requestPath });
    assert.equal(denied.data.file, null);
  }
});

test("the WordPress companion exposes the local workflow and content-generation contract", async () => {
  const source = await readFile(path.resolve("wordpress/dashless-wpcloud.php"), "utf8");
  assert.match(source, /Plugin Name: Dashless for WordPress/);
  assert.match(source, /Version: 1\.0\.0/);
  assert.match(source, /DASHLESS_CONTENT_VERSION_OPTION/);
  assert.match(source, /'\/site'/);
  assert.match(source, /dashless_mark_content_changed/);
});

test("the normal WordPress uninstall removes options but not static releases", async () => {
  const result = await new Promise((resolve, reject) => {
    const child = spawn("php", [path.resolve("tests/helpers/wp-uninstall-harness.php")], { stdio: ["ignore", "pipe", "pipe"] });
    const stdout = [];
    const stderr = [];
    child.stdout.on("data", (chunk) => stdout.push(chunk));
    child.stderr.on("data", (chunk) => stderr.push(chunk));
    child.once("error", reject);
    child.once("close", (code) => {
      if (code !== 0) reject(new Error(Buffer.concat(stderr).toString("utf8")));
      else resolve(JSON.parse(Buffer.concat(stdout).toString("utf8")));
    });
  });
  assert.deepEqual(result.sort(), ["dashless_content_version", "dashless_wpcloud_release"]);
  const source = await readFile(path.resolve("wordpress/uninstall.php"), "utf8");
  assert.doesNotMatch(source, /unlink|rmdir|delete_directory|wp_delete_file/);
});
