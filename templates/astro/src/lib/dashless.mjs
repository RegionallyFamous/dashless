import { createHash } from "node:crypto";
import { mkdir, readFile } from "node:fs/promises";
import path from "node:path";
import { mediaCache, copyCachedFile } from "./media-cache.mjs";
import userConfig from "../../dashless.config.mjs";
import { loadSnapshot } from "./snapshot.mjs";
import { generateSocialCard } from "./social-card.mjs";

const snapshot = await loadSnapshot(process.env.DASHLESS_SNAPSHOT);

export const config = {
  language: "en",
  logo: /** @type {string | null} */ (null),
  design: /** @type {{theme_id?: string, theme_version?: number, palette?: string, typography?: string, layout?: string} | null} */ (null),
  navigation: /** @type {{page_id:number, label:string}[] | null} */ (null),
  topicsPath: "topics",
  tagsPath: "tags",
  postsPerPage: 12,
  homePageId: 0,
  postsPageId: 0,
  mirrorMedia: true,
  ...userConfig,
};

const wordpressUrl = (process.env.WORDPRESS_URL || config.wordpressUrl).replace(/\/$/, "");
const releasePrefix = (process.env.DASHLESS_RELEASE_PREFIX || "").replace(/\/$/, "");
const username = process.env.WORDPRESS_USERNAME || "";
const password = process.env.WORDPRESS_APP_PASSWORD || "";
const auth = username && password
  ? `Basic ${Buffer.from(`${username}:${password}`, "utf8").toString("base64")}`
  : null;
const requestCache = new Map();

function textField(value) {
  if (typeof value === "string") return value;
  return value?.raw ?? value?.rendered ?? "";
}

function renderedField(value) {
  if (typeof value === "string") return value;
  return value?.rendered ?? value?.raw ?? "";
}

function sortedIds(value) {
  return Array.isArray(value)
    ? [...new Set(value.filter(Number.isInteger))].sort((a, b) => a - b)
    : [];
}

function canonicalPost(post, postType) {
  return {
    post_type: postType,
    id: Number(post.id),
    slug: String(post.slug ?? ""),
    title: textField(post.title),
    content: textField(post.content),
    excerpt: textField(post.excerpt),
    featured_media: Number(post.featured_media || 0),
    parent: postType === "page" ? Number(post.parent || 0) : 0,
    menu_order: postType === "page" ? Number(post.menu_order || 0) : 0,
    categories: sortedIds(post.categories),
    tags: sortedIds(post.tags),
  };
}

function contentDigest(post, postType) {
  return createHash("sha256")
    .update(JSON.stringify(canonicalPost(post, postType)), "utf8")
    .digest("hex");
}

async function wpRequest(route, query = {}) {
  if (snapshot) return snapshot.request(route, query);
  const url = new URL(`${wordpressUrl}/wp-json/${route.replace(/^\//, "")}`);
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== "") url.searchParams.set(key, String(value));
  }
  const key = url.toString();
  if (!requestCache.has(key)) {
    requestCache.set(key, (async () => {
      const response = await fetch(url, { headers: auth ? { Authorization: auth } : {} });
      if (!response.ok) throw new Error(`WordPress returned ${response.status} for ${url.pathname}.`);
      return { data: await response.json(), headers: response.headers };
    })());
  }
  return requestCache.get(key);
}

async function optionalWpRequest(route, query = {}) {
  try {
    return await wpRequest(route, query);
  } catch (error) {
    if (/returned (403|404) /.test(error.message)) return null;
    throw error;
  }
}

async function listAll(endpoint, extraQuery = {}) {
  const items = [];
  let page = 1;
  while (true) {
    const response = await wpRequest(`wp/v2/${endpoint}`, {
      context: auth ? "edit" : "view",
      status: "publish",
      per_page: 100,
      page,
      orderby: "date",
      order: "desc",
      ...extraQuery,
    });
    items.push(...response.data);
    const totalPages = Number(response.headers.get("x-wp-totalpages") || 1);
    if (page >= totalPages) break;
    page += 1;
  }
  return items;
}

async function listTerms(endpoint, urlBase) {
  const terms = await listAll(endpoint, { status: undefined, hide_empty: true, orderby: "name", order: "asc" });
  return terms.map((term) => ({
    id: Number(term.id),
    name: textField(term.name),
    slug: String(term.slug || ""),
    description: textField(term.description),
    count: Number(term.count || 0),
    parent: Number(term.parent || 0),
    url: `/${urlBase}/${term.slug}/`,
  }));
}

async function loadPreviewPayload() {
  const file = process.env.DASHLESS_PREVIEW_PAYLOAD;
  if (!file) return null;
  return JSON.parse(await readFile(file, "utf8"));
}

function safeMediaName(url, prefix = "asset") {
  const parsed = new URL(url);
  const original = decodeURIComponent(path.basename(parsed.pathname)).replace(/[^A-Za-z0-9._-]/g, "-");
  return `${prefix}-${original || "media"}`;
}

