import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const root = path.resolve('.');

test('the Astro Hub homepage and logo contract pass', () => {
  assert.doesNotThrow(() => execFileSync('node', ['hosted/scripts/check-hub-frontend.mjs'], { cwd: root, stdio: 'pipe' }));
});

test('root asset cleanup removes only stale hashed assets', () => {
  const manifest = { files: [{ path: '_astro/Layout.css' }, { path: '_astro/logo.webp' }] };
  const listing = 'sftp> ls -1 /htdocs/_astro\n/htdocs/_astro/Layout.css\n/htdocs/_astro/logo.webp\n/htdocs/_astro/old.css\n';
  const manifestPath = path.join(mkdtempSync(path.join(tmpdir(), 'dashless-root-assets-')), 'current.json');
  writeFileSync(manifestPath, JSON.stringify(manifest));
  const output = execFileSync('node', ['hosted/scripts/cleanup-hub-root-assets.mjs', `--manifest=${manifestPath}`, '--remote-root=/htdocs/_astro'], { input: listing });
  assert.equal(output.toString(), 'rm /htdocs/_astro/old.css\n');
});
