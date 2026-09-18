import fs from 'node:fs';
import path from 'node:path';
import { themes } from '../templates/astro/src/lib/theme-registry.mjs';

const root = path.resolve('.');
const pages = path.join(root, 'templates', 'astro', 'src', 'pages');
const requiredRoutes = {
  home: 'index.astro',
  archive: 'stories/index.astro',
  article: 'stories/[slug].astro',
  search: 'search/index.astro',
  taxonomy: 'topics/[slug].astro',
  rss: 'rss.xml.js',
  '404': '404.astro',
};

for (const [state, relative] of Object.entries(requiredRoutes)) {
  if (!fs.existsSync(path.join(pages, relative))) throw new Error(`Missing Astro route for ${state}: ${relative}`);
}
for (const theme of themes) {
  for (const state of Object.keys(requiredRoutes)) {
    if (!theme.route_states.includes(state)) throw new Error(`${theme.id} does not declare route state ${state}`);
  }
}
console.log(`Theme route matrix passed for ${themes.length} themes across ${Object.keys(requiredRoutes).length} states.`);
