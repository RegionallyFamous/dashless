import { archivePages, config, getCategories, getPages, getPosts, getTags } from "../lib/dashless.mjs";

export async function GET() {
  const [posts, pages, categories, tags] = await Promise.all([getPosts(), getPages(), getCategories(), getTags()]);
  const postPages = archivePages(posts);
  const urls = [...new Set([
    "/",
    `/${config.postsPath}/`,
    ...postPages.slice(1).map((_, index) => `/${config.postsPath}/page/${index + 2}/`),
    `/${config.topicsPath}/`,
    `/${config.tagsPath}/`,
    "/search/",
    ...posts.map((post) => post.url),
    ...pages.map((page) => page.url),
    ...categories.map((term) => term.url),
    ...tags.map((term) => term.url),
  ])];
  const body = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${urls.map((url) => `<url><loc>${new URL(url, config.publicUrl)}</loc></url>`).join("")}</urlset>`;
  return new Response(body, { headers: { "Content-Type": "application/xml; charset=utf-8" } });
}
