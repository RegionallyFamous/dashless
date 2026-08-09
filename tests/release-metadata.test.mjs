import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const version = "1.0.0";

async function json(file) {
  return JSON.parse(await readFile(path.resolve(file), "utf8"));
}

test("all public release metadata agrees on Dashless 1.0.0", async () => {
  const [rootPackage, manifest, templatePackage, templateLock] = await Promise.all([
    json("package.json"),
    json(".codex-plugin/plugin.json"),
    json("templates/astro/package.json"),
    json("templates/astro/package-lock.json"),
  ]);
  assert.equal(rootPackage.version, version);
  assert.equal(manifest.version, version);
  assert.equal(templatePackage.version, version);
  assert.equal(templateLock.packages[""].version, version);
  assert.equal(templatePackage.dependencies.sharp, "^0.35.3");
  assert.equal(templateLock.packages[""].dependencies.sharp, "^0.35.3");

  const [server, packaging, wordpress, readme, changelog] = await Promise.all([
    readFile(path.resolve("server/dashless-mcp.mjs"), "utf8"),
    readFile(path.resolve("scripts/package.mjs"), "utf8"),
    readFile(path.resolve("wordpress/dashless-wpcloud.php"), "utf8"),
    readFile(path.resolve("README.md"), "utf8"),
    readFile(path.resolve("CHANGELOG.md"), "utf8"),
  ]);
  assert.match(server, /serverInfo: \{ name: "dashless", title: "Dashless", version: "1\.0\.0" \}/);
  assert.match(packaging, /const codexVersion = "1\.0\.0"/);
  assert.match(wordpress, /\* Version: 1\.0\.0/);
  assert.match(readme, /What ships in 1\.0/);
  assert.doesNotMatch(readme, /What ships in 1\.2|dashless-1\.2/);
  assert.match(changelog, /## 1\.0\.0/);
});

test("agent instructions enforce WordPress-only production content", async () => {
  const [server, dashlessSkill, designSkill, publishingContract] = await Promise.all([
    readFile(path.resolve("server/dashless-mcp.mjs"), "utf8"),
    readFile(path.resolve("skills/dashless/SKILL.md"), "utf8"),
    readFile(path.resolve("skills/design-dashless-astro-sites/SKILL.md"), "utf8"),
    readFile(path.resolve("docs/publishing-contract.md"), "utf8"),
  ]);

  for (const source of [server, dashlessSkill, designSkill, publishingContract]) {
    assert.match(source, /WordPress is the (sole|only) (source of )?production/i);
    assert.match(source, /empty state/i);
  }
  assert.match(server, /Never fabricate or seed Posts, Pages, media, or terms/);
  assert.match(dashlessSkill, /design, preview, deployment, or launch/);
  assert.match(designSkill, /Never write fixtures to WordPress/);
});
