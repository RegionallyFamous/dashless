import assert from "node:assert/strict";
import { execFileSync, spawn } from "node:child_process";
import { copyFile, mkdir, mkdtemp, readFile, readdir, realpath, stat, symlink, writeFile } from "node:fs/promises";
import { createHash } from 'node:crypto';
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";
const harness = path.resolve("tests/helpers/wpcloud-bridge-harness.php");
const { createWpCloudDeltaBundle, createWpCloudReleaseManifest, validateDeployment } = await import("../server/lib/frontend.mjs");

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

async function createRelease(uploads, releaseId, contents, contentGeneration = null) {
  const release = path.join(uploads, "dashless", "releases", releaseId);
  await mkdir(path.join(release, "stories", "hello"), { recursive: true });
  await writeFile(path.join(release, "index.html"), contents.home);
  await writeFile(path.join(release, "404.html"), "missing");
  await writeFile(path.join(release, "stories", "hello", "index.html"), contents.story);
  const deployment = validateDeployment({ kind: "wpcloud", public_url: "https://example.com", host: "ssh.wp.cloud", user: "dashless" });
  await createWpCloudReleaseManifest({ distPath: release, deployment, releaseId, contentGeneration });
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

  const raced = await phpHarness({ action: "rollback", uploads_basedir: uploads, options: second.options, expected_release_id: firstId });
  assert.equal(raced.ok, false);
  assert.equal(raced.error.code, "dashless_rollback_release_changed");
  assert.equal(raced.error.data.status, 409);
  assert.equal(raced.options.dashless_wpcloud_release.id, secondId);

  const rolledBack = await phpHarness({ action: "rollback", uploads_basedir: uploads, options: second.options, expected_release_id: secondId });
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

test("activation refuses a stale or mismatched WordPress content generation", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "dashless-php-generation-"));
  const uploads = path.join(root, "uploads");
  const releaseId = "20260808T120000000Z-eeeeee";
  await createRelease(uploads, releaseId, { home: "current", story: "current story" }, 7);
  const options = { dashless_content_version: { generation: 7 } };

  const manifestMismatch = await phpHarness({
    action: "activate",
    uploads_basedir: uploads,
    options,
    release_id: releaseId,
    public_url: "https://example.com",
    content_generation: 8,
  });
  assert.equal(manifestMismatch.ok, false);
  assert.equal(manifestMismatch.error.code, "dashless_content_generation_mismatch");
  assert.equal(manifestMismatch.error.data.status, 409);
  assert.equal(manifestMismatch.options.dashless_wpcloud_release, undefined);

  const stale = await phpHarness({
    action: "activate",
    uploads_basedir: uploads,
    options: { dashless_content_version: { generation: 8 } },
    release_id: releaseId,
    public_url: "https://example.com",
    content_generation: 7,
  });
  assert.equal(stale.ok, false);
  assert.equal(stale.error.code, "dashless_content_changed_during_deployment");
  assert.equal(stale.error.data.status, 409);
  assert.equal(stale.options.dashless_wpcloud_release, undefined);

  const activated = await phpHarness({
    action: "activate",
    uploads_basedir: uploads,
    options,
    release_id: releaseId,
    public_url: "https://example.com",
    content_generation: 7,
  });
  assert.equal(activated.ok, true);
  assert.equal(activated.data.content_generation, 7);
  assert.equal(activated.options.dashless_wpcloud_release.content_generation, 7);
});

test("the PHP bridge contains routes within the release and never serves PHP", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "dashless-php-paths-"));
  const release = path.join(root, "release");
  await mkdir(path.join(release, "stories", "hello"), { recursive: true });
	await mkdir(path.join(release, "_dashless", "audio"), { recursive: true });
  await writeFile(path.join(release, "stories", "hello", "index.html"), "story");
	await writeFile(path.join(release, "_dashless", "audio", "broadcast.mp3"), "synthetic mp3 fixture");
  await writeFile(path.join(release, "secret.php"), "<?php echo 'secret';");
  const outside = path.join(root, "outside.html");
  await writeFile(outside, "outside");
  await symlink(outside, path.join(release, "escape.html"));

  const story = await phpHarness({ action: "resolve", release_directory: release, request_path: "/stories/hello/" });
  assert.equal(story.ok, true);
  assert.equal(story.data.file, await realpath(path.join(release, "stories", "hello", "index.html")));
	const audio = await phpHarness({ action: "resolve", release_directory: release, request_path: "/_dashless/audio/broadcast.mp3" });
	assert.equal(audio.ok, true);
	assert.equal(audio.data.file, await realpath(path.join(release, "_dashless", "audio", "broadcast.mp3")));
  for (const requestPath of ["/../outside.html", "/escape.html", "/secret.php"]) {
    const denied = await phpHarness({ action: "resolve", release_directory: release, request_path: requestPath });
    assert.equal(denied.data.file, null);
  }
});

