import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve('.');
const dist = path.join(root, 'hosted', 'hub-frontend', 'dist');
const html = fs.readFileSync(path.join(dist, 'index.html'), 'utf8');
const cssFile = fs.readdirSync(path.join(dist, '_astro')).find((name) => name.endsWith('.css'));
const css = cssFile ? fs.readFileSync(path.join(dist, '_astro', cssFile), 'utf8') : '';

function fail(message) {
  throw new Error(`Hub frontend contract failed: ${message}`);
}

if (!html.includes('class="dl-studio-layout"')) fail('restored collage homepage is missing');
for (const marker of ['dl-theme-gallery', 'dl-studio-steps', 'dl-example--collage', 'dl-price-ticket', 'dl-questions']) {
  if (!html.includes(marker)) fail(`homepage composition marker is missing: ${marker}`);
}
if ((html.match(/class="dl-rip"/g) || []).length !== 1) fail('the rip mark must have one Astro owner');
if ((html.match(/class="dl-hot-type"/g) || []).length !== 1) fail('the wordmark must have one Astro owner');
if (!/<span class="dl-hot-type" role="img" aria-label="Dashless"/.test(html)) fail('the wordmark must be an asset-backed semantic span');
if (!/--hot-type-url: url\(\/_astro\/hot-type-v3[.-]/.test(html)) fail('the generated wordmark must reference the hashed hot-type asset');
if (!css.includes('background-image:var(--hot-type-url)!important')) fail('the final cascade must restore the asset-backed wordmark crop');
if (html.includes('class="masthead"') || html.includes('dashless<span>')) fail('the old Astro homepage shell is still present');
if (!fs.readdirSync(path.join(dist, '_astro')).some((name) => /^hot-type-v3[.-]/.test(name))) fail('the hot-type asset was not emitted');

console.log('Hub frontend contract passed.');
