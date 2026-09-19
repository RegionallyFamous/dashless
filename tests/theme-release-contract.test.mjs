import test from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdtemp, mkdir, writeFile, rm } from "node:fs/promises";
import { createServer } from "node:http";
import os from "node:os";
import path from "node:path";
import { verifyLiveRelease, verifyReleaseDirectory } from "../scripts/check-theme-release.mjs";
import { deploymentTarget } from "../hosted/scripts/publish-hub-release.mjs";
import { readFileSync } from "node:fs";

async function fixture() {
  const directory = await mkdtemp(path.join(os.tmpdir(), "dashless-theme-release-"));
  const files = { "index.html": "<html>theme</html>\n", "404.html": "<html>404</html>\n" };
  const entries = [];
  for (const [relative, content] of Object.entries(files)) {
    await mkdir(path.dirname(path.join(directory, relative)), { recursive: true });
    await writeFile(path.join(directory, relative), content);
    entries.push({ path: relative, bytes: Buffer.byteLength(content), sha256: createHash("sha256").update(content).digest("hex") });
  }
  await writeFile(path.join(directory, "dashless-release.json"), JSON.stringify({
    version: 1,
    release_id: "20260918T065302000Z-a1b2c3",
    public_host: "example.com",
    files: entries,
  }));
  return directory;
}

test("theme release verifier accepts a complete immutable artifact", async () => {
  const directory = await fixture();
  try {
    assert.deepEqual(await verifyReleaseDirectory(directory), {
      release_id: "20260918T065302000Z-a1b2c3",
      public_host: "example.com",
      files: 2,
    });
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test("theme release verifier accepts space-separated CLI arguments", async () => {
  const directory = await fixture();
  try {
    const output = execFileSync(process.execPath, ["scripts/check-theme-release.mjs", "--dist", directory], { encoding: "utf8" });
    assert.match(output, /"ok": true/);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test("the publisher defaults to the canonical Dashless WP Cloud target", () => {
  const target = deploymentTarget();
  const contract = JSON.parse(readFileSync("config/deployment-contract.json", "utf8"));
  assert.equal(target.origin, contract.canonical_hub.origin);
  assert.equal(target.sshTarget, contract.canonical_hub.ssh_target);
  assert.equal(target.remoteRoot, contract.canonical_hub.remote_root);
});

test("theme release verifier rejects stale hashes", async () => {
  const directory = await fixture();
  try {
    const manifestPath = path.join(directory, "dashless-release.json");
    const manifest = JSON.parse(await (await import("node:fs/promises")).readFile(manifestPath, "utf8"));
    manifest.files[0].sha256 = "0".repeat(64);
    await writeFile(manifestPath, JSON.stringify(manifest));
    await assert.rejects(() => verifyReleaseDirectory(directory), /hash is stale/);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test("theme release verifier requires the live release header and selected theme", async () => {
  const server = createServer((request, response) => {
    response.setHeader("X-Dashless-Release", "20260918T065302000Z-a1b2c3");
    response.writeHead(200, { "content-type": "text/html" });
    response.end('<html data-theme="field-notes"></html>');
  });
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  try {
    const { port } = server.address();
    const result = await verifyLiveRelease({
      url: `http://127.0.0.1:${port}/`,
      releaseId: "20260918T065302000Z-a1b2c3",
      theme: "field-notes",
    });
    assert.equal(result.release_id, "20260918T065302000Z-a1b2c3");
    await assert.rejects(
      () => verifyLiveRelease({ url: `http://127.0.0.1:${port}/`, releaseId: "20260918T065302000Z-a1b2c3", theme: "after-hours" }),
      /expected after-hours/,
    );
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});
