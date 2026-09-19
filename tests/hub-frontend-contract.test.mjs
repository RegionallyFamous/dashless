import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { verifyLive } from '../hosted/scripts/publish-hub-release.mjs';

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
  const spaced = execFileSync('node', ['hosted/scripts/cleanup-hub-root-assets.mjs', '--manifest', manifestPath, '--remote-root', '/htdocs/_astro'], { input: listing });
  assert.equal(spaced.toString(), 'rm /htdocs/_astro/old.css\n');
});

test('Hub live verification binds pages, demos, and previews to one release', async () => {
  const originalFetch = globalThis.fetch;
  const releaseId = '20260918T000000Z-test';
  const demoSlugs = ['hypertext-diary', 'field-notes', 'afterhours', 'bulletin', 'sunroom', 'mono-press'];
  const themeIds = ['hypertext-diary', 'field-notes', 'after-hours', 'bulletin', 'sunroom', 'mono-press'];
  const body = `${demoSlugs.map((slug) => `/demos/${slug}/`).join(' ')} ${themeIds.map((id) => `src="/_astro/theme-preview-${id}.hash.png"`).join(' ')} <a href="mailto:howdy@regionallyfamous.com">support</a>`;
  const assetBytes = Buffer.from('preview-bytes');
  const staleAssetBytes = Buffer.from('stale-preview-bytes');
  const assetHash = createHash('sha256').update(assetBytes).digest('hex');
  const manifest = { files: themeIds.map((id) => ({ path: `_astro/theme-preview-${id}.hash.png`, sha256: assetHash })) };
  const response = (contentType = 'text/html', headerRelease = releaseId, bytes = assetBytes) => ({
    status: 200,
    headers: { get: (name) => name === 'x-dashless-release' ? headerRelease : contentType },
    text: async () => body,
    arrayBuffer: async () => bytes,
  });
  globalThis.fetch = async (url, init) => {
    assert.equal(init.headers['X-Dashless-Verify'], releaseId);
    return url.includes('.png') ? response('image/png') : response();
  };
  try {
    await assert.doesNotReject(() => verifyLive(releaseId, manifest));
    globalThis.fetch = async (url, init) => {
      assert.equal(init.headers['X-Dashless-Verify'], releaseId);
      return url.includes('theme-preview-sunroom.') ? response('image/png', 'old-release', staleAssetBytes) : response(url.includes('.png') ? 'image/png' : 'text/html');
    };
    await assert.rejects(() => verifyLive(releaseId, manifest), /sunroom.*does not match the release manifest/);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
