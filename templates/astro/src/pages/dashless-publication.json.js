import { archivePages, config, getPosts, getPages, getCategories, getTags } from '../lib/dashless.mjs';
export async function GET() {
  const [posts, pages, categories, tags] = await Promise.all([getPosts(), getPages(), getCategories(), getTags()]);
  const routes = [...new Set(['/', `/${config.postsPath}/`, `/${config.topicsPath}/`, `/${config.tagsPath}/`, '/search/',
    ...posts.map(p => p.url), ...pages.map(p => p.url), ...categories.map(p => p.url), ...tags.map(p => p.url),
    ...archivePages(posts).slice(1).map((_, i) => `/${config.postsPath}/page/${i+2}/`)])];
  return Response.json({ version: 1, routes, posts: posts.map(p => ({url:p.url, socialImage:p.socialImage})), pageCount:pages.length });
}