test("the PHP bridge consolidates only core WordPress XML sitemaps", async () => {
  for (const requestPath of ["/wp-sitemap.xml", "/wp-sitemap-posts-post-1.xml", "/wp-sitemap-users-1.xml"]) {
    const result = await phpHarness({ action: "sitemap_redirect", request_path: requestPath });
    assert.equal(result.ok, true);
    assert.equal(result.data.target, "/sitemap.xml");
  }
  for (const requestPath of ["/sitemap.xml", "/wp-json/", "/wp-sitemap.xml/extra", "/stories/wp-sitemap.xml"]) {
    const result = await phpHarness({ action: "sitemap_redirect", request_path: requestPath });
    assert.equal(result.data.target, null);
  }
});

test("the private mailbox emails a sanitized note without creating a public comment", async () => {
  const result = await phpHarness({
    action: "mailbox",
    options: { admin_email: "nick@example.com" },
    posts: { 42: { post_type: "post", post_status: "publish", post_title: "A Teddy Story" } },
    post: 42,
    author_name: "Nick <script>alert(1)</script>",
    author_email: "reader@example.com",
    content: "Hello <b>Teddy</b>!\nThis is private.",
  });
  assert.equal(result.ok, true);
  assert.deepEqual(result.data, {
    delivered: true,
    private: true,
    message: "Transmission received. Teddy put it in the good pile.",
  });
  assert.equal(result.mail.length, 1);
  assert.equal(result.mail[0].to, "nick@example.com");
  assert.match(result.mail[0].subject, /^\[Teddy Mailbox\] A Teddy Story$/);
  assert.match(result.mail[0].message, /Name: Nick alert\(1\)/);
  assert.match(result.mail[0].message, /Hello Teddy!\nThis is private\./);
  assert.doesNotMatch(result.mail[0].message, /<script>|<b>/);
  assert.match(result.mail[0].headers.join("\n"), /Reply-To: Nick alert\(1\) <reader@example\.com>/);
});

test("the private mailbox validates input, traps bots, and reports delivery failures", async () => {
  const base = {
    action: "mailbox",
    options: { admin_email: "nick@example.com" },
    posts: { 42: { post_type: "post", post_status: "publish", post_title: "A Teddy Story" } },
    post: 42,
    author_name: "Reader",
    author_email: "reader@example.com",
    content: "A thoughtful note.",
  };

  const invalid = await phpHarness({ ...base, author_email: "not-an-email" });
  assert.equal(invalid.ok, false);
  assert.equal(invalid.error.code, "dashless_mailbox_email_invalid");
  assert.equal(invalid.error.data.status, 400);
  assert.equal(invalid.mail.length, 0);

  const bot = await phpHarness({ ...base, website: "https://spam.invalid" });
  assert.equal(bot.ok, true);
  assert.equal(bot.data.private, true);
  assert.equal(bot.mail.length, 0);

  const failed = await phpHarness({ ...base, mail_success: false });
  assert.equal(failed.ok, false);
  assert.equal(failed.error.code, "dashless_mailbox_delivery_failed");
  assert.equal(failed.error.data.status, 503);
  assert.match(failed.error.message, /Your text is still here/);
});

test("the private mailbox rate limits each salted connection bucket", async () => {
  const base = {
    action: "mailbox",
    options: { admin_email: "nick@example.com" },
    posts: { 42: { post_type: "post", post_status: "publish", post_title: "A Teddy Story" } },
    post: 42,
    author_name: "Reader",
    author_email: "reader@example.com",
    content: "A thoughtful note.",
    remote_addr: "198.51.100.44",
  };
  let transients = {};
  for (let attempt = 0; attempt < 5; attempt += 1) {
    const accepted = await phpHarness({ ...base, transients });
    assert.equal(accepted.ok, true);
    transients = accepted.transients;
  }
  const limited = await phpHarness({ ...base, transients });
  assert.equal(limited.ok, false);
  assert.equal(limited.error.code, "dashless_mailbox_rate_limited");
  assert.equal(limited.error.data.status, 429);
  assert.ok(limited.error.data.retry_after > 0);
  assert.equal(limited.mail.length, 0);
  assert.equal(Object.keys(transients).length, 1);
  assert.doesNotMatch(Object.keys(transients)[0], /198\.51\.100\.44/);
});

