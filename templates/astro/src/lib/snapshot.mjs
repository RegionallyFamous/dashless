import { readFile, realpath } from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';

// Build-time adapter only. Never fetch a missing resource from the public network.
export async function loadSnapshot(file) {
  if (!file) return null;
  const data = JSON.parse(await readFile(file, 'utf8'));
  if (data.frontend_contract !== 1) throw new Error('Unsupported frontend snapshot; rebuild with the current site plugin');
  const root = await realpath(path.dirname(file));
  const assets = new Map(Object.entries(data.assets).map(([relative, asset]) => {
    if (!/^media\/[A-Za-z0-9._-]+$/.test(relative)) throw new Error('Unsafe snapshot asset path');
    return [asset.url, { ...asset, relative }];
  }));
  const target = data.target?.after;
  const items = data.items.filter(item => item.status === 'publish' || (target && item.id === target.id && item.post_type === target.post_type));
  const ids = new Set();
  for (const item of items) {
    const key = `${item.post_type}:${item.id}`;
    if (ids.has(key)) throw new Error('Duplicate snapshot item');
    ids.add(key);
    if (!['post', 'page'].includes(item.post_type) || !item.slug || /[\\/?#\u0000-\u0020]/.test(item.slug) || ['.', '..'].includes(item.slug)) throw new Error('Unsafe snapshot route');
    if (typeof item.rendered?.content !== 'string' || typeof item.rendered?.excerpt !== 'string') throw new Error('Snapshot requires WordPress-rendered content');
  }
  function post(item) {
    const date = value => /Z$|[+-]\d\d:\d\d$/.test(value) ? value : `${value}Z`;
    return { ...item, date: date(item.date_gmt), modified: date(item.modified_gmt),
      title: { raw: item.title, rendered: item.rendered.title ?? item.title },
      content: { raw: item.content, rendered: item.rendered.content },
      excerpt: { raw: item.excerpt, rendered: item.rendered.excerpt } };
  }
  return {
    data,
    asset: url => assets.get(url),
    async localAsset(url) {
      const asset = assets.get(url);
      if (!asset) throw new Error('Media is absent from the sealed snapshot');
      const file = await realpath(path.join(root, asset.relative));
      if (!file.startsWith(`${root}${path.sep}`)) throw new Error('Snapshot media escaped workspace');
      const bytes = await readFile(file);
      if (bytes.length !== asset.bytes || createHash('sha256').update(bytes).digest('hex') !== asset.sha256) throw new Error('Snapshot media checksum mismatch');
      return file;
    },
    request(route, query = {}) {
      let result;
      if (route === 'dashless/v1/site') return { data: { content_generation: data.generation }, headers: new Headers() };
      if (/^wp\/v2\/(posts|pages)$/.test(route)) {
        const type = route.endsWith('posts') ? 'post' : 'page';
        result = items.filter(item => item.post_type === type).map(post);
        result.sort(type === 'post' ? (a,b) => b.date.localeCompare(a.date) || b.id-a.id : (a,b) => a.menu_order-b.menu_order || a.id-b.id);
      } else if (/^wp\/v2\/(categories|tags)$/.test(route)) {
        const categories = route.endsWith('categories');
        result = (data.terms[categories ? 'category' : 'post_tag'] || []).map(term => ({ ...term, count: items.filter(item => item.post_type === 'post' && (item[categories ? 'categories' : 'tags'] || []).includes(term.id)).length }));
        if (query.hide_empty) result = result.filter(term => term.count > 0);
        result.sort((a,b) => a.name.localeCompare(b.name));
      } else if (/^wp\/v2\/media\/\d+$/.test(route)) {
        const item = data.media.find(item => item.id === Number(route.split('/').at(-1)));
        if (!item) throw new Error('Featured media is absent from snapshot');
        const asset = Object.values(data.assets).find(asset => asset.media_id === item.id && assets.get(asset.url)?.relative === item.files?.[0]);
        if (!asset) throw new Error('Featured media has no sealed asset');
        return { data: { ...item, source_url: asset.url }, headers: new Headers() };
      } else throw new Error(`Unsupported snapshot request: ${route}`);
      const size = Number(query.per_page || 100), page = Number(query.page || 1);
      return { data: result.slice((page-1)*size, page*size), headers: new Headers({ 'x-wp-totalpages': String(Math.max(1, Math.ceil(result.length/size))) }) };
    },
  };
}
