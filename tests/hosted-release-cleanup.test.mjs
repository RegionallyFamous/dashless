import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { cleanupBatch, releaseIdsFromListing } from '../hosted/scripts/cleanup-hub-releases.mjs';

const root = '/htdocs/wp-content/uploads/dashless/releases';
const manifestsDir = fs.mkdtempSync(path.join(os.tmpdir(), 'dashless-release-manifests-'));
for (const releaseId of ['20260918T161947Z-3d21ba', '20260918T162559Z-dbe419']) {
  fs.writeFileSync(path.join(manifestsDir, `${releaseId}.json`), JSON.stringify({
    release_id: releaseId,
    files: [{ path: '_astro/index.css' }, { path: 'account/index.html' }, { path: 'index.html' }],
  }));
}
const listing = `sftp> ls -1 ${root}
${root}/20260918T161947Z-3d21ba
${root}/20260918T162559Z-dbe419
${root}/20260918T163022Z-dbe419
${root}/current.json
`;

test('release cleanup only emits exact superseded release directories', () => {
  assert.deepEqual(releaseIdsFromListing(listing, root), [
    '20260918T161947Z-3d21ba',
    '20260918T162559Z-dbe419',
    '20260918T163022Z-dbe419',
  ]);
  assert.equal(
    cleanupBatch({ listing, activeReleaseId: '20260918T163022Z-dbe419', remoteRoot: root, manifestsDir }),
    [
      `rm ${root}/20260918T161947Z-3d21ba/_astro/index.css`,
      `rm ${root}/20260918T161947Z-3d21ba/account/index.html`,
      `rm ${root}/20260918T161947Z-3d21ba/index.html`,
      `rmdir ${root}/20260918T161947Z-3d21ba/account`,
      `rmdir ${root}/20260918T161947Z-3d21ba/_astro`,
      `rmdir ${root}/20260918T161947Z-3d21ba`,
      `rm ${root}/20260918T162559Z-dbe419/_astro/index.css`,
      `rm ${root}/20260918T162559Z-dbe419/account/index.html`,
      `rm ${root}/20260918T162559Z-dbe419/index.html`,
      `rmdir ${root}/20260918T162559Z-dbe419/account`,
      `rmdir ${root}/20260918T162559Z-dbe419/_astro`,
      `rmdir ${root}/20260918T162559Z-dbe419`,
    ].join('\n'),
  );
});

test('release cleanup fails closed when the active release is absent', () => {
  assert.throws(
    () => cleanupBatch({ listing, activeReleaseId: '20260918T164000Z-a1b2c3', remoteRoot: root, manifestsDir }),
    /active release .* was not present/,
  );
});

test('release cleanup ignores traversal and non-release entries', () => {
  const unsafe = `${listing}/../other\n${root}/current.json.tmp\n`;
  assert.deepEqual(releaseIdsFromListing(unsafe, root), [
    '20260918T161947Z-3d21ba',
    '20260918T162559Z-dbe419',
    '20260918T163022Z-dbe419',
  ]);
});
