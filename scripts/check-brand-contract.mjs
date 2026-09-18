import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve('.');
const themeRoot = path.join(root, 'hosted', 'theme');
const functionsPath = path.join(themeRoot, 'functions.php');
const cssPath = path.join(themeRoot, 'assets', 'site.css');
const assetPaths = [
  path.join(themeRoot, 'assets', 'rip.svg'),
  path.join(themeRoot, 'assets', 'webp', 'hot-type-v3.webp'),
];

const functions = await readFile(functionsPath, 'utf8');
const css = await readFile(cssPath, 'utf8');

function fail(message) {
  throw new Error(`Brand contract failed: ${message}`);
}

if ((functions.match(/class="dl-rip"/g) || []).length !== 1) fail('the rip mark must have one semantic markup owner');
if ((functions.match(/class="dl-hot-type"/g) || []).length !== 1) fail('the wordmark must have one semantic markup owner');
if (!functions.includes("get_theme_file_uri('assets/rip.svg')")) fail('the rip mark must reference the canonical asset');
if (!functions.includes("aria-label=\"dashless\"")) fail('the wordmark must retain an accessible name');
if (/\.dl-rip\{display\s*:\s*none/i.test(css)) fail('the rip mark is hidden by CSS');
if (/\.dl-hot-type:after\{[^}]*content\s*:\s*['"]Dashless/i.test(css)) fail('the wordmark is being generated as replacement text');
if (!/\.dl-hot-type\{[^}]*background:[^}]*hot-type-v3\.webp/i.test(css)) fail('the wordmark must use the canonical image asset');
if (!/\.dl-hot-type\{[^}]*width:190px/i.test(css)) fail('desktop wordmark geometry is not explicit');
if (!/@media\(max-width:680px\)[\s\S]*\.dl-hot-type\{width:155px\}/.test(css)) fail('mobile header contract is missing');

const hashes = {};
for (const file of assetPaths) {
  const bytes = await readFile(file);
  hashes[path.relative(root, file)] = createHash('sha256').update(bytes).digest('hex');
}

const urlArg = process.argv.find((arg) => arg.startsWith('--url='));
const surface = process.argv.find((arg) => arg.startsWith('--surface='))?.slice('--surface='.length) || 'hub';
let live = null;
if (urlArg) {
  const origin = new URL(urlArg.slice('--url='.length));
  const requestUrl = new URL(origin);
  requestUrl.searchParams.set('brand_contract', Date.now().toString());
  const pageResponse = await fetch(requestUrl, { headers: { 'cache-control': 'no-cache' } });
  if (!pageResponse.ok) fail(`live page returned HTTP ${pageResponse.status}`);
  const html = await pageResponse.text();
  const hubSurface = (html.match(/class="dl-rip"/g) || []).length === 1 && (html.match(/class="dl-hot-type"/g) || []).length === 1;
  if (surface === 'hub' && !hubSurface) fail('live page is not the authenticated Hub surface; use a logged-in Hub URL');
  if (surface === 'reader' && !html.includes('<header class="masthead"')) fail('live page is not the Astro reader surface');
  const stylesheet = [...html.matchAll(/<link[^>]+href=["']([^"']+site\.css[^"']*)["']/gi)][0]?.[1];
  const cssUrl = stylesheet ? new URL(stylesheet, origin) : null;
  const cssResponse = cssUrl ? await fetch(cssUrl, { headers: { 'cache-control': 'no-cache' } }) : null;
  if (surface === 'hub' && (!cssResponse || !cssResponse.ok)) fail(`live stylesheet returned HTTP ${cssResponse?.status || 0}`);
  const liveCss = cssResponse ? await cssResponse.text() : '';
  if (surface === 'hub') {
    if (!html.includes('aria-label="dashless"')) fail('live page lost the accessible wordmark name');
    if (/\.dl-rip\{display\s*:\s*none/i.test(liveCss)) fail('live CSS hides the rip mark');
    if (/\.dl-hot-type:after\{[^}]*content\s*:\s*['"]Dashless/i.test(liveCss)) fail('live CSS generates replacement wordmark text');
    if (!/hot-type-v3\.webp/.test(liveCss)) fail('live CSS is missing the canonical wordmark asset');
  }
  const { chromium } = await import('playwright');
  const browser = await chromium.launch({ headless: true });
  try {
    const fingerprints = {};
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.goto(requestUrl.href, { waitUntil: 'networkidle' });
      fingerprints[width] = await page.evaluate(() => {
        const rip = document.querySelector('.dl-rip');
        const wordmark = document.querySelector('.dl-hot-type');
        const readerWordmark = document.querySelector('.wordmark');
        const header = document.querySelector('.dl-masthead');
        if (!rip || !wordmark || !header) return { valid: false, surface: readerWordmark ? 'reader' : 'unknown' };
        const ripStyle = getComputedStyle(rip);
        const wordmarkStyle = getComputedStyle(wordmark);
        const ripBox = rip.getBoundingClientRect();
        const wordmarkBox = wordmark.getBoundingClientRect();
        return {
          surface: 'hub', valid: ripStyle.display !== 'none' && wordmarkStyle.display !== 'none' && ripBox.width > 0 && wordmarkBox.width > 0,
          rip: `${Math.round(ripBox.width)}x${Math.round(ripBox.height)}`,
          wordmark: `${Math.round(wordmarkBox.width)}x${Math.round(wordmarkBox.height)}`,
          generatedContent: getComputedStyle(wordmark, '::after').content,
          expectedMobileScale: window.innerWidth > 680 || wordmarkBox.width <= 160,
          overflow: document.documentElement.scrollWidth > window.innerWidth,
        };
      });
      if (!fingerprints[width].valid) {
        if (surface === 'reader' && fingerprints[width].surface === 'reader') {
          fingerprints[width] = await page.evaluate(() => { const mark=document.querySelector('.wordmark'); const box=mark?.getBoundingClientRect(); return { surface:'reader', valid:!!mark && box.width>0 && box.height>0, wordmark:`${Math.round(box.width)}x${Math.round(box.height)}`, overflow:document.documentElement.scrollWidth>innerWidth }; });
        } else fail(`live header failed at ${width}px`);
      }
      if (fingerprints[width].overflow) fail(`live header overflows at ${width}px`);
      if (surface === 'hub') {
        if (fingerprints[width].generatedContent !== 'none') fail(`live header generates wordmark content at ${width}px`);
        if (!fingerprints[width].expectedMobileScale) fail(`live wordmark is oversized at ${width}px`);
      }
      await page.close();
    }
    live = { page: origin.href, surface, stylesheet: cssUrl?.href || null, rip: surface === 'hub', wordmark: true, fingerprints };
  } finally {
    await browser.close();
  }
}

const fingerprint = createHash('sha256').update(JSON.stringify({ functions, css, hashes, live })).digest('hex');
const report = { ok: true, fingerprint, assets: hashes, live };
console.log(process.argv.includes('--json') ? JSON.stringify(report, null, 2) : `Brand contract passed (${fingerprint.slice(0, 16)}).`);
