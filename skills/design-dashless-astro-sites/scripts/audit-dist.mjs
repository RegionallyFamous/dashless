#!/usr/bin/env node

import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const scriptPath = fileURLToPath(import.meta.url);

function walkFiles(root) {
  if (!existsSync(root)) return [];
  const files = [];
  for (const entry of readdirSync(root, { withFileTypes: true })) {
    if (new Set(["node_modules", ".git", ".astro"]).has(entry.name)) continue;
    const absolute = path.join(root, entry.name);
    if (entry.isDirectory()) files.push(...walkFiles(absolute));
    else if (entry.isFile()) files.push(absolute);
  }
  return files;
}

function tags(html, name) {
  return html.match(new RegExp(`<${name}\\b[^>]*>`, "gi")) || [];
}

function pairedTags(html, name) {
  return [...html.matchAll(new RegExp(`<${name}\\b([^>]*)>([\\s\\S]*?)<\\/${name}>`, "gi"))];
}

function attribute(tag, name) {
  const match = tag.match(new RegExp(`(?:^|\\s)${name.replace(/[.*+?^${}()|[\\]\\]/g, "\\$&")}\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s>]+))`, "i"));
  return match ? (match[1] ?? match[2] ?? match[3] ?? "") : null;
}

function stripMarkup(value) {
  return String(value || "")
    .replace(/<script\b[\s\S]*?<\/script>/gi, " ")
    .replace(/<style\b[\s\S]*?<\/style>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;|&#160;/gi, " ")
    .replace(/&amp;/gi, "&")
    .replace(/&lt;/gi, "<")
    .replace(/&gt;/gi, ">")
    .replace(/&quot;|&#34;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'")
    .replace(/\s+/g, " ")
    .trim();
}

function routeFor(file, distPath) {
  const relative = path.relative(distPath, file).split(path.sep).join("/");
  if (relative === "index.html") return "/";
  if (relative.endsWith("/index.html")) return `/${relative.slice(0, -"index.html".length)}`;
  return `/${relative}`;
}

function targetForPathname(pathname, distPath) {
  let decoded;
  try { decoded = decodeURIComponent(pathname); } catch { return null; }
  const relative = decoded.replace(/^\/+/, "");
  if (!relative) return path.join(distPath, "index.html");
  if (path.extname(relative)) return path.join(distPath, relative);
  return path.join(distPath, relative, "index.html");
}

function hasMeta(html, key, value) {
  return tags(html, "meta").some((tag) => attribute(tag, key)?.toLowerCase() === value.toLowerCase());
}

function findMetaContent(html, key, value) {
  const tag = tags(html, "meta").find((candidate) => attribute(candidate, key)?.toLowerCase() === value.toLowerCase());
  return tag ? attribute(tag, "content") : null;
}

function findLink(html, rel) {
  return tags(html, "link").find((tag) => (attribute(tag, "rel") || "").toLowerCase().split(/\s+/).includes(rel));
}

function issue(level, code, message, file = null, route = null) {
  return { level, code, message, ...(file ? { file } : {}), ...(route ? { route } : {}) };
}

export function auditSite({ projectPath = process.cwd(), distPath = null, production = false } = {}) {
  const project = path.resolve(projectPath);
  const dist = path.resolve(distPath || path.join(project, "dist"));
  const errors = [];
  const warnings = [];
  const addError = (code, message, file, route) => errors.push(issue("error", code, message, file, route));
  const addWarning = (code, message, file, route) => warnings.push(issue("warning", code, message, file, route));

  const packagePath = path.join(project, "package.json");
  if (!existsSync(packagePath)) addError("project-package-missing", "package.json was not found.", packagePath);
  else {
    try {
      const packageJson = JSON.parse(readFileSync(packagePath, "utf8"));
      const dependencies = { ...packageJson.dependencies, ...packageJson.devDependencies };
      if (!dependencies.astro) addError("astro-dependency-missing", "The project does not declare Astro as a dependency.", packagePath);
      const buildScript = String(packageJson.scripts?.build || "");
      if (!buildScript.includes("astro build")) addError("build-script-missing", "The build script does not run astro build.", packagePath);
      if (!buildScript.includes("astro check")) addWarning("typecheck-not-in-build", "The build script does not run astro check before building.", packagePath);
    } catch (error) {
      addError("project-package-invalid", `package.json could not be parsed: ${error.message}`, packagePath);
    }
  }

  if (!existsSync(dist) || !statSync(dist).isDirectory()) {
    addError("dist-missing", "The production dist directory does not exist. Build the Astro project first.", dist);
    return { projectPath: project, distPath: dist, filesAudited: 0, errors, warnings };
  }

  const htmlFiles = walkFiles(dist).filter((file) => file.endsWith(".html"));
  if (!htmlFiles.length) addError("html-missing", "No generated HTML files were found in dist.", dist);

  const titles = new Map();
  const canonicals = new Map();
  const internalLinks = [];

  for (const file of htmlFiles) {
    const html = readFileSync(file, "utf8");
    const route = routeFor(file, dist);
    const relative = path.relative(project, file);

    if (!/^\s*<!doctype html>/i.test(html)) addError("doctype-missing", "The document is missing an HTML doctype.", relative, route);
    const htmlTag = tags(html, "html")[0];
    if (!htmlTag || !(attribute(htmlTag, "lang") || "").trim()) addError("language-missing", "The html element needs a non-empty lang attribute.", relative, route);
    if (!hasMeta(html, "charset", "utf-8") && !/<meta\b[^>]*charset\s*=\s*["']?utf-8/i.test(html)) addError("charset-missing", "UTF-8 charset metadata is missing.", relative, route);
    if (!hasMeta(html, "name", "viewport")) addError("viewport-missing", "Responsive viewport metadata is missing.", relative, route);

    const titleMatch = html.match(/<title\b[^>]*>([\s\S]*?)<\/title>/i);
    const title = stripMarkup(titleMatch?.[1]);
    if (!title) addError("title-missing", "The page title is empty or missing.", relative, route);
    else {
      if (!titles.has(title)) titles.set(title, []);
      titles.get(title).push(route);
    }
    const description = findMetaContent(html, "name", "description");
    if (!description?.trim()) addError("description-missing", "The meta description is empty or missing.", relative, route);

    const canonicalTag = findLink(html, "canonical");
    const canonical = canonicalTag ? attribute(canonicalTag, "href") : null;
    if (!canonical) addError("canonical-missing", "The canonical link is missing.", relative, route);
    else {
      try {
        const parsed = new URL(canonical);
        const local = ["localhost", "127.0.0.1", "::1", "[::1]"].includes(parsed.hostname);
        if (parsed.protocol !== "https:" && !(local && parsed.protocol === "http:")) addWarning("canonical-not-https", "The canonical URL is not HTTPS.", relative, route);
        if (parsed.search || parsed.hash) addError("canonical-unstable", "The canonical URL must not contain a query or fragment.", relative, route);
      } catch {
        addError("canonical-invalid", "The canonical URL is not absolute and valid.", relative, route);
      }
      if (!canonicals.has(canonical)) canonicals.set(canonical, []);
      canonicals.get(canonical).push(route);
    }

    for (const [property, code] of [["og:title", "og-title-missing"], ["og:description", "og-description-missing"], ["og:url", "og-url-missing"]]) {
      if (!findMetaContent(html, "property", property)) addWarning(code, `${property} metadata is missing.`, relative, route);
    }
    const ogType = findMetaContent(html, "property", "og:type");
    const ogImage = findMetaContent(html, "property", "og:image");
    if (ogType?.toLowerCase() === "article" && !ogImage) addWarning("article-social-image-missing", "Article pages should provide a share image.", relative, route);
    if (ogImage && !findMetaContent(html, "property", "og:image:alt")) addWarning("og-image-alt-missing", "The Open Graph image needs alternative text.", relative, route);
    const twitterCard = findMetaContent(html, "name", "twitter:card");
    const twitterImage = findMetaContent(html, "name", "twitter:image");
    if (!twitterCard) addWarning("twitter-card-missing", "Twitter card metadata is missing.", relative, route);
    if (twitterCard?.toLowerCase() === "summary_large_image" && !twitterImage) addWarning("twitter-image-missing", "A large Twitter card needs an image.", relative, route);
    if (twitterImage && !findMetaContent(html, "name", "twitter:image:alt")) addWarning("twitter-image-alt-missing", "The Twitter image needs alternative text.", relative, route);
    if (!findLink(html, "sitemap")) addWarning("sitemap-discovery-missing", "The page does not link to its sitemap.", relative, route);
    const rssLink = tags(html, "link").some((tag) => attribute(tag, "type")?.toLowerCase() === "application/rss+xml");
    if (!rssLink) addWarning("rss-discovery-missing", "The page does not advertise an RSS feed.", relative, route);

    const h1Count = (html.match(/<h1\b/gi) || []).length;
    if (h1Count !== 1) addError("page-heading-count", `Expected exactly one h1; found ${h1Count}.`, relative, route);
    const mainCount = (html.match(/<main\b/gi) || []).length;
    if (mainCount !== 1) addError("main-count", `Expected exactly one main landmark; found ${mainCount}.`, relative, route);
    if (!/<a\b[^>]*href\s*=\s*["']#main-content["']/i.test(html)) addWarning("skip-link-missing", "A skip link to #main-content was not found.", relative, route);

    const ids = tags(html, "[a-z][a-z0-9:-]*").flatMap((tag) => {
      const id = attribute(tag, "id");
      return id ? [id] : [];
    });
    const duplicateIds = [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))];
    if (duplicateIds.length) addError("duplicate-id", `Duplicate id values: ${duplicateIds.join(", ")}.`, relative, route);

    const images = tags(html, "img");
    const missingAlt = images.filter((tag) => attribute(tag, "alt") === null).length;
    if (missingAlt) addError("image-alt-missing", `${missingAlt} image(s) are missing an alt attribute.`, relative, route);
    const missingDimensions = images.filter((tag) => attribute(tag, "width") === null || attribute(tag, "height") === null).length;
    if (missingDimensions) addWarning("image-dimensions-missing", `${missingDimensions} image(s) do not declare both width and height. Reserve stable layout space another way.`, relative, route);

    if (/<[^>]+\son[a-z]+\s*=/i.test(html)) addError("inline-event-handler", "Inline event handler attributes are not allowed.", relative, route);
    if (/\b(?:href|src)\s*=\s*["']javascript:/i.test(html)) addError("javascript-url", "javascript: URLs are not allowed.", relative, route);

    for (const match of pairedTags(html, "button")) {
      const openTag = match[0].slice(0, match[0].indexOf(">") + 1);
      if (!stripMarkup(match[2]) && !attribute(openTag, "aria-label") && !attribute(openTag, "aria-labelledby")) {
        addError("button-name-missing", "A button has no accessible name.", relative, route);
      }
    }

    const labels = tags(html, "label").map((tag) => attribute(tag, "for")).filter(Boolean);
    for (const input of tags(html, "input")) {
      const type = (attribute(input, "type") || "text").toLowerCase();
      if (new Set(["hidden", "submit", "button", "reset", "image"]).has(type)) continue;
      const id = attribute(input, "id");
      if (!attribute(input, "aria-label") && !attribute(input, "aria-labelledby") && (!id || !labels.includes(id))) {
        addError("input-label-missing", "A form input has no programmatic label.", relative, route);
      }
    }

    for (const anchor of tags(html, "a")) {
      const href = attribute(anchor, "href");
      if (href) internalLinks.push({ href, route, file: relative });
      if (attribute(anchor, "target") === "_blank" && !(attribute(anchor, "rel") || "").toLowerCase().split(/\s+/).includes("noopener")) {
        addWarning("noopener-missing", "A target=_blank link does not include rel=noopener.", relative, route);
      }
    }

    const canonicalOrigin = (() => { try { return canonical ? new URL(canonical).origin : null; } catch { return null; } })();
    if (canonicalOrigin?.startsWith("https://") && /<(?:img|script|iframe|audio|video|source|link)\b[^>]*(?:src|href)\s*=\s*["']http:\/\//i.test(html)) {
      addError("mixed-content", "An HTTPS page references an insecure HTTP asset or link.", relative, route);
    }

    if ((route === "/404.html" || route.startsWith("/search/")) && !/name\s*=\s*["']robots["'][^>]*content\s*=\s*["'][^"']*noindex/i.test(html)) {
      addWarning("noindex-missing", "Search and 404 pages should normally be noindex,follow.", relative, route);
    }
  }

  for (const [title, routes] of titles) {
    if (routes.length > 1) addWarning("title-duplicate", `The title “${title}” is shared by: ${routes.join(", ")}.`);
  }
  for (const [canonical, routes] of canonicals) {
    if (routes.length > 1) addError("canonical-duplicate", `The canonical ${canonical} is shared by: ${routes.join(", ")}.`);
  }

  const seenLinks = new Set();
  for (const link of internalLinks) {
    const key = `${link.route}|${link.href}`;
    if (seenLinks.has(key)) continue;
    seenLinks.add(key);
    if (/^(?:https?:|mailto:|tel:|data:)/i.test(link.href) || link.href.startsWith("#")) continue;
    let resolved;
    try { resolved = new URL(link.href, `https://audit.invalid${link.route}`); } catch {
      addError("link-invalid", `The link target is invalid: ${link.href}`, link.file, link.route);
      continue;
    }
    if (resolved.origin !== "https://audit.invalid") continue;
    const target = targetForPathname(resolved.pathname, dist);
    if (!target || !existsSync(target)) addError("internal-link-broken", `Internal link does not resolve in dist: ${link.href}`, link.file, link.route);
  }

  for (const required of ["index.html", "404.html", "robots.txt", "rss.xml", "sitemap.xml", "favicon.svg"]) {
    if (!existsSync(path.join(dist, required))) addError("discovery-file-missing", `${required} is missing from the production build.`, path.join("dist", required));
  }

  if (production) {
    const textAssets = walkFiles(dist).filter((file) => /\.(?:html?|xml|txt|json|js|css)$/i.test(file));
    const previewOrigin = /https?:\/\/(?:localhost|127(?:\.\d{1,3}){3}|\[?::1\]?)(?::\d+)?(?:[\/?#]|$)/i;
    for (const file of textAssets) {
      if (previewOrigin.test(readFileSync(file, "utf8"))) {
        addError(
          "preview-origin-in-production",
          "A production asset contains a loopback preview origin. Configure the public URL and rebuild before release.",
          path.relative(project, file),
          file.endsWith(".html") ? routeFor(file, dist) : null,
        );
      }
    }
  }

  const cssFiles = walkFiles(path.join(project, "src")).filter((file) => file.endsWith(".css"));
  const css = cssFiles.map((file) => readFileSync(file, "utf8")).join("\n");
  if (cssFiles.length) {
    if (!css.includes(":focus-visible")) addWarning("focus-style-missing", "Source CSS has no :focus-visible rule.");
    if (!css.includes("prefers-reduced-motion")) addWarning("reduced-motion-missing", "Source CSS does not respond to prefers-reduced-motion.");
    if (!/@media\s*\([^)]*(?:max-width|min-width)/i.test(css)) addWarning("responsive-css-missing", "Source CSS has no width-based responsive media query.");
    if (!css.includes("forced-colors")) addWarning("forced-colors-missing", "Source CSS has no forced-colors treatment.");
  }

  for (const asset of walkFiles(dist)) {
    const size = statSync(asset).size;
    if (asset.endsWith(".js") && size > 250_000) addWarning("javascript-large", `${path.relative(dist, asset)} is ${(size / 1024).toFixed(1)} KiB.`);
    if (asset.endsWith(".css") && size > 150_000) addWarning("css-large", `${path.relative(dist, asset)} is ${(size / 1024).toFixed(1)} KiB.`);
  }

  return { projectPath: project, distPath: dist, production, filesAudited: htmlFiles.length, errors, warnings };
}

function argumentValue(name) {
  const index = process.argv.indexOf(name);
  return index >= 0 ? process.argv[index + 1] : null;
}

function printHuman(result) {
  for (const item of [...result.errors, ...result.warnings]) {
    const location = item.route || item.file ? ` (${[item.route, item.file].filter(Boolean).join(" · ")})` : "";
    process.stdout.write(`${item.level.toUpperCase()} ${item.code}${location}: ${item.message}\n`);
  }
  process.stdout.write(`Audited ${result.filesAudited} HTML file(s): ${result.errors.length} error(s), ${result.warnings.length} warning(s).\n`);
}

if (process.argv[1] && path.resolve(process.argv[1]) === scriptPath) {
  const projectPath = argumentValue("--project") || process.cwd();
  const distPath = argumentValue("--dist");
  const strict = process.argv.includes("--strict");
  const production = process.argv.includes("--production");
  const json = process.argv.includes("--json");
  const result = auditSite({ projectPath, distPath, production });
  if (json) process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  else printHuman(result);
  if (result.errors.length || (strict && result.warnings.length)) process.exitCode = 1;
}
