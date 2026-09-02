import assert from "node:assert/strict";
import { mkdir, mkdtemp, readFile, readlink, writeFile } from "node:fs/promises";
import { createServer } from "node:http";
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";
import { createMockWordPress } from "./helpers/mock-wordpress.mjs";

const dataDirectory = await mkdtemp(path.join(tmpdir(), "dashless-tests-"));
process.env.DASHLESS_DATA_DIR = dataDirectory;
process.env.DASHLESS_DISABLE_KEYCHAIN = "1";

const { canonicalPost, contentDigest } = await import("../server/lib/digest.mjs");
const { saveSiteConnection } = await import("../server/lib/storage.mjs");
const { WordPressClient } = await import("../server/lib/wordpress.mjs");
const { createDraft, savePreviewLock, stageUpdate } = await import("../server/lib/editorial.mjs");
const { buildAtStableContentGeneration, handleRequest, verifyActivatedWpCloudRelease } = await import("../server/dashless-mcp.mjs");
const { assertContentGenerationMatches, buildWpCloudSftpBatch, createReleaseId, createWpCloudReleaseManifest, deployFrontend, validateDeployment, verifyPublicDigest, wpCloudReleasePrefix } = await import("../server/lib/frontend.mjs");

test("content digests are stable across taxonomy ordering and ignore status", () => {
  const first = { id: 8, slug: "hello", title: "Hello", content: "<p>World</p>", excerpt: "", featured_media: 2, categories: [4, 1, 4], tags: [9], status: "draft" };
  const second = { ...first, categories: [1, 4], status: "publish" };
  assert.deepEqual(canonicalPost(first, "post").categories, [1, 4]);
  assert.equal(contentDigest(first, "post"), contentDigest(second, "post"));
  assert.notEqual(contentDigest({ ...first, parent: 1 }, "page"), contentDigest({ ...first, parent: 2 }, "page"));
});

test("WordPress client covers drafts, stale writes, terms, media, and revisions", async (t) => {
  const mock = await createMockWordPress();
  t.after(() => mock.close());
  const client = new WordPressClient({ siteUrl: mock.url, username: "editor", password: "app-password" });
  const inspection = await client.inspectSite();
  assert.equal(inspection.site_name, "Mock Gazette");
  assert.equal(inspection.capabilities.posts, true);
  assert.equal(inspection.capabilities.dashless_plugin, true);
  assert.equal(inspection.content_model.post_types.length, 2);

  const draft = await client.createDraft("post", { title: "Fresh Draft", content: "<p>Draft body.</p>", categories: [1] });
  assert.equal(draft.status, "draft");
  const updated = await client.updateDraft("post", draft.id, { excerpt: "A useful dek." }, draft.modified_gmt);
  assert.equal(updated.excerpt, "A useful dek.");
  await assert.rejects(
    () => client.updateDraft("post", draft.id, { title: "Stale" }, draft.modified_gmt),
    (error) => error.code === "stale_post",
  );
  const revisions = await client.listRevisions("post", draft.id);
  assert.equal(revisions.length, 1);

  const terms = await client.ensureTerms("tag", ["Indiana", "Indiana"]);
  assert.equal(terms[0].created, true);
  assert.equal(terms[1].created, false);

  const image = path.join(dataDirectory, "test.png");
  await writeFile(image, Buffer.from("89504e470d0a1a0a", "hex"));
  const media = await client.uploadMedia(image, { altText: "A tiny test image", caption: "Test caption" });
  assert.equal(media.alt_text, "A tiny test image");
  assert.equal(media.mime_type, "image/png");
  const mediaLibrary = await client.listMedia({ perPage: 10 });
  assert.ok(mediaLibrary.items.some((item) => item.id === media.id));
  const repaired = await client.updateMedia(media.id, { altText: "A repaired description" });
  assert.equal(repaired.alt_text, "A repaired description");
  const inventory = await client.inspectContent();
  assert.equal(inventory.routes.categories, "/topics/[slug]/");
  assert.equal(inventory.counts.pages, 1);
  assert.equal(inventory.page_tree[0].path, "/about/");
});

