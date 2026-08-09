import assert from "node:assert/strict";
import { mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";
import { auditSite } from "../skills/design-dashless-astro-sites/scripts/audit-dist.mjs";

test("the Astro audit catches unsafe and inaccessible generated markup", async (t) => {
  const project = await mkdtemp(path.join(tmpdir(), "dashless-audit-"));
  t.after(() => rm(project, { recursive: true, force: true }));
  await mkdir(path.join(project, "dist"), { recursive: true });
  await mkdir(path.join(project, "src"), { recursive: true });
  await writeFile(path.join(project, "package.json"), JSON.stringify({
    scripts: { build: "astro check && astro build" },
    dependencies: { astro: "^7.1.6" },
  }));
  await writeFile(path.join(project, "src", "global.css"), ":focus-visible{} @media (prefers-reduced-motion: reduce){} @media (max-width: 600px){} @media (forced-colors: active){}");
  await writeFile(path.join(project, "dist", "index.html"), `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Broken fixture</title><meta name="description" content="Fixture"><meta property="og:type" content="article"><link rel="canonical" href="https://example.com/"></head><body><main id="duplicate"><h1>Fixture</h1><img src="missing.png"><button onclick="alert(1)"></button><div id="duplicate"></div></main></body></html>`);

  const audit = auditSite({ projectPath: project });
  const codes = new Set(audit.errors.map((item) => item.code));
  for (const expected of ["language-missing", "duplicate-id", "image-alt-missing", "inline-event-handler", "button-name-missing"]) {
    assert.ok(codes.has(expected), `Expected ${expected}; received ${[...codes].join(", ")}`);
  }
  assert.ok(audit.warnings.some((item) => item.code === "article-social-image-missing"));

  await writeFile(path.join(project, "dist", "robots.txt"), "Sitemap: http://127.0.0.1:4321/sitemap.xml\n");
  const productionAudit = auditSite({ projectPath: project, production: true });
  assert.ok(
    productionAudit.errors.some((item) => item.code === "preview-origin-in-production"),
    "Expected production mode to reject a loopback preview origin.",
  );
});
