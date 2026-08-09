import { readFile, stat } from "node:fs/promises";
import path from "node:path";
import { canonicalPost, contentDigest } from "./digest.mjs";
import { DashlessError, requireInteger, requireString } from "./errors.mjs";

const MIME_TYPES = new Map([
  [".avif", "image/avif"],
  [".gif", "image/gif"],
  [".jpeg", "image/jpeg"],
  [".jpg", "image/jpeg"],
  [".png", "image/png"],
  [".svg", "image/svg+xml"],
  [".webp", "image/webp"],
  [".pdf", "application/pdf"],
  [".mp3", "audio/mpeg"],
  [".mp4", "video/mp4"],
]);

function endpointFor(postType) {
  if (postType === "post") return "posts";
  if (postType === "page") return "pages";
  throw new DashlessError("unsupported_post_type", "Dashless supports the core post and page types.");
}

function rawText(value) {
  if (typeof value === "string") return value;
  return value?.raw ?? value?.rendered ?? "";
}

function cleanPost(post, postType) {
  const canonical = canonicalPost(post, postType);
  return {
    ...canonical,
    status: post.status,
    date: post.date,
    date_gmt: post.date_gmt,
    modified: post.modified,
    modified_gmt: post.modified_gmt,
    link: post.link,
    author: post.author,
    digest: contentDigest(post, postType),
    revision: post._links?.["version-history"]?.[0]?.count ?? null,
  };
}

function editBody(input, postType, { includeStatus = false } = {}) {
  const allowed = ["title", "content", "excerpt", "slug", "featured_media"];
  if (postType === "post") allowed.push("categories", "tags");
  if (postType === "page") allowed.push("parent", "menu_order");
  const body = {};
  for (const key of allowed) {
    if (input[key] !== undefined) body[key] = input[key];
  }
  if (includeStatus && input.status !== undefined) body.status = input.status;
  return body;
}

function mergeCanonical(current, changes, postType) {
  const merged = {
    id: current.id,
    slug: changes.slug ?? current.slug,
    title: changes.title ?? rawText(current.title),
    content: changes.content ?? rawText(current.content),
    excerpt: changes.excerpt ?? rawText(current.excerpt),
    featured_media: changes.featured_media ?? current.featured_media ?? 0,
    parent: postType === "page" ? changes.parent ?? current.parent ?? 0 : 0,
    menu_order: postType === "page" ? changes.menu_order ?? current.menu_order ?? 0 : 0,
    categories: postType === "post" ? changes.categories ?? current.categories ?? [] : [],
    tags: postType === "post" ? changes.tags ?? current.tags ?? [] : [],
  };
  return { ...merged, ...canonicalPost(merged, postType) };
}

function requestError(status, payload, url) {
  const wpCode = payload && typeof payload === "object" ? payload.code : null;
  const message = payload && typeof payload === "object" ? payload.message : null;
  return new DashlessError(
    wpCode || "wordpress_request_failed",
    message || `WordPress returned HTTP ${status}.`,
    { status, url, wordpress: payload },
  );
}

export class WordPressClient {
  constructor({ siteUrl, username, password, timeoutMs = 20_000 }) {
    this.siteUrl = siteUrl.replace(/\/$/, "");
    this.username = username;
    this.password = password;
    this.timeoutMs = timeoutMs;
  }

  authHeader() {
    return `Basic ${Buffer.from(`${this.username}:${this.password}`, "utf8").toString("base64")}`;
  }

