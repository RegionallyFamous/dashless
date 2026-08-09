import { createServer } from "node:http";

function bodyFrom(request) {
  return new Promise(async (resolve) => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    const body = Buffer.concat(chunks);
    if (request.headers["content-type"]?.includes("application/json")) resolve(JSON.parse(body.toString("utf8") || "{}"));
    else resolve(body);
  });
}

function textField(value) {
  if (typeof value === "string") return { raw: value, rendered: value };
  return value || { raw: "", rendered: "" };
}

function slugify(value) {
  return String(value).toLowerCase().replace(/<[^>]+>/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "untitled";
}

export async function createMockWordPress() {
  const state = {
    posts: new Map(),
    pages: new Map(),
    revisions: new Map(),
    categories: [{ id: 1, name: "News", slug: "news", count: 1, description: "", link: "" }],
    tags: [],
    media: new Map(),
    nextPost: 2,
    nextRevision: 100,
    nextTerm: 10,
    nextMedia: 50,
    clock: 0,
    applicationPasswordUuid: "11111111-2222-4333-8444-555555555555",
    applicationPasswordRevoked: false,
  };

  function timestamp() {
    state.clock += 1;
    return `2026-08-05T21:${String(state.clock).padStart(2, "0")}:00`;
  }

  function prepare(input, current = {}, type = "post") {
    const modified = timestamp();
    const title = textField(input.title ?? current.title);
    const content = textField(input.content ?? current.content);
    const excerpt = textField(input.excerpt ?? current.excerpt);
    const post = {
      id: current.id ?? state.nextPost++,
      slug: input.slug ?? current.slug ?? slugify(title.raw),
      title,
      content,
      excerpt,
      featured_media: input.featured_media ?? current.featured_media ?? 0,
      parent: type === "page" ? input.parent ?? current.parent ?? 0 : undefined,
      menu_order: type === "page" ? input.menu_order ?? current.menu_order ?? 0 : undefined,
      categories: type === "post" ? input.categories ?? current.categories ?? [] : undefined,
      tags: type === "post" ? input.tags ?? current.tags ?? [] : undefined,
      status: input.status ?? current.status ?? "draft",
      author: 1,
      date: current.date ?? modified,
      date_gmt: current.date_gmt ?? modified,
      modified,
      modified_gmt: modified,
      link: `${baseUrl}/${type === "post" ? "stories/" : ""}${input.slug ?? current.slug ?? slugify(title.raw)}/`,
      _links: { "version-history": [{ count: (state.revisions.get(`${type}:${current.id}`) || []).length }] },
    };
    if (post.categories === undefined) delete post.categories;
    if (post.tags === undefined) delete post.tags;
    if (post.parent === undefined) delete post.parent;
    if (post.menu_order === undefined) delete post.menu_order;
    return post;
  }

  function addRevision(type, post) {
    const key = `${type}:${post.id}`;
    const revisions = state.revisions.get(key) || [];
    revisions.unshift({ ...structuredClone(post), id: state.nextRevision++, parent: post.id });
    state.revisions.set(key, revisions);
  }

  let baseUrl = "";
  const server = createServer(async (request, response) => {
    response.setHeader("Content-Type", "application/json");
    const url = new URL(request.url, baseUrl);
    if (url.pathname.startsWith("/wp-content/uploads/")) {
      response.setHeader("Content-Type", "image/png");
      response.end(Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64"));
      return;
    }
    if (state.applicationPasswordRevoked || request.headers.authorization !== `Basic ${Buffer.from("editor:app-password").toString("base64")}`) {
      response.statusCode = 401;
      response.end(JSON.stringify({ code: "rest_not_logged_in", message: "Authentication required." }));
      return;
    }
    const pathname = url.pathname.replace(/\/$/, "") || "/";

    if (pathname === "/wp-json") {
      response.end(JSON.stringify({
        name: "Mock Gazette",
        description: "Mock publication",
        url: baseUrl,
        authentication: { "application-passwords": { endpoints: { authorization: `${baseUrl}/wp-admin/authorize-application.php` } } },
      }));
      return;
    }
    if (pathname === "/wp-json/wp/v2/users/me") {
      response.end(JSON.stringify({ id: 1, name: "Editor", slug: "editor" }));
      return;
    }
    if (pathname === "/wp-json/wp/v2/users/me/application-passwords/introspect") {
      response.end(JSON.stringify({ uuid: state.applicationPasswordUuid, name: "Dashless" }));
      return;
    }
    if (pathname === `/wp-json/wp/v2/users/me/application-passwords/${state.applicationPasswordUuid}` && request.method === "DELETE") {
      state.applicationPasswordRevoked = true;
      response.end(JSON.stringify({ deleted: true }));
      return;
    }
    if (pathname === "/wp-json/wp/v2/types") {
      response.end(JSON.stringify({ post: { slug: "post", name: "Posts", rest_base: "posts", hierarchical: false }, page: { slug: "page", name: "Pages", rest_base: "pages", hierarchical: true } }));
      return;
    }
    if (pathname === "/wp-json/wp/v2/taxonomies") {
      response.end(JSON.stringify({ category: { slug: "category", name: "Categories", rest_base: "categories", hierarchical: true }, post_tag: { slug: "post_tag", name: "Tags", rest_base: "tags", hierarchical: false } }));
      return;
    }
    if (pathname === "/wp-json/wp/v2/settings") {
      response.end(JSON.stringify({ title: "Mock Gazette", description: "Mock publication", timezone: "America/Chicago", date_format: "F j, Y", posts_per_page: 10, show_on_front: "posts", page_on_front: 0, page_for_posts: 0 }));
      return;
    }
    if (pathname === "/wp-json/dashless/v1/site") {
      response.end(JSON.stringify({ plugin_version: "1.0.0", content_version: { generation: state.clock, changed_at_gmt: null, object_id: null } }));
      return;
    }

    const collectionMatch = pathname.match(/^\/wp-json\/wp\/v2\/(posts|pages)$/);
    if (collectionMatch) {
      const type = collectionMatch[1] === "posts" ? "post" : "page";
      const collection = type === "post" ? state.posts : state.pages;
      if (request.method === "POST") {
        const input = await bodyFrom(request);
        const post = prepare(input, {}, type);
        collection.set(post.id, post);
        response.statusCode = 201;
        response.end(JSON.stringify(post));
        return;
      }
      let items = [...collection.values()];
      const statuses = new Set((url.searchParams.get("status") || "publish").split(","));
      items = items.filter((item) => statuses.has(item.status));
      const search = url.searchParams.get("search")?.toLowerCase();
      if (search) items = items.filter((item) => item.title.raw.toLowerCase().includes(search));
      response.setHeader("X-WP-Total", String(items.length));
      response.setHeader("X-WP-TotalPages", "1");
      response.end(JSON.stringify(items));
      return;
    }

    const revisionMatch = pathname.match(/^\/wp-json\/wp\/v2\/(posts|pages)\/(\d+)\/revisions(?:\/(\d+))?$/);
    if (revisionMatch) {
      const type = revisionMatch[1] === "posts" ? "post" : "page";
      const revisions = state.revisions.get(`${type}:${Number(revisionMatch[2])}`) || [];
      if (revisionMatch[3]) {
        const revision = revisions.find((item) => item.id === Number(revisionMatch[3]));
        if (!revision) { response.statusCode = 404; response.end(JSON.stringify({ code: "rest_post_invalid_id", message: "Invalid revision." })); return; }
        response.end(JSON.stringify(revision));
      } else response.end(JSON.stringify(revisions));
      return;
    }

    const itemMatch = pathname.match(/^\/wp-json\/wp\/v2\/(posts|pages)\/(\d+)$/);
    if (itemMatch) {
      const type = itemMatch[1] === "posts" ? "post" : "page";
      const collection = type === "post" ? state.posts : state.pages;
      const id = Number(itemMatch[2]);
      const current = collection.get(id);
      if (!current) { response.statusCode = 404; response.end(JSON.stringify({ code: "rest_post_invalid_id", message: "Invalid post." })); return; }
      if (request.method === "POST") {
        addRevision(type, current);
        const updated = prepare(await bodyFrom(request), current, type);
        collection.set(id, updated);
        response.end(JSON.stringify(updated));
      } else response.end(JSON.stringify(current));
      return;
    }

    const termCollection = pathname.match(/^\/wp-json\/wp\/v2\/(categories|tags)$/);
    if (termCollection) {
      const terms = termCollection[1] === "categories" ? state.categories : state.tags;
      if (request.method === "POST") {
        const { name } = await bodyFrom(request);
        const existing = terms.find((term) => term.name.toLowerCase() === name.toLowerCase());
        if (existing) {
          response.statusCode = 400;
          response.end(JSON.stringify({ code: "term_exists", message: "A term with the name provided already exists.", data: { term_id: existing.id } }));
          return;
        }
        const term = { id: state.nextTerm++, name, slug: slugify(name), count: 0, description: "", link: "" };
        terms.push(term);
        response.statusCode = 201;
        response.end(JSON.stringify(term));
      } else {
        const search = url.searchParams.get("search")?.toLowerCase();
        const found = search ? terms.filter((term) => term.name.toLowerCase().includes(search)) : terms;
        response.setHeader("X-WP-Total", String(found.length));
        response.setHeader("X-WP-TotalPages", "1");
        response.end(JSON.stringify(found));
      }
      return;
    }

    const mediaCollection = pathname === "/wp-json/wp/v2/media";
    if (mediaCollection) {
      if (request.method === "POST") {
        await bodyFrom(request);
        const id = state.nextMedia++;
        const media = { id, date: timestamp(), slug: `media-${id}`, status: "inherit", source_url: `${baseUrl}/wp-content/uploads/media-${id}.png`, mime_type: request.headers["content-type"], media_type: "image", alt_text: "", caption: textField(""), title: textField(`media-${id}`), description: textField(""), media_details: { width: 10, height: 10 } };
        state.media.set(id, media);
        response.statusCode = 201;
        response.end(JSON.stringify(media));
      } else {
        let items = [...state.media.values()];
        const search = url.searchParams.get("search")?.toLowerCase();
        if (search) items = items.filter((item) => item.title.raw.toLowerCase().includes(search));
        response.setHeader("X-WP-Total", String(items.length));
        response.setHeader("X-WP-TotalPages", "1");
        response.end(JSON.stringify(items));
      }
      return;
    }
    const mediaItem = pathname.match(/^\/wp-json\/wp\/v2\/media\/(\d+)$/);
    if (mediaItem) {
      const id = Number(mediaItem[1]);
      const current = state.media.get(id);
      if (!current) { response.statusCode = 404; response.end(JSON.stringify({ code: "rest_post_invalid_id", message: "Invalid media." })); return; }
      if (request.method === "POST") {
        const input = await bodyFrom(request);
        const updated = { ...current, ...input, caption: textField(input.caption ?? current.caption), title: textField(input.title ?? current.title) };
        state.media.set(id, updated);
        response.end(JSON.stringify(updated));
      } else response.end(JSON.stringify(current));
      return;
    }

    response.statusCode = 404;
    response.end(JSON.stringify({ code: "rest_no_route", message: "No route." }));
  });

  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  baseUrl = `http://127.0.0.1:${server.address().port}`;
  state.media.set(49, { id: 49, date: timestamp(), slug: "newsroom", status: "inherit", source_url: `${baseUrl}/wp-content/uploads/hero.png`, mime_type: "image/png", media_type: "image", alt_text: "A test newsroom", caption: textField("Test image"), title: textField("Newsroom"), description: textField(""), media_details: { width: 1200, height: 800 } });
  const initial = prepare({ title: "A Published Story", content: `<p>Original body.</p><img src="${baseUrl}/wp-content/uploads/inline.png" alt="Inline test">`, excerpt: "Original excerpt.", slug: "published-story", status: "publish", featured_media: 49, categories: [1], tags: [] }, { id: 1 }, "post");
  state.posts.set(1, initial);
  state.pages.set(1, prepare({ title: "About", content: "<p>About this publication.</p>", slug: "about", status: "publish" }, { id: 1 }, "page"));

  return {
    url: baseUrl,
    state,
    close: () => new Promise((resolve) => server.close(resolve)),
  };
}