function mirroredMediaRelative(url, prefix) {
  if (snapshot?.asset(url)) return `/${snapshot.asset(url).relative}`;
  if (!config.mirrorMedia || !url || !url.startsWith("http")) return null;
  let parsed;
  try { parsed = new URL(url); } catch { return null; }
  if (parsed.origin !== new URL(wordpressUrl).origin || !parsed.pathname.includes("/wp-content/uploads/")) return null;
  return `/_dashless/media/${safeMediaName(url, prefix)}`;
}

const mirroredFiles = new Map();
function mirrorUrl(url, prefix) {
  const key = `${prefix}:${url}`;
  if (!mirroredFiles.has(key)) mirroredFiles.set(key, mirrorFile(url, prefix));
  return mirroredFiles.get(key);
}

async function mirrorFile(url, prefix) {
  const relative = mirroredMediaRelative(url, prefix);
  if (!relative) return url;
  const destination = path.join(process.cwd(), "public", relative);
  const buildDestination = path.join(process.cwd(), "dist", relative);
  const cached = snapshot ? { file: await snapshot.localAsset(url) } : await mediaCache.get(url);
  await mkdir(path.dirname(destination), { recursive: true });
  await copyCachedFile(cached.file, destination);
  await mkdir(path.dirname(buildDestination), { recursive: true });
  await copyCachedFile(destination, buildDestination);
  return releasePrefix ? `${releasePrefix}${relative}` : relative;
}