  async request(route, { method = "GET", query, body, headers = {}, rawBody = false } = {}) {
    const url = new URL(route.startsWith("http") ? route : `${this.siteUrl}/wp-json/${route.replace(/^\//, "")}`);
    for (const [key, value] of Object.entries(query || {})) {
      if (value !== undefined && value !== null && value !== "") {
        url.searchParams.set(key, Array.isArray(value) ? value.join(",") : String(value));
      }
    }
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.timeoutMs);
    const requestHeaders = { Authorization: this.authHeader(), Accept: "application/json", ...headers };
    let payload = body;
    if (body !== undefined && !rawBody) {
      requestHeaders["Content-Type"] ||= "application/json";
      payload = JSON.stringify(body);
    }
    let response;
    try {
      response = await fetch(url, { method, headers: requestHeaders, body: payload, signal: controller.signal });
    } catch (error) {
      if (error?.name === "AbortError") {
        throw new DashlessError("wordpress_timeout", `WordPress did not respond within ${this.timeoutMs / 1000} seconds.`);
      }
      throw new DashlessError("wordpress_unreachable", `Dashless could not reach WordPress: ${error.message}`);
    } finally {
      clearTimeout(timeout);
    }
    const text = await response.text();
    let parsed = null;
    try {
      parsed = text ? JSON.parse(text) : null;
    } catch {
      parsed = text;
    }
    if (!response.ok) throw requestError(response.status, parsed, url.toString());
    return { data: parsed, headers: response.headers, status: response.status };
  }

  async optionalRequest(route, options = {}) {
    try {
      return await this.request(route, options);
    } catch (error) {
      if (error?.details?.status === 404 || error?.details?.status === 403) return null;
      throw error;
    }
  }

  async inspectSite() {
    const root = await this.request(`${this.siteUrl}/wp-json/`);
    const me = await this.request("wp/v2/users/me", { query: { context: "edit" } });
    const [types, taxonomies, settings, dashless] = await Promise.all([
      this.request("wp/v2/types", { query: { context: "edit" } }),
      this.optionalRequest("wp/v2/taxonomies", { query: { context: "edit" } }),
      this.optionalRequest("wp/v2/settings"),
      this.optionalRequest("dashless/v1/site"),
    ]);
    const authentication = root.data?.authentication?.["application-passwords"] || {};
    const editableTypes = Object.entries(types.data || {})
      .filter(([, type]) => type?.rest_base && ["post", "page"].includes(type.slug))
      .map(([slug, type]) => ({ slug, name: type.name, rest_base: type.rest_base, hierarchical: Boolean(type.hierarchical) }));
    const editableTaxonomies = Object.entries(taxonomies?.data || {})
      .filter(([, taxonomy]) => ["category", "post_tag"].includes(taxonomy.slug))
      .map(([slug, taxonomy]) => ({ slug, name: taxonomy.name, rest_base: taxonomy.rest_base, hierarchical: Boolean(taxonomy.hierarchical) }));
    return {
      site_name: root.data?.name || new URL(this.siteUrl).hostname,
      description: root.data?.description || "",
      url: root.data?.url || this.siteUrl,
      user: { id: me.data.id, name: me.data.name, username: this.username },
      settings: settings ? {
        title: settings.data.title || root.data?.name || "",
        description: settings.data.description || root.data?.description || "",
        timezone: settings.data.timezone || "",
        date_format: settings.data.date_format || "",
        posts_per_page: Number(settings.data.posts_per_page || 10),
        show_on_front: settings.data.show_on_front || "posts",
        page_on_front: Number(settings.data.page_on_front || 0),
        page_for_posts: Number(settings.data.page_for_posts || 0),
      } : null,
      content_model: {
        post_types: editableTypes,
        taxonomies: editableTaxonomies,
      },
      dashless_plugin: dashless?.data || null,
      capabilities: {
        posts: Boolean(types.data?.post),
        pages: Boolean(types.data?.page),
        media: true,
        revisions: true,
        categories: true,
        tags: true,
        application_password_authorization: authentication.endpoints?.authorization || null,
        dashless_plugin: Boolean(dashless?.data?.plugin_version),
      },
    };
  }

  async revokeCurrentApplicationPassword() {
    const current = await this.request("wp/v2/users/me/application-passwords/introspect", { query: { context: "edit" } });
    const uuid = String(current.data?.uuid || "");
    if (!/^[0-9a-f-]{36}$/i.test(uuid)) {
      throw new DashlessError("application_password_introspection_failed", "WordPress did not identify the Application Password used by this connection.");
    }
    const deleted = await this.request(`wp/v2/users/me/application-passwords/${encodeURIComponent(uuid)}`, { method: "DELETE" });
    if (!deleted.data?.deleted) {
      throw new DashlessError("application_password_revocation_failed", "WordPress did not confirm that the Application Password was revoked.");
    }
    return { revoked: true };
  }

  async inspectContent() {
    const [inspection, posts, pages, categories, tags, media] = await Promise.all([
      this.inspectSite(),
      this.listPosts({ postType: "post", page: 1, perPage: 1 }),
      this.listPosts({ postType: "page", page: 1, perPage: 100 }),
      this.listTerms("category", { page: 1, perPage: 100 }),
      this.listTerms("tag", { page: 1, perPage: 100 }),
      this.listMedia({ page: 1, perPage: 1 }),
    ]);
    const pageById = new Map(pages.items.map((page) => [page.id, page]));
    const pagePath = (page) => {
      const parts = [page.slug];
      const seen = new Set([page.id]);
      let parent = pageById.get(page.parent);
      while (parent && !seen.has(parent.id)) {
        seen.add(parent.id);
        parts.unshift(parent.slug);
        parent = pageById.get(parent.parent);
      }
      return `/${parts.filter(Boolean).join("/")}/`;
    };
    return {
      ...inspection,
      counts: {
        posts: posts.total,
        pages: pages.total,
        categories: categories.length,
        tags: tags.length,
        media: media.total,
      },
      page_tree: pages.items
        .map((page) => ({ id: page.id, title: page.title, parent: page.parent, menu_order: page.menu_order, path: pagePath(page), status: page.status }))
        .sort((a, b) => a.path.localeCompare(b.path)),
      routes: {
        home: "/",
        posts: "/stories/",
        post: "/stories/[slug]/",
        pages: "/[...path]/",
        categories: "/topics/[slug]/",
        tags: "/tags/[slug]/",
        search: "/search/",
        feed: "/rss.xml",
        sitemap: "/sitemap.xml",
      },
      warnings: [
        ...(!inspection.capabilities.dashless_plugin ? [{ code: "wordpress_plugin_missing", message: "The Dashless WordPress companion is not installed yet; WP Cloud deployment will install it automatically." }] : []),
        ...(pages.total_pages > 1 ? [{ code: "page_inventory_truncated", message: "The site has more than 100 Pages; the compact page tree is truncated." }] : []),
      ],
    };
  }

  async listPosts({ postType = "post", status, search, page = 1, perPage = 20 } = {}) {
    const endpoint = endpointFor(postType);
    const response = await this.request(`wp/v2/${endpoint}`, {
      query: {
        context: "edit",
        status: status || "draft,pending,future,publish,private",
        search,
        page,
        per_page: Math.min(Math.max(perPage, 1), 100),
        orderby: "modified",
        order: "desc",
      },
    });
    return {
      items: response.data.map((post) => cleanPost(post, postType)),
      page,
      total: Number(response.headers.get("x-wp-total") || response.data.length),
      total_pages: Number(response.headers.get("x-wp-totalpages") || 1),
    };
  }

  async getPost(postType, id) {
    requireInteger(id, "id");
    const response = await this.request(`wp/v2/${endpointFor(postType)}/${id}`, {
      query: { context: "edit" },
    });
    return cleanPost(response.data, postType);
  }

  async pageRoute(page) {
    const slugs = [String(page.slug || "")];
    const seen = new Set([Number(page.id)]);
    let parentId = Number(page.parent || 0);
    while (parentId) {
      if (seen.has(parentId) || seen.size > 100) {
        throw new DashlessError("page_hierarchy_invalid", "The WordPress Page hierarchy contains a cycle or is too deep to publish safely.");
      }
      seen.add(parentId);
      const parent = await this.getPost("page", parentId);
      slugs.unshift(parent.slug);
      parentId = Number(parent.parent || 0);
    }
    return `/${slugs.filter(Boolean).join("/")}/`;
  }

  async createDraft(postType, input) {
    const body = editBody(input, postType);
    body.status = "draft";
    const response = await this.request(`wp/v2/${endpointFor(postType)}`, { method: "POST", body });
    return cleanPost(response.data, postType);
  }

  async updateDraft(postType, id, input, expectedModifiedGmt) {
    const currentResponse = await this.request(`wp/v2/${endpointFor(postType)}/${id}`, { query: { context: "edit" } });
    const current = currentResponse.data;
    if (current.modified_gmt !== expectedModifiedGmt) {
      throw new DashlessError("stale_post", "The WordPress item changed after it was read. Review the current version before updating.", {
        expected_modified_gmt: expectedModifiedGmt,
        current: cleanPost(current, postType),
      });
    }
    if (!new Set(["draft", "pending", "future"]).has(current.status)) {
      throw new DashlessError("published_post_requires_stage", "Published content must be revised with stage_update so the live parent is unchanged until approval.");
    }
    const response = await this.request(`wp/v2/${endpointFor(postType)}/${id}`, {
      method: "POST",
      body: editBody(input, postType),
    });
    return cleanPost(response.data, postType);
  }

  async applyPublishedPayload(postType, id, payload) {
    const body = editBody(payload, postType);
    body.status = "publish";
    const response = await this.request(`wp/v2/${endpointFor(postType)}/${id}`, { method: "POST", body });
    return cleanPost(response.data, postType);
  }

  async publishCurrent(postType, id) {
    const response = await this.request(`wp/v2/${endpointFor(postType)}/${id}`, {
      method: "POST",
      body: { status: "publish" },
    });
    return cleanPost(response.data, postType);
  }

  async listRevisions(postType, id, perPage = 20) {
    const response = await this.request(`wp/v2/${endpointFor(postType)}/${id}/revisions`, {
      query: { context: "edit", per_page: Math.min(Math.max(perPage, 1), 100), order: "desc", orderby: "date" },
    });
    return response.data.map((revision) => ({
      id: revision.id,
      parent: revision.parent,
      author: revision.author,
      date: revision.date,
      modified_gmt: revision.modified_gmt,
      title: rawText(revision.title),
      excerpt: rawText(revision.excerpt),
      digest: contentDigest({ ...revision, id, slug: "", featured_media: 0 }, postType),
    }));
  }

  async getRevision(postType, parentId, revisionId) {
    const response = await this.request(`wp/v2/${endpointFor(postType)}/${parentId}/revisions/${revisionId}`, {
      query: { context: "edit" },
    });
    return response.data;
  }

  async listTerms(taxonomy, { search, page = 1, perPage = 100 } = {}) {
    const endpoint = taxonomy === "category" ? "categories" : taxonomy === "tag" ? "tags" : null;
    if (!endpoint) throw new DashlessError("unsupported_taxonomy", "Dashless supports core categories and tags.");
    const response = await this.request(`wp/v2/${endpoint}`, {
      query: { context: "edit", hide_empty: false, search, page, per_page: Math.min(Math.max(perPage, 1), 100) },
    });
    return response.data.map(({ id, count, description, link, name, slug }) => ({ id, count, description, link, name, slug }));
  }

  async ensureTerms(taxonomy, names) {
    const endpoint = taxonomy === "category" ? "categories" : taxonomy === "tag" ? "tags" : null;
    if (!endpoint) throw new DashlessError("unsupported_taxonomy", "Dashless supports core categories and tags.");
    const results = [];
    for (const originalName of names) {
      const name = requireString(originalName, "term name").trim();
      const candidates = await this.listTerms(taxonomy, { search: name });
      const exact = candidates.find((term) => term.name.localeCompare(name, undefined, { sensitivity: "accent" }) === 0);
      if (exact) {
        results.push({ ...exact, created: false });
        continue;
      }
      try {
        const response = await this.request(`wp/v2/${endpoint}`, { method: "POST", body: { name } });
        results.push({ ...response.data, created: true });
      } catch (error) {
        const existingId = error?.details?.wordpress?.data?.term_id;
        if (!existingId) throw error;
        const response = await this.request(`wp/v2/${endpoint}/${existingId}`, { query: { context: "edit" } });
        results.push({ ...response.data, created: false });
      }
    }
    return results.map(({ id, name, slug, created }) => ({ id, name, slug, created }));
  }

  async uploadMedia(filePath, { altText, caption = "", title, description = "" } = {}) {
    const absolute = path.resolve(filePath);
    const info = await stat(absolute).catch(() => null);
    if (!info?.isFile()) throw new DashlessError("media_file_missing", `Media file does not exist: ${absolute}`);
    if (info.size > 50 * 1024 * 1024) throw new DashlessError("media_file_too_large", "Dashless limits an individual upload to 50 MB.");
    const extension = path.extname(absolute).toLowerCase();
    const contentType = MIME_TYPES.get(extension) || "application/octet-stream";
    const filename = path.basename(absolute).replace(/[\r\n"\\]/g, "-");
    const upload = await this.request("wp/v2/media", {
      method: "POST",
      body: await readFile(absolute),
      rawBody: true,
      headers: {
        "Content-Type": contentType,
        "Content-Disposition": `attachment; filename="${filename}"`,
      },
    });
    const metadata = {
      alt_text: altText ?? "",
      caption,
      title: title || path.basename(filename, extension),
      description,
    };
    const updated = await this.request(`wp/v2/media/${upload.data.id}`, { method: "POST", body: metadata });
    return {
      id: updated.data.id,
      date: updated.data.date,
      slug: updated.data.slug,
      status: updated.data.status,
      source_url: updated.data.source_url,
      mime_type: updated.data.mime_type,
      alt_text: updated.data.alt_text,
      caption: rawText(updated.data.caption),
      title: rawText(updated.data.title),
    };
  }

  async listMedia({ search, page = 1, perPage = 20, mediaType } = {}) {
    const response = await this.request("wp/v2/media", {
      query: {
        context: "edit",
        search,
        page,
        per_page: Math.min(Math.max(perPage, 1), 100),
        media_type: mediaType,
        orderby: "date",
        order: "desc",
      },
    });
    return {
      items: response.data.map((media) => ({
        id: media.id,
        date: media.date,
        slug: media.slug,
        status: media.status,
        source_url: media.source_url,
        mime_type: media.mime_type,
        media_type: media.media_type,
        alt_text: media.alt_text || "",
        caption: rawText(media.caption),
        title: rawText(media.title),
        description: rawText(media.description),
        width: media.media_details?.width || null,
        height: media.media_details?.height || null,
      })),
      page,
      total: Number(response.headers.get("x-wp-total") || response.data.length),
      total_pages: Number(response.headers.get("x-wp-totalpages") || 1),
    };
  }

  async updateMedia(id, { altText, caption, title, description } = {}) {
    requireInteger(id, "id");
    const body = {};
    if (altText !== undefined) body.alt_text = altText;
    if (caption !== undefined) body.caption = caption;
    if (title !== undefined) body.title = title;
    if (description !== undefined) body.description = description;
    const response = await this.request(`wp/v2/media/${id}`, { method: "POST", body });
    const media = response.data;
    return {
      id: media.id,
      source_url: media.source_url,
      mime_type: media.mime_type,
      alt_text: media.alt_text || "",
      caption: rawText(media.caption),
      title: rawText(media.title),
      description: rawText(media.description),
      width: media.media_details?.width || null,
      height: media.media_details?.height || null,
    };
  }
}

export { cleanPost, editBody, endpointFor, mergeCanonical };