test("the Receiver List requires double opt-in and supports token-only unsubscribe", async () => {
  const requested = await phpHarness({ action: "receiver_request", email: "reader@example.com", source: "story" });
  assert.equal(requested.ok, true);
  assert.equal(requested.data.double_opt_in, true);
  assert.equal(requested.mail.length, 1);
  assert.doesNotMatch(requested.mail[0].message, /<img|utm_/i);
  const confirmation = requested.mail[0].message.match(/[?&]token=([a-f0-9]+)&action=confirm/);
  assert.ok(confirmation);

  const confirmed = await phpHarness({ action: "receiver_confirm", options: requested.options, token: confirmation[1], receiver_action: "confirm" });
  assert.equal(confirmed.ok, true);
  assert.equal(confirmed.status, 302);
  assert.match(confirmed.headers.Location, /state=confirmed/);
  const subscribers = confirmed.options.dashless_receiver_list;
  const subscriber = Object.values(subscribers)[0];
  assert.equal(subscriber.status, "active");

  const removed = await phpHarness({ action: "receiver_confirm", options: confirmed.options, token: subscriber.unsubscribe_token, receiver_action: "unsubscribe" });
  assert.equal(removed.ok, true);
  assert.match(removed.headers.Location, /state=unsubscribed/);
  assert.equal(Object.keys(removed.options.dashless_receiver_list).length, 0);
});

test("private reactions email one count-free signal and store nothing", async () => {
  const result = await phpHarness({
    action: "reaction",
    post: 19,
    reaction: "hell-yes",
    options: { admin_email: "nick@example.com" },
    posts: { 19: { post_type: "post", post_status: "publish", post_title: "A Teddy Story", post_name: "a-teddy-story" } },
  });
  assert.equal(result.ok, true);
  assert.equal(result.data.private, true);
  assert.equal(result.mail.length, 1);
  assert.match(result.mail[0].subject, /HELL YES/);
  assert.match(result.mail[0].message, /Nothing was stored and no public count exists/);
  assert.equal(result.options.admin_email, "nick@example.com");
});

test("Webmentions are verified, hidden pending email moderation, and public only after approval", async () => {
  const target = "https://teddy.blog/stories/a-teddy-story/";
  const source = "https://indie.example/notes/teddy";
  const received = await phpHarness({
    action: "webmention_receive",
    source,
    target,
    remote_body: `<html><head><title>A thoughtful reply</title></head><body><a href="${target}">Teddy</a></body></html>`,
    options: { admin_email: "nick@example.com" },
  });
  assert.equal(received.ok, true);
  assert.equal(received.status, 202);
  const pending = await phpHarness({ action: "webmention_list", target, options: received.options });
  assert.deepEqual(pending.data.mentions, []);

  const approval = received.mail[0].message.match(/Approve: .*?[?&]token=([a-f0-9]+)&action=approve/);
  assert.ok(approval);
  const moderated = await phpHarness({ action: "webmention_moderate", token: approval[1], moderation_action: "approve", options: received.options });
  assert.match(moderated.headers.Location, /webmention=approved/);
  const listed = await phpHarness({ action: "webmention_list", target, options: moderated.options });
  assert.equal(listed.data.mentions.length, 1);
  assert.equal(listed.data.mentions[0].source, source);
  assert.equal(listed.data.mentions[0].title, "A thoughtful reply");
});

test("the WordPress companion exposes the local workflow and content-generation contract", async () => {
  const source = await readFile(path.resolve("wordpress/dashless-wpcloud.php"), "utf8");
  assert.match(source, /^ \* Plugin Name: Dashless$/m);
  assert.match(source, /Version: 1\.0\.0/);
  assert.match(source, /DASHLESS_CONTENT_VERSION_OPTION/);
  assert.match(source, /'\/site'/);
  assert.match(source, /'\/mailbox'/);
  assert.match(source, /'\/receiver-list'/);
  assert.match(source, /'\/reaction'/);
  assert.match(source, /'\/webmention'/);
  assert.match(source, /dashless_mark_content_changed/);
  assert.doesNotMatch(source, /wp_insert_comment/);
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
  assert.deepEqual(result.sort(), ["dashless_content_version", "dashless_receiver_list", "dashless_webmentions", "dashless_wpcloud_release"]);
  const source = await readFile(path.resolve("wordpress/uninstall.php"), "utf8");
  assert.doesNotMatch(source, /unlink|rmdir|delete_directory|wp_delete_file/);
});