async function mirrorHtml(html, prefix) {
  if (!config.mirrorMedia || !html) return html;
  if (snapshot) {
    let output = html;
    for (const match of html.matchAll(/(?:https?:\/\/[^\s"'<>]+|\/wp-content\/uploads\/[^\s"'<>]+)/g)) {
      const url = new URL(match[0].replaceAll("&amp;", "&"), config.wordpressUrl).href;
      if (snapshot.asset(url)) output = output.replaceAll(match[0], await mirrorUrl(url, prefix));
    }
    return output;
  }
  const matches = [...html.matchAll(/https?:\/\/[^\s"'<>]+\/wp-content\/uploads\/[^\s"'<>]+/g)];
  let output = html;
  for (const [index, match] of matches.entries()) {
    const clean = match[0].replaceAll("&amp;", "&");
    const local = await mirrorUrl(clean, `${prefix}-${index + 1}`);
    output = output.replaceAll(match[0], local.replaceAll("&", "&amp;"));
  }
  return output;
}

async function normalize(post, postType, termMaps = {}) {
  const canonical = canonicalPost(post, postType);
  const digest = contentDigest(canonical, postType);
  const categoryTerms = canonical.categories.map((id) => termMaps.categories?.get(id)).filter(Boolean);
  const tagTerms = canonical.tags.map((id) => termMaps.tags?.get(id)).filter(Boolean);
  let featuredImage = null;
  let featuredImagePath = null;
  let featuredImageUrl = null;
  if (canonical.featured_media) {
    const media = await wpRequest(`wp/v2/media/${canonical.featured_media}`, { context: auth ? "edit" : "view" });
    const prefix = `featured-${media.data.id}`;
    const relative = mirroredMediaRelative(media.data.source_url, prefix);
    featuredImageUrl = media.data.source_url;
    featuredImagePath = relative ? path.join(process.cwd(), "public", relative) : null;
    featuredImage = {
      id: media.data.id,
      src: await mirrorUrl(media.data.source_url, prefix),
      alt: media.data.alt_text || "",
      caption: renderedField(media.data.caption),
      width: media.data.media_details?.width || null,
      height: media.data.media_details?.height || null,
    };
  }
  const socialImage = postType === "post" ? await generateSocialCard({
    publicDir: path.join(process.cwd(), "public"),
    distDir: path.join(process.cwd(), "dist"),
    fileName: `post-${canonical.id}-${digest.slice(0, 12)}-${createHash("sha256").update(JSON.stringify([config.siteName, config.design])).digest("hex").slice(0, 8)}.png`,
    releasePrefix,
    design: config.design,
    siteName: config.siteName,
    displayHost: new URL(config.publicUrl).hostname,
    title: plainText(canonical.title),
    category: categoryTerms[0]?.name || "",
    date: post.date,
    featuredImagePath,
    featuredImageUrl: snapshot ? null : featuredImageUrl,
  }) : null;
  return {
    ...canonical,
    title: plainText(renderedField(post.title)),
    excerpt: renderedField(post.excerpt),
    status: post.status,
    date: post.date,
    modified: post.modified,
    link: post.link,
    content: await mirrorHtml(renderedField(post.content), `${postType}-${post.id}`),
    digest,
    featuredImage,
    socialImage,
    socialImageAlt: socialImage ? `${plainText(canonical.title)} — ${config.siteName}` : "",
    categoryTerms,
    tagTerms,
    url: postType === "post" ? `/${config.postsPath}/${canonical.slug}/` : `/${canonical.slug}/`,
  };
}

function applyPagePaths(pages) {
  const pageById = new Map(pages.map((page) => [page.id, page]));
  for (const id of [config.homePageId, config.postsPageId]) if (id && !pageById.has(Number(id))) throw new Error("Configured front/posts page is unavailable");
  for (const page of pages) {
    const slugs = [page.slug];
    const seen = new Set([page.id]);
    let parent = pageById.get(page.parent);
    if (page.parent && !parent) throw new Error(`Page ${page.id} has an unavailable parent`);
    while (parent) {
      if (seen.has(parent.id)) throw new Error("Page hierarchy cycle");
      seen.add(parent.id);
      slugs.unshift(parent.slug);
      parent = pageById.get(parent.parent);
    }
    page.path = slugs.filter(Boolean).join("/");
    page.isHome = page.id === Number(config.homePageId || 0);
    page.isPostsIndex = page.id === Number(config.postsPageId || 0);
    page.url = page.isHome ? "/" : page.isPostsIndex ? `/${config.postsPath}/` : `/${page.path}/`;
  }
  const reserved = new Set([config.postsPath, config.topicsPath, config.tagsPath, "search", "404", "rss.xml", "sitemap.xml", "robots.txt", "_astro", "_dashless", "media", "dashless-publication.json"]);
  const conflict = pages.find((page) => !page.isHome && !page.isPostsIndex && reserved.has(page.path.split("/")[0]));
  if (conflict) throw new Error(`WordPress Page ${conflict.id} (${conflict.title}) conflicts with the reserved /${conflict.path.split("/")[0]}/ route. Change that Page slug or the Dashless archive path.`);
  if (new Set(pages.map(page => page.url)).size !== pages.length) throw new Error("Duplicate page route");
  return pages.sort((a, b) => a.menu_order - b.menu_order || a.title.localeCompare(b.title));
}

async function getContent(postType) {
  const endpoint = postType === "post" ? "posts" : "pages";
  const items = await listAll(endpoint, postType === "page" ? { orderby: "menu_order", order: "asc" } : {});
  const preview = await loadPreviewPayload();
  if (preview?.post_type === postType) {
    const index = items.findIndex((item) => item.id === preview.id);
    const previewItem = { ...preview, status: "draft", date: preview.date || new Date().toISOString() };
    if (index >= 0) items[index] = previewItem;
    else items.unshift(previewItem);
  }
  let termMaps = {};
  if (postType === "post") {
    const [categories, tags] = await Promise.all([getCategories(), getTags()]);
    termMaps = { categories: new Map(categories.map((term) => [term.id, term])), tags: new Map(tags.map((term) => [term.id, term])) };
  }
  const normalized = [];
  for (const item of items) normalized.push(await normalize(item, postType, termMaps));
  if (new Set(normalized.map(item => item.slug)).size !== normalized.length && postType === "post") throw new Error("Duplicate post route");
  return postType === "page" ? applyPagePaths(normalized) : normalized;
}

export function plainText(html) {
  return String(html || "")
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/\s+/g, " ")
    .trim();
}

// Format publication dates in UTC so a build is reproducible regardless of
// the machine's local timezone (and so a date cannot shift at midnight).
export function formatDate(value, options = {}) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return new Intl.DateTimeFormat("en-US", { timeZone: "UTC", ...options }).format(date);
}

export function archivePages(items, perPage = config.postsPerPage) {
  const size = Math.min(Math.max(Number(perPage) || 12, 1), 50);
  const pages = [];
  for (let offset = 0; offset < items.length; offset += size) pages.push(items.slice(offset, offset + size));
  return pages.length ? pages : [[]];
}

export function searchIndex(posts, pages) {
  return [
    ...posts.map((post) => ({ type: "Story", title: post.title, url: post.url, text: plainText(`${post.excerpt} ${post.content}`).slice(0, 600) })),
    ...pages.map((page) => ({ type: "Page", title: page.title, url: page.url, text: plainText(`${page.excerpt} ${page.content}`).slice(0, 600) })),
  ];
}

const contentCache = new Map();
function getContentOnce(type) {
  if (!contentCache.has(type)) contentCache.set(type, getContent(type));
  return contentCache.get(type);
}
export const getPosts = () => getContentOnce("post");
export const getPages = () => getContentOnce("page");
export const getCategories = () => listTerms("categories", config.topicsPath);
export const getTags = () => listTerms("tags", config.tagsPath);

export async function getNavigation() {
  const pages = await getPages();
  return [
    { label: "Latest", url: "/" },
    { label: "Stories", url: `/${config.postsPath}/` },
    { label: "Topics", url: `/${config.topicsPath}/` },
    ...(config.navigation ? config.navigation.map(item => {
      const page = pages.find(page => page.id === item.page_id);
      if (!page) throw new Error("Navigation references a missing page");
      return { label: item.label, url: page.url };
    }) : pages.filter((page) => page.parent === 0 && !page.isHome && !page.isPostsIndex).slice(0, 5).map((page) => ({ label: page.title, url: page.url }))),
    { label: "Search", url: "/search/" },
  ];
}

export async function getWordPressSiteState() {
  const response = await optionalWpRequest("dashless/v1/site");
  return response?.data || null;
}
