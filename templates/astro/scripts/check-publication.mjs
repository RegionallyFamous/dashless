import { readFile, access } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { auditSite } from './audit-dist.mjs';
export async function checkPublication(project, { production = true } = {}) {
  const dist = path.join(project, 'dist');
  const manifest = JSON.parse(await readFile(path.join(dist, 'dashless-publication.json'), 'utf8'));
  const basePath = (process.env.DASHLESS_BASE_PATH || '').replace(/\/$/, '');
  const fsPath = (route) => {
    const value = String(route).replace(new RegExp(`^${basePath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`), '') || '/';
    return value;
  };
  if (manifest.version !== 1 || !Array.isArray(manifest.routes)) throw new Error('Unsupported publication contract');
  for (const route of manifest.routes) {
    if (!/^\/(?!\/)/.test(route) || route.includes('..') || /[\\?#]/.test(route)) throw new Error(`Unsafe publication route: ${route}`);
    await access(path.join(dist, fsPath(route), 'index.html'));
  }
  for (const post of manifest.posts) {
    const html = await readFile(path.join(dist, fsPath(post.url), 'index.html'), 'utf8');
    if (!html.includes('dashless-content-digest') || !html.includes('og:image')) throw new Error(`Article metadata missing: ${post.url}`);
    if (!post.socialImage) throw new Error(`Social card missing: ${post.url}`);
    const imagePath = new URL(post.socialImage, 'https://publication.invalid').pathname;
    const relative = imagePath.slice(imagePath.indexOf('/_dashless/social/'));
    const image = await readFile(path.join(dist, relative));
    if (image.subarray(0,8).toString('hex') !== '89504e470d0a1a0a' || image.readUInt32BE(16) !== 1200 || image.readUInt32BE(20) !== 630) throw new Error('Invalid social card');
  }
  const sitemap = await readFile(path.join(dist, 'sitemap.xml'), 'utf8');
  const rss = await readFile(path.join(dist, 'rss.xml'), 'utf8');
  if (!rss.includes('<rss') || !sitemap.includes('<urlset')) throw new Error('Invalid discovery document');
  if (sitemap.includes('/search/</loc>') || sitemap.includes('/404')) throw new Error('Noindex routes must not be in the sitemap');
  const searchRoute = `${basePath}/search/` || '/search/';
  for (const route of manifest.routes.filter(route => route !== searchRoute)) {
    const html = await readFile(path.join(dist, fsPath(route), 'index.html'), 'utf8');
    const canonical = html.match(/rel="canonical" href="([^"]+)"/)?.[1];
    if (!canonical || !sitemap.includes(`<loc>${canonical}</loc>`)) throw new Error(`Sitemap route missing: ${route}`);
    for (const match of html.matchAll(/<(?:img|script|link)\b[^>]*(?:src|href)="([^"#]+)"/g)) {
      const url = new URL(match[1], canonical);
      if (url.origin !== new URL(canonical).origin) continue;
      let asset = url.pathname;
      for (const marker of ['/_astro/', '/_dashless/']) if (asset.includes(marker)) asset = asset.slice(asset.indexOf(marker));
      if (asset.endsWith('/')) continue;
      await access(path.join(dist, fsPath(asset)));
    }
  }
  const search = await readFile(path.join(dist, 'search/index.html'), 'utf8');
  if (!search.includes('dashless-search-index') || !search.includes('noindex,follow')) throw new Error('Search contract missing');
  const report = auditSite({ projectPath: project, production });
  if (report.errors.length || report.warnings.length) throw new Error(`Publication quality gate failed: ${JSON.stringify([...report.errors, ...report.warnings])}`);
  return { version: 1, passed: true, routes: manifest.routes.length, articles: manifest.posts.length, htmlPages: report.filesAudited };
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const { default: config } = await import(pathToFileURL(path.join(process.cwd(), 'dashless.config.mjs')).href);
  const local = ['localhost', '127.0.0.1', '[::1]'].includes(new URL(config.publicUrl).hostname);
  console.log(JSON.stringify(await checkPublication(process.cwd(), { production: !local })));
}