async function deltaFixture() {
  const root = await mkdtemp(path.join(tmpdir(), 'dashless-delta-'));
  const uploads = path.join(root, 'uploads');
  const baseId = '20260910T120000000Z-aaaaaa', id = '20260910T120001000Z-bbbbbb';
  const base = await createRelease(uploads, baseId, { home: 'old', story: 'same story' }, 7);
  await writeFile(path.join(base, 'removed.html'), 'must not leak into next release');
  const current = await phpHarness({ action: 'activate', uploads_basedir: uploads, release_id: baseId, public_url: 'https://example.com', content_generation: 7, options: { dashless_content_version: { generation: 7 } } });
  assert.equal(current.ok, true);
  const dist = path.join(root, 'dist');
  await mkdir(path.join(dist, 'stories/hello'), { recursive: true });
  await writeFile(path.join(dist, 'index.html'), 'new');
  await writeFile(path.join(dist, '404.html'), 'missing');
  await writeFile(path.join(dist, 'stories/hello/index.html'), 'same story');
  const deployment = validateDeployment({ kind: 'wpcloud', public_url: 'https://example.com', host: 'ssh.wp.cloud', user: 'dashless' });
  const manifest = await createWpCloudReleaseManifest({ distPath: dist, deployment, releaseId: id, contentGeneration: 7 });
  const plan = await phpHarness({ action: 'plan', uploads_basedir: uploads, options: current.options, manifest });
  assert.equal(plan.ok, true, JSON.stringify(plan));
  const incoming = path.join(uploads, 'dashless/incoming');
  await mkdir(incoming, { recursive: true });
  const bundle = await createWpCloudDeltaBundle({ distPath: dist, manifest, plan: plan.data, bundlePath: path.join(incoming, `${id}.tar.gz`) });
  return { root, uploads, baseId, base, id, dist, manifest, plan, bundle, current };
}

test('delta bundles reuse verified files, omit deletions, activate and roll back complete releases', async () => {
  const f = await deltaFixture();
  assert.deepEqual(f.plan.data.upload_files, ['index.html']);
  assert.equal(f.bundle.reused_files, 2);
  assert.equal(f.bundle.uploaded_files, 1);
  const assembled = await phpHarness({ action: 'assemble', uploads_basedir: f.uploads, options: f.current.options, release_id: f.id, sha256: f.bundle.sha256 });
  assert.equal(assembled.ok, true, JSON.stringify(assembled));
  assert.equal(assembled.options.dashless_wpcloud_release.id, f.baseId);
  const target = path.join(f.uploads, 'dashless/releases', f.id);
  assert.equal(await readFile(path.join(target, 'stories/hello/index.html'), 'utf8'), 'same story');
  assert.equal(await stat(path.join(target, 'removed.html')).catch(() => null), null);
  const retry = await phpHarness({ action: 'assemble', uploads_basedir: f.uploads, options: f.current.options, release_id: f.id, sha256: f.bundle.sha256 });
  assert.equal(retry.ok, true);
  assert.equal(retry.data.reused_assembly, true);
  const activated = await phpHarness({ action: 'activate', uploads_basedir: f.uploads, options: f.current.options, release_id: f.id, public_url: 'https://example.com', content_generation: 7 });
  assert.equal(activated.ok, true, JSON.stringify(activated));
  const rollback = await phpHarness({ action: 'rollback', uploads_basedir: f.uploads, options: activated.options, expected_release_id: f.id });
  assert.equal(rollback.data.release_id, f.baseId);
  assert.equal(await readFile(path.join(f.base, 'index.html'), 'utf8'), 'old');
  // Copies must be independent, not hard links into the rollback tree.
  await writeFile(path.join(target, 'stories/hello/index.html'), 'modified');
  assert.equal(await readFile(path.join(f.base, 'stories/hello/index.html'), 'utf8'), 'same story');
});

test('delta assembly rejects corrupt transfers and changed reuse sources without activating or leaving partial trees', async () => {
  const f = await deltaFixture();
  const corrupt = await phpHarness({ action: 'assemble', uploads_basedir: f.uploads, options: f.current.options, release_id: f.id, sha256: '0'.repeat(64) });
  assert.equal(corrupt.error.code, 'dashless_transfer_invalid');
  await writeFile(path.join(f.base, 'stories/hello/index.html'), 'changed since plan');
  const stale = await phpHarness({ action: 'assemble', uploads_basedir: f.uploads, options: f.current.options, release_id: f.id, sha256: f.bundle.sha256 });
  assert.equal(stale.ok, false);
  assert.equal(stale.error.code, 'dashless_assembly_failed');
  assert.equal(stale.options.dashless_wpcloud_release.id, f.baseId);
  assert.deepEqual(await readdir(path.join(f.uploads, 'dashless/releases')), [f.baseId]);
});

