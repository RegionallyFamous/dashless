import fs from 'node:fs/promises';
import path from 'node:path';

const repo = path.resolve(new URL('.', import.meta.url).pathname, '../..');
const dist = path.join(repo, 'hosted/dist');
const packages = JSON.parse(await fs.readFile(path.join(dist, 'site-packages.json'), 'utf8'));
const origin = (process.env.DASHLESS_RELEASE_ORIGIN || 'https://dashless.blog').replace(/\/$/, '');
const base = `${origin}/wp-content/dashless-packages/`;
const manifest = {
  schema: 1,
  version: packages.version,
  site_plugin_version: packages.version,
  site_package_url: `${base}${packages.site.filename}`,
  site_package_sha256: packages.site.sha256,
  runtime_package_url: `${base}${packages.runtime.filename}`,
  runtime_package_sha256: packages.runtime.sha256,
  release_id: packages.site.sha256,
  created_at: new Date().toISOString(),
};
await fs.writeFile(path.join(dist, 'current.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(JSON.stringify(manifest, null, 2));