test("editorial creates are idempotent and published updates are staged", async (t) => {
  const mock = await createMockWordPress();
  t.after(() => mock.close());
  await saveSiteConnection({ siteUrl: mock.url, username: "editor", password: "app-password", siteName: "Mock Gazette", userId: 1 });

  const first = await createDraft({ postType: "post", clientKey: "test-draft-0001", title: "Idempotent", content: "<p>Once.</p>" });
  const second = await createDraft({ postType: "post", clientKey: "test-draft-0001", title: "Duplicate", content: "<p>Twice.</p>" });
  assert.equal(first.id, second.id);
  assert.equal(second.idempotent_replay, true);

  const published = await new WordPressClient({ siteUrl: mock.url, username: "editor", password: "app-password" }).getPost("post", 1);
  const change = await stageUpdate({ postType: "post", id: 1, expectedModifiedGmt: published.modified_gmt, changes: { title: "A Better Published Story" } });
  assert.equal(change.payload.title, "A Better Published Story");
  assert.equal(mock.state.posts.get(1).title.raw, "A Published Story");
});

test("MCP protocol initializes and advertises the complete guarded tool surface", async () => {
  const initialized = await handleRequest({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2025-06-18" } });
  assert.equal(initialized.result.serverInfo.name, "dashless");
  assert.equal(initialized.result.serverInfo.version, "1.0.0");
  const listed = await handleRequest({ jsonrpc: "2.0", id: 2, method: "tools/list", params: {} });
  const names = listed.result.tools.map((tool) => tool.name);
  assert.ok(names.includes("start_setup"));
  assert.ok(names.includes("inspect_site"));
  assert.ok(names.includes("list_media"));
  assert.ok(names.includes("update_media"));
  assert.ok(names.includes("preview_frontend"));
  assert.ok(names.includes("create_preview"));
  assert.ok(names.includes("publish_previewed"));
  assert.ok(names.includes("rollback_wpcloud_release"));
  assert.ok(names.includes("check_wpcloud_deployment"));
  const publish = listed.result.tools.find((tool) => tool.name === "publish_previewed");
  assert.equal(publish.annotations.destructiveHint, true);
  assert.equal(publish.annotations.openWorldHint, true);
  assert.equal(listed.result.tools.find((tool) => tool.name === "rollback_wpcloud_release").annotations.destructiveHint, true);
  assert.equal(listed.result.tools.find((tool) => tool.name === "check_wpcloud_deployment").annotations.readOnlyHint, true);
});

test("publication refuses a preview after WordPress changes", async (t) => {
  const mock = await createMockWordPress();
  t.after(() => mock.close());
  const site = await saveSiteConnection({ siteUrl: mock.url, username: "editor", password: "app-password", siteName: "Mock Gazette", userId: 1 });
  const client = new WordPressClient({ siteUrl: mock.url, username: "editor", password: "app-password" });
  const draft = await client.createDraft("post", { title: "Conflict", content: "<p>Preview me.</p>", slug: "conflict" });
  const lock = await savePreviewLock({
    siteId: site.id,
    postType: "post",
    postId: draft.id,
    slug: draft.slug,
    baseModifiedGmt: draft.modified_gmt,
    changeId: null,
    digest: draft.digest,
    projectPath: "/tmp/not-used",
    previewUrl: "http://127.0.0.1/",
  });
  await client.updateDraft("post", draft.id, { title: "Changed elsewhere" }, draft.modified_gmt);
  const result = await handleRequest({
    jsonrpc: "2.0",
    id: 99,
    method: "tools/call",
    params: { name: "publish_previewed", arguments: { preview_token: lock.token } },
  });
  assert.equal(result.result.isError, true);
  assert.equal(result.result.structuredContent.error.code, "preview_stale");
  assert.equal(mock.state.posts.get(draft.id).status, "draft");
});

test("preview and production builds fail closed when target or other WordPress content changes during the build", async () => {
  for (const mutation of ["target_post", "other_content"]) {
    let generation = 41;
    const client = {
      inspectSite: async () => ({ dashless_plugin: { content_version: { generation } } }),
    };
    await assert.rejects(
      () => buildAtStableContentGeneration({
        client,
        build: async () => {
          generation += 1;
          return { dist_path: `/tmp/${mutation}` };
        },
      }),
      (error) => error.code === "content_changed_during_build"
        && error.details.before_generation === 41
        && error.details.after_generation === 42,
      mutation,
    );
  }

  const stable = await buildAtStableContentGeneration({
    client: { inspectSite: async () => ({ dashless_plugin: { content_version: { generation: 77 } } }) },
    build: async () => ({ dist_path: "/tmp/stable" }),
  });
  assert.equal(stable.generation, 77);
  assert.equal(stable.generation_verified, true);
  assert.equal(stable.result.dist_path, "/tmp/stable");
});

test("failed WP Cloud public verification automatically restores the prior release", async () => {
  let rollbackCalls = 0;
  let rollbackTarget = null;
  await assert.rejects(
    () => verifyActivatedWpCloudRelease({
      deployment: { kind: "wpcloud" },
      site: { site_url: "https://example.com" },
      password: "secret",
      url: "https://example.com/story/",
      digest: "a".repeat(64),
      releaseId: "20260811T120000000Z-abcdef",
      verify: async () => ({ verified: false, last: { found_release_id: "wrong" } }),
      rollback: async ({ expectedReleaseId }) => {
        rollbackCalls += 1;
        rollbackTarget = expectedReleaseId;
        return { rolled_back: true, release_id: "20260810T120000000Z-123456" };
      },
    }),
    (error) => error.code === "wpcloud_public_verification_failed"
      && error.details.rollback.release_id === "20260810T120000000Z-123456",
  );
  assert.equal(rollbackCalls, 1);
  assert.equal(rollbackTarget, "20260811T120000000Z-abcdef");

  const verified = await verifyActivatedWpCloudRelease({
    deployment: { kind: "wpcloud" },
    site: { site_url: "https://example.com" },
    password: "secret",
    url: "https://example.com/",
    releaseId: "20260811T120000000Z-abcdef",
    verify: async () => ({ verified: true, found_release_id: "20260811T120000000Z-abcdef" }),
    rollback: async () => { throw new Error("must not roll back a verified release"); },
  });
  assert.equal(verified.rolled_back, false);
});

test("local deployments switch an atomic current symlink without replacing prior releases", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "dashless-deploy-"));
  const dist = path.join(root, "dist");
  const releases = path.join(root, "public");
  await mkdir(dist);
  await writeFile(path.join(dist, "index.html"), "first");
  const first = await deployFrontend({ distPath: dist, deployment: { kind: "local", releases_path: releases, public_url: "http://localhost" } });
  assert.equal(await readFile(path.join(releases, "current", "index.html"), "utf8"), "first");
  await writeFile(path.join(dist, "index.html"), "second");
  const second = await deployFrontend({ distPath: dist, deployment: { kind: "local", releases_path: releases, public_url: "http://localhost" } });
  assert.notEqual(first.release_id, second.release_id);
  assert.equal(await readFile(path.join(releases, "current", "index.html"), "utf8"), "second");
  assert.equal(await readFile(path.join(first.release_path, "index.html"), "utf8"), "first");
  assert.equal(await readlink(path.join(releases, "current")), path.join("releases", second.release_id));
});