test('transfer plans reject traversal, executable files, duplicate paths and invalid sizes before any writes', async () => {
  const f = await deltaFixture();
  for (const badPath of ['../escape', '/escape', 'a/../../escape', 'a\\escape', 'x.php', 'a/.user.ini', 'a/.htaccess', 'dashless-release.json', '.dashless-transfer-sha256']) {
    const manifest = structuredClone(f.manifest);
    manifest.files.push({ path: badPath, bytes: 1, sha256: 'a'.repeat(64) });
    const r = await phpHarness({ action: 'plan', uploads_basedir: f.uploads, options: f.current.options, manifest });
    assert.equal(r.ok, false, badPath);
    assert.equal(r.error.code, 'dashless_manifest_invalid');
  }
  const manifest = structuredClone(f.manifest);
  manifest.files.push(manifest.files[0]);
  assert.equal((await phpHarness({ action: 'plan', uploads_basedir: f.uploads, manifest })).ok, false);
  manifest.files.pop(); manifest.files[0].bytes = '1';
  assert.equal((await phpHarness({ action: 'plan', uploads_basedir: f.uploads, manifest })).ok, false);
});

test('a first deployment bundles every file and cannot replace an existing release', async () => {
  const f = await deltaFixture();
  const plan = await phpHarness({ action: 'plan', uploads_basedir: f.uploads, manifest: f.manifest });
  assert.equal(plan.data.base_release_id, null);
  assert.equal(plan.data.upload_files.length, f.manifest.files.length);
  const bundle = await createWpCloudDeltaBundle({ distPath: f.dist, manifest: f.manifest, plan: plan.data, bundlePath: f.bundle.path });
  const assembled = await phpHarness({ action: 'assemble', uploads_basedir: f.uploads, release_id: f.id, sha256: bundle.sha256 });
  assert.equal(assembled.ok, true, JSON.stringify(assembled));
  const overwrite = await phpHarness({ action: 'assemble', uploads_basedir: f.uploads, release_id: f.id, sha256: 'f'.repeat(64) });
  assert.equal(overwrite.error.code, 'dashless_release_exists');
});

test('assembly never extracts unlisted files or archive symlinks', async () => {
  for (const attack of ['extra', 'symlink']) {
    const f = await deltaFixture();
    const malicious = path.join(f.root, 'malicious');
    await mkdir(malicious);
    await copyFile(path.join(f.dist, 'dashless-release.json'), path.join(malicious, 'dashless-release.json'));
    if (attack === 'extra') await writeFile(path.join(malicious, 'unlisted.php'), '<?php echo "unsafe";');
    else await symlink('../outside.html', path.join(malicious, 'index.html'));
    execFileSync('tar', ['--format=ustar', '-czf', f.bundle.path, 'dashless-release.json', attack === 'extra' ? 'unlisted.php' : 'index.html'], { cwd: malicious, env: { ...process.env, COPYFILE_DISABLE: '1' } });
    const sha256 = createHash('sha256').update(await readFile(f.bundle.path)).digest('hex');
    const r = await phpHarness({ action: 'assemble', uploads_basedir: f.uploads, options: f.current.options, release_id: f.id, sha256 });
    assert.equal(r.ok, false, attack);
    assert.equal(r.error.code, 'dashless_assembly_failed');
    assert.equal(r.options.dashless_wpcloud_release.id, f.baseId);
    assert.deepEqual(await readdir(path.join(f.uploads, 'dashless/releases')), [f.baseId]);
  }
});

test('planning refuses to reuse symlinks outside the release and detects same-size corruption', async () => {
  const f = await deltaFixture();
  await writeFile(path.join(f.base, 'stories/hello/index.html'), 'fake story');
  const corrupt = await phpHarness({ action: 'plan', uploads_basedir: f.uploads, options: f.current.options, manifest: f.manifest });
  assert.ok(corrupt.data.upload_files.includes('stories/hello/index.html'));
  const outside = path.join(f.root, 'outside.html');
  await writeFile(outside, 'new');
  await symlink(outside, path.join(f.base, 'escape.html'));
  const manifest = structuredClone(f.manifest);
  manifest.files.push({ path: 'escape.html', bytes: 3, sha256: createHash('sha256').update('new').digest('hex') });
  const planned = await phpHarness({ action: 'plan', uploads_basedir: f.uploads, options: f.current.options, manifest });
  assert.ok(planned.data.upload_files.includes('escape.html'));
});
