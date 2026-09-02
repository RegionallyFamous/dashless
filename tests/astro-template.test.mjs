import assert from "node:assert/strict";
import { mkdtemp, readFile, readdir } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";
import { createMockWordPress } from "./helpers/mock-wordpress.mjs";
import { auditSite } from "../skills/design-dashless-astro-sites/scripts/audit-dist.mjs";

const dataDirectory = await mkdtemp(path.join(tmpdir(), "dashless-astro-data-"));
process.env.DASHLESS_DATA_DIR = dataDirectory;
process.env.DASHLESS_DISABLE_KEYCHAIN = "1";

const { createFrontend, buildFrontend } = await import("../server/lib/frontend.mjs");
const { saveSiteConnection, updateActiveSite } = await import("../server/lib/storage.mjs");
const { handleRequest } = await import("../server/dashless-mcp.mjs");
const { WordPressClient } = await import("../server/lib/wordpress.mjs");

test("the generated Astro frontend installs and produces digest-marked static pages", { timeout: 180_000 }, async (t) => {
  const mock = await createMockWordPress();
  t.after(() => mock.close());
  const publishedPost = mock.state.posts.get(1);
  publishedPost.content.raw = '<script>globalThis.dashlessRawScript = true</script><p>Unfiltered editor source.</p>';
  publishedPost.content.rendered = `<p>Original body.</p><img src="${mock.url}/wp-content/uploads/inline.png" alt="Inline test" width="640" height="360">`;
  const root = await mkdtemp(path.join(tmpdir(), "dashless-astro-"));
  const project = path.join(root, "publication");
  const client = new WordPressClient({ siteUrl: mock.url, username: "editor", password: "app-password" });
  const childPage = await client.createDraft("page", { title: "Team", content: "<p>Meet the team.</p>", slug: "team", parent: 1, menu_order: 2 });
  await client.publishCurrent("page", childPage.id);
  await createFrontend({
    projectPath: project,
    siteName: "Mock Gazette",
    siteDescription: "Stories from the mock newsroom.",
    wordpressUrl: mock.url,
    publicUrl: "https://gazette.example",
    postsPath: "stories",
  });
  const build = await buildFrontend({
    projectPath: project,
    site: { site_url: mock.url, username: "editor" },
    password: "app-password",
    releasePrefix: "https://gazette.example/wp-content/uploads/dashless/releases/20260808T120000000Z-abcdef",
  });
  assert.equal(build.dependencies_installed, true);
  const lock = JSON.parse(await readFile(path.join(project, "package-lock.json"), "utf8"));
  assert.equal(lock.packages[""].version, "1.0.0");
  const audit = auditSite({ projectPath: project, production: true });
  assert.equal(audit.errors.length, 0, JSON.stringify(audit.errors, null, 2));
  assert.equal(audit.warnings.length, 0, JSON.stringify(audit.warnings, null, 2));
  const home = await readFile(path.join(build.dist_path, "index.html"), "utf8");
  const story = await readFile(path.join(build.dist_path, "stories", "published-story", "index.html"), "utf8");
  const page = await readFile(path.join(build.dist_path, "about", "index.html"), "utf8");
  const nestedPage = await readFile(path.join(build.dist_path, "about", "team", "index.html"), "utf8");
  const topic = await readFile(path.join(build.dist_path, "topics", "news", "index.html"), "utf8");
  const stories = await readFile(path.join(build.dist_path, "stories", "index.html"), "utf8");
  const search = await readFile(path.join(build.dist_path, "search", "index.html"), "utf8");
  const globalCss = await readFile(path.join(project, "src", "styles", "global.css"), "utf8");
  const socialFiles = await readdir(path.join(build.dist_path, "_dashless", "social"));
  assert.equal(socialFiles.length, 1);
  assert.match(socialFiles[0], /^post-1-[a-f0-9]{12}\.png$/);
  const socialCard = await readFile(path.join(build.dist_path, "_dashless", "social", socialFiles[0]));
  assert.equal(socialCard.subarray(0, 8).toString("hex"), "89504e470d0a1a0a");
  assert.equal(socialCard.readUInt32BE(16), 1200);
  assert.equal(socialCard.readUInt32BE(20), 630);
  assert.match(home, /Mock Gazette/);
  assert.match(home, /href="\/favicon\.svg"/);
  assert.doesNotMatch(home, /PERSONAL WEB LOG|WELCOME TO MY WEBSITE/);
  assert.match(home, /DO NOT PRESS/);
  assert.match(home, /Try typing T-E-D-D-Y/);
  assert.match(home, /https:\/\/gazette\.example\/wp-content\/uploads\/dashless\/releases\/20260808T120000000Z-abcdef\/_astro\//);
  assert.match(story, /dashless-content-digest/);
  assert.match(story, /Original body/);
  assert.doesNotMatch(story, /dashlessRawScript|Unfiltered editor source/);
  assert.match(story, /https:\/\/gazette\.example\/wp-content\/uploads\/dashless\/releases\/20260808T120000000Z-abcdef\/_dashless\/media\/featured-49-hero\.png/);
  assert.match(story, /https:\/\/gazette\.example\/wp-content\/uploads\/dashless\/releases\/20260808T120000000Z-abcdef\/_dashless\/media\/post-1-1-inline\.png/);
  assert.match(story, new RegExp(`https:\\/\\/gazette\\.example\\/wp-content\\/uploads\\/dashless\\/releases\\/20260808T120000000Z-abcdef\\/_dashless\\/social\\/${socialFiles[0]}`));
  assert.match(story, /property="og:image:alt" content="A Published Story — Mock Gazette"/);
  assert.match(story, /name="twitter:card" content="summary_large_image"/);
  assert.ok((await readFile(path.join(build.dist_path, "_dashless", "media", "featured-49-hero.png"))).length > 0);
  assert.ok((await readFile(path.join(build.dist_path, "_dashless", "media", "post-1-1-inline.png"))).length > 0);
  assert.match(home, /view-transition-name: dashless-story-title-1/);
  assert.match(home, /view-transition-name: dashless-story-image-1/);
  assert.match(story, /view-transition-name: dashless-story-title-1/);
  assert.match(story, /view-transition-name: dashless-story-image-1/);
  assert.match(globalCss, /@view-transition\s*\{[\s\S]*navigation:\s*auto/);
  assert.match(globalCss, /prefers-reduced-motion/);
  assert.match(globalCss, /\.teddy-sighting\[hidden\]/);
  assert.match(globalCss, /::view-transition-old\(root\)/);
  assert.match(page, /About this publication/);
  assert.match(nestedPage, /Meet the team/);
  assert.match(topic, /A Published Story/);
  assert.match(stories, /All stories/);
  assert.match(search, /dashless-search-index/);
  const notFound = await readFile(path.join(build.dist_path, "404.html"), "utf8");
  assert.match(notFound, /name="robots" content="noindex,follow"/);

  await saveSiteConnection({ siteUrl: mock.url, username: "editor", password: "app-password", siteName: "Mock Gazette", userId: 1 });
  await updateActiveSite({ frontend: { project_path: project, posts_path: "stories", public_url: "https://gazette.example" } });
  const nestedPreview = await handleRequest({
    jsonrpc: "2.0",
    id: 0,
    method: "tools/call",
    params: { name: "create_preview", arguments: { post_type: "page", id: childPage.id } },
  });
  assert.equal(nestedPreview.result.isError, false, nestedPreview.result.content[0].text);
  assert.match(nestedPreview.result.structuredContent.preview_url, /\/about\/team\/$/);
  const created = await handleRequest({
    jsonrpc: "2.0",
    id: 1,
    method: "tools/call",
    params: {
      name: "create_draft",
      arguments: {
        post_type: "post",
        client_key: "astro-golden-path-0001",
        title: "The Golden Path",
        slug: "golden-path",
        excerpt: "A complete Dashless publishing test.",
        content: "<p>Drafted, previewed, and published without wp-admin.</p>",
        categories: [1],
      },
    },
  });
  assert.equal(created.result.isError, false);
  const draftId = created.result.structuredContent.id;
  const previewed = await handleRequest({
    jsonrpc: "2.0",
    id: 2,
    method: "tools/call",
    params: { name: "create_preview", arguments: { post_type: "post", id: draftId } },
  });
  assert.equal(previewed.result.isError, false, previewed.result.content[0].text);
  assert.match(previewed.result.structuredContent.preview_url, /\/stories\/golden-path\/$/);
  assert.equal(previewed.result.structuredContent.content_generation_verified, true);
  assert.equal(previewed.result.structuredContent.content_generation, mock.state.clock);
  const previewHtml = await (await fetch(previewed.result.structuredContent.preview_url)).text();
  assert.match(previewHtml, /Drafted, previewed, and published/);

  const published = await handleRequest({
    jsonrpc: "2.0",
    id: 3,
    method: "tools/call",
    params: { name: "publish_previewed", arguments: { preview_token: previewed.result.structuredContent.preview_token } },
  });
  assert.equal(published.result.isError, false, published.result.content[0].text);
  assert.equal(published.result.structuredContent.published_in_wordpress, true);
  assert.equal(published.result.structuredContent.production_built, true);
  assert.equal(published.result.structuredContent.deployed, false);
  assert.equal(mock.state.posts.get(draftId).status, "publish");

  const reused = await handleRequest({
    jsonrpc: "2.0",
    id: 4,
    method: "tools/call",
    params: { name: "publish_previewed", arguments: { preview_token: previewed.result.structuredContent.preview_token } },
  });
  assert.equal(reused.result.isError, true);
  assert.equal(reused.result.structuredContent.error.code, "preview_already_used");

  mock.state.posts.clear();
  mock.state.pages.clear();
  const emptyBuild = await buildFrontend({
    projectPath: project,
    site: { site_url: mock.url, username: "editor" },
    password: "app-password",
  });
  const emptyHome = await readFile(path.join(emptyBuild.dist_path, "index.html"), "utf8");
  assert.match(emptyHome, /No published stories yet/);
  assert.match(emptyHome, /update when a story is published in WordPress/);
  assert.doesNotMatch(emptyHome, /Create your first WordPress draft|A Published Story|The Golden Path|About this publication|Meet the team/);
  await assert.rejects(readFile(path.join(emptyBuild.dist_path, "_dashless", "social", socialFiles[0])));

  const runtime = JSON.parse(await readFile(path.join(dataDirectory, "runtime", "preview.json"), "utf8"));
  try { process.kill(runtime.pid, "SIGTERM"); } catch {}
});