test("WP Cloud deployments use the uploads-backed immutable release contract", () => {
  const deployment = validateDeployment({
    kind: "wpcloud",
    public_url: "https://example.com/",
    host: "ssh.wp.cloud",
    user: "dashless",
  });
  assert.equal(deployment.htdocs_path, "/srv/htdocs");
  assert.equal(deployment.releases_path, "/srv/htdocs/wp-content/uploads/dashless");
  const releaseId = createReleaseId();
  assert.match(releaseId, /^[0-9T]+Z-[a-f0-9]{6}$/);
  assert.equal(
    wpCloudReleasePrefix(deployment, releaseId),
    `https://example.com/wp-content/uploads/dashless/releases/${releaseId}`,
  );
  assert.throws(
    () => validateDeployment({ kind: "wpcloud", public_url: "https://example.com", host: "ssh.wp.cloud" }),
    (error) => error.code === "wpcloud_user_required",
  );
  for (const publicUrl of ["https://user:secret@example.com", "https://example.com/site", "https://example.com?preview=1", "http://example.com"]) {
    assert.throws(
      () => validateDeployment({ kind: "wpcloud", public_url: publicUrl, host: "ssh.wp.cloud", user: "dashless" }),
      (error) => error.code === "deployment_url_invalid",
    );
  }
  assert.equal(
    validateDeployment({ kind: "wpcloud", public_url: "https://EXAMPLE.com./", host: "ssh.wp.cloud", user: "dashless" }).public_url,
    "https://example.com",
  );
});

test("WP Cloud deployment rejects server-executable files before upload", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "dashless-wpcloud-unsafe-"));
  await writeFile(path.join(root, "index.html"), "safe");
  await writeFile(path.join(root, "shell.php"), "<?php echo 'unsafe';");
  const deployment = validateDeployment({
    kind: "wpcloud",
    public_url: "https://example.com",
    host: "ssh.wp.cloud",
    user: "dashless",
  });
  await assert.rejects(
    () => deployFrontend({ distPath: root, deployment, site: {}, password: null }),
    (error) => error.code === "wpcloud_build_file_unsafe",
  );
});

