import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';

const repo = path.resolve(new URL('.', import.meta.url).pathname, '../..');
const source = path.join(repo, 'hosted/hub-frontend/dist');
const output = path.join(repo, 'hosted/dist/hub');
const publicHost = new URL(process.env.DASHLESS_RELEASE_ORIGIN || 'https://dashless.blog').hostname;
const sha = (bytes) => createHash('sha256').update(bytes).digest('hex');
const files = [];

async function walk(dir, relative = '') {
  for (const entry of await fs.readdir(path.join(dir, relative), { withFileTypes: true })) {
    const next = relative ? `${relative}/${entry.name}` : entry.name;
    if (entry.isDirectory()) await walk(dir, next);
    else if (entry.isFile()) {
      const bytes = await fs.readFile(path.join(dir, next));
      files.push({ path: next, bytes: bytes.length, sha256: sha(bytes) });
    }
  }
}

await walk(source);
files.sort((a, b) => a.path.localeCompare(b.path));
if (!files.some((entry) => entry.path === 'index.html') || !files.some((entry) => entry.path === '404.html')) throw new Error('Hub build must contain index.html and 404.html');
const fingerprint = sha(Buffer.from(files.map((entry) => `${entry.path}:${entry.bytes}:${entry.sha256}`).join('\n'))).slice(0, 6);
const releaseId = `${new Date().toISOString().replace(/[-:.]/g, '').replace(/\d{3}Z$/, 'Z')}-${fingerprint}`;
const manifest = {
  version: 1,
  release_id: releaseId,
  public_host: publicHost,
  content_generation: 0,
  created_at: new Date().toISOString(),
  build_system: 'canonical-astro-hub-v1',
  build_order: ['hub-astro', 'theme-demos', 'immutable-release'],
  files,
};
await fs.rm(output, { recursive: true, force: true });
await fs.mkdir(output, { recursive: true });
for (const entry of files) {
  const target = path.join(output, releaseId, entry.path);
  await fs.mkdir(path.dirname(target), { recursive: true });
  await fs.copyFile(path.join(source, entry.path), target);
}
await fs.writeFile(path.join(output, releaseId, 'dashless-release.json'), `${JSON.stringify(manifest, null, 2)}\n`);
await fs.writeFile(path.join(output, 'current.json'), `${JSON.stringify(manifest, null, 2)}\n`);
await fs.writeFile(path.join(output, 'release.json'), `${JSON.stringify({ ...manifest, directory: releaseId }, null, 2)}\n`);
console.log(JSON.stringify({ releaseId, publicHost, files: files.length, output }, null, 2));
