import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve('.');
const dist = path.join(root, 'hosted', 'hub-frontend', 'dist');
const html = fs.readFileSync(path.join(dist, 'index.html'), 'utf8');
const catalog = JSON.parse(fs.readFileSync(path.join(root, 'templates', 'astro', 'src', 'lib', 'themes.json'), 'utf8'));
const themesHtml = fs.readFileSync(path.join(dist, 'themes', 'index.html'), 'utf8');
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
if (!themesHtml.includes(`${String(catalog.length).padStart(2, '0')} EDITIONS`)) fail('theme page count does not match the canonical catalog');
for (const theme of catalog) {
  if (!themesHtml.includes(`>${theme.name}<`)) fail(`theme page is missing catalog theme: ${theme.id}`);
  const demoSlug = theme.id === 'after-hours' ? 'afterhours' : theme.id;
  if (!themesHtml.includes(`/demos/${demoSlug}/`)) fail(`theme page is missing demo link: ${theme.id}`);
  if (!fs.readdirSync(path.join(dist, '_astro')).some((name) => name.startsWith(`theme-preview-${theme.id}.`) && name.endsWith('.png'))) fail(`built Hub is missing preview asset: ${theme.id}`);
}
for (const route of ['support', 'privacy', 'terms']) {
  const page = fs.readFileSync(path.join(dist, route, 'index.html'), 'utf8');
  if (!page.includes('howdy@regionallyfamous.com')) fail(`${route} page is missing the support contact`);
  if (page.includes('WordPress.com')) fail(`${route} page contains retired provider copy`);
}
if (!html.includes('href="/support/"') || !html.includes('href="/privacy/"') || !html.includes('href="/terms/"')) fail('homepage footer is missing public support and policy links');

console.log('Hub frontend contract passed.');