test("WP Cloud release manifests lock every build file, digest, and WordPress generation", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "dashless-wpcloud-manifest-"));
  await mkdir(path.join(root, "_astro"));
  await writeFile(path.join(root, "index.html"), "home");
  await writeFile(path.join(root, "404.html"), "missing");
  await writeFile(path.join(root, "_astro", "site.css"), "body{}");
  await writeFile(path.join(root, "indexnow-key.txt"), "86c1d4af20bf4c5e97a3d8126e4b09fc\n");
  const deployment = validateDeployment({ kind: "wpcloud", public_url: "https://example.com", host: "ssh.wp.cloud", user: "dashless" });
  const manifest = await createWpCloudReleaseManifest({ distPath: root, deployment, releaseId: "20260808T120000000Z-abcdef", contentGeneration: 77 });
  assert.equal(manifest.public_host, "example.com");
  assert.equal(manifest.content_generation, 77);
  assert.equal(manifest.indexnow_key, "86c1d4af20bf4c5e97a3d8126e4b09fc");
  assert.deepEqual(manifest.files.map((entry) => entry.path), ["404.html", "_astro/site.css", "index.html", "indexnow-key.txt"]);
  assert.ok(manifest.files.every((entry) => /^[a-f0-9]{64}$/.test(entry.sha256)));
  assert.deepEqual(JSON.parse(await readFile(path.join(root, "dashless-release.json"), "utf8")), manifest);
});

test("WP Cloud refuses activation when WordPress no longer matches the built generation", () => {
  assert.deepEqual(assertContentGenerationMatches(77, 77, "pre_activation"), {
    expected_generation: 77,
    current_generation: 77,
    verified: true,
  });
  assert.throws(
    () => assertContentGenerationMatches(77, 78, "pre_activation"),
    (error) => error.code === "content_changed_during_deployment"
      && error.details.expected_generation === 77
      && error.details.current_generation === 78,
  );
});

test("WP Cloud SFTP batches quote paths and create new release files", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "dashless-wpcloud-batch-"));
  await mkdir(path.join(root, "images with spaces"));
  await writeFile(path.join(root, "index.html"), "home");
  await writeFile(path.join(root, "404.html"), "missing");
  await writeFile(path.join(root, "images with spaces", "hero image.webp"), "image");
  const deployment = validateDeployment({ kind: "wpcloud", public_url: "https://example.com", host: "ssh.wp.cloud", user: "dashless" });
  const prepared = await buildWpCloudSftpBatch({ distPath: root, deployment, releaseId: "20260808T120000000Z-abcdef" });
  assert.match(prepared.batch, /put .*dashless-wpcloud\.php.*dashless-wpcloud-20260808T120000000Z-abcdef\.stage/);
  assert.match(prepared.batch, /put .*".*hero image\.webp" .*".*hero image\.webp"/);
  assert.doesNotMatch(prepared.batch, /\nreput /);
  assert.equal(prepared.fileCount, 4);
  const withoutBridge = await buildWpCloudSftpBatch({ distPath: root, deployment, releaseId: "20260808T120000000Z-abcdef", bridgeMode: "skip" });
  assert.doesNotMatch(withoutBridge.batch, /dashless-wpcloud\.php/);
});

test("public verification requires the activated WP Cloud release as well as the content digest", async (t) => {
  const digest = "a".repeat(64);
  let releaseId = "old-release";
  let contentGeneration = 76;
  const server = createServer((request, response) => {
    response.setHeader("Content-Type", "text/html");
    response.setHeader("X-Dashless-Release", releaseId);
    response.setHeader("X-Dashless-Content-Generation", String(contentGeneration));
    response.end(`<meta name="dashless-content-digest" content="${digest}">`);
  });
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  t.after(() => new Promise((resolve) => server.close(resolve)));
  const url = `http://127.0.0.1:${server.address().port}/story/`;
  const stale = await verifyPublicDigest({ url, digest, releaseId: "new-release", contentGeneration: 77, attempts: 1 });
  assert.equal(stale.verified, false);
  assert.equal(stale.last.found_digest, digest);
  assert.equal(stale.last.found_release_id, "old-release");
  assert.equal(stale.last.found_content_generation, 76);
  releaseId = "new-release";
  contentGeneration = 77;
  const current = await verifyPublicDigest({ url, digest, releaseId: "new-release", contentGeneration: 77, attempts: 1 });
  assert.equal(current.verified, true);
  assert.equal(current.content_generation, 77);
});
