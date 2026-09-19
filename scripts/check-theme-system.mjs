import fs from 'node:fs';
import path from 'node:path';
import { themes, validateThemeCatalog, THEME_ROUTE_STATES, THEME_STRUCTURE_KEYS } from '../templates/astro/src/lib/theme-registry.mjs';

const root = path.resolve('.');
validateThemeCatalog(themes);
const signatures = new Set();
for (const theme of themes) {
  const reference = path.join(root, theme.visual_reference);
  if (!fs.existsSync(reference)) throw new Error(`Theme ${theme.id} is missing its visual reference: ${theme.visual_reference}`);
  const preview = path.join(root, 'wordpress/hosted/theme-previews', theme.preview_image);
  if (!fs.existsSync(preview)) throw new Error(`Theme ${theme.id} is missing its preview asset: ${theme.preview_image}`);
  const signature = THEME_STRUCTURE_KEYS.map((key) => theme.structure[key]).join('|');
  if (signatures.has(signature)) throw new Error(`Theme ${theme.id} duplicates another theme's structural acceptance`);
  signatures.add(signature);
}
console.log(`Theme system contract passed for ${themes.length} themes and ${THEME_ROUTE_STATES.length} route states.`);
