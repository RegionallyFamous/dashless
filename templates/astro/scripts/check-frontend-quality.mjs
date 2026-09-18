#!/usr/bin/env node

import { access, readdir, readFile, stat } from 'node:fs/promises';
import path from 'node:path';

const project = path.resolve(process.argv[2] || process.cwd());
const dist = path.join(project, 'dist');
const limits = {
  javascript: Number(process.env.DASHLESS_JS_BUDGET || 200 * 1024),
  css: Number(process.env.DASHLESS_CSS_BUDGET || 180 * 1024),
  html: Number(process.env.DASHLESS_HTML_BUDGET || 300 * 1024),
  asset: Number(process.env.DASHLESS_ASSET_BUDGET || 500 * 1024),
};

async function walk(directory) {
  const result = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (entry.name === '.prerender') continue;
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) result.push(...await walk(file));
    else result.push(file);
  }
  return result;
}

await access(dist);
const files = await walk(dist);
const failures = [];
const totals = { javascript: 0, css: 0, html: 0 };

for (const file of files) {
  const size = (await stat(file)).size;
  const relative = path.relative(project, file);
  const ext = path.extname(file).toLowerCase();
  const kind = ext === '.js' ? 'javascript' : ext === '.css' ? 'css' : ext === '.html' ? 'html' : null;
  if (kind) totals[kind] += size;
  if (kind && size > limits[kind]) failures.push(`${relative} is ${size} bytes; ${kind} asset budget is ${limits[kind]}.`);
  if (['.png', '.jpg', '.jpeg', '.webp', '.avif', '.gif', '.svg'].includes(ext) && size > limits.asset) {
    failures.push(`${relative} is ${size} bytes; asset budget is ${limits.asset}.`);
  }
  if (ext !== '.html') continue;
  const html = await readFile(file, 'utf8');
  if (!/^\s*<!doctype html>/i.test(html)) failures.push(`${relative} is missing a doctype.`);
  if (!/<html\b[^>]*\blang=["'][^"']+["']/i.test(html)) failures.push(`${relative} is missing a language attribute.`);
  if ((html.match(/<main\b/gi) || []).length !== 1) failures.push(`${relative} must contain exactly one main landmark.`);
  if ((html.match(/<h1\b/gi) || []).length !== 1) failures.push(`${relative} must contain exactly one h1.`);
  if (/<[^>]+\son[a-z]+\s*=/i.test(html)) failures.push(`${relative} contains an inline event handler.`);
}

const report = { project, dist, limits, totals, files: files.length, passed: failures.length === 0 };
console.log(JSON.stringify(report));
if (failures.length) {
  for (const failure of failures) console.error(`QUALITY: ${failure}`);
  process.exitCode = 1;
}
