import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { cleanupBatch, releaseIdsFromListing, releasesToCleanup } from '../hosted/scripts/cleanup-hub-releases.mjs';

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
      `rm ${root}/20260918T161947Z-3d21ba/dashless-release.json`,
      `rmdir ${root}/20260918T161947Z-3d21ba/account`,
      `rmdir ${root}/20260918T161947Z-3d21ba/_astro`,
      `rmdir ${root}/20260918T161947Z-3d21ba`,
    ].join('\n'),
  );
});

test('release cleanup retains the newest previous release for rollback', () => {
  const active = '20260918T163022Z-dbe419';
  const ids = [
    '20260918T150000Z-aaaaaa',
    '20260918T160000Z-bbbbbb',
    active,
    'not-a-release',
  ];
  assert.deepEqual(releasesToCleanup(ids, active), ['20260918T150000Z-aaaaaa']);
  assert.deepEqual(releasesToCleanup(ids, active, 0), [
    '20260918T150000Z-aaaaaa',
    '20260918T160000Z-bbbbbb',
  ]);
});

test('release cleanup can target one release and continue after partial cleanup', () => {
  const batch = cleanupBatch({
    listing,
    activeReleaseId: '20260918T163022Z-dbe419',
    remoteRoot: root,
    manifestsDir,
    onlyReleaseId: '20260918T161947Z-3d21ba',
    continueOnError: true,
  });
  assert.match(batch, /-rm \/htdocs\/wp-content\/uploads\/dashless\/releases\/20260918T161947Z-3d21ba\/index\.html/);
  assert.doesNotMatch(batch, /162559Z-dbe419/);
  assert.match(batch, /-rmdir \/htdocs\/wp-content\/uploads\/dashless\/releases\/20260918T161947Z-3d21ba/);
});

test('release cleanup validates a retained rollback manifest when inspected explicitly', () => {
  const releaseId = '20260918T162559Z-dbe419';
  const manifestPath = path.join(manifestsDir, `${releaseId}.json`);
  const original = fs.readFileSync(manifestPath, 'utf8');
  fs.writeFileSync(manifestPath, JSON.stringify({ release_id: releaseId, files: [{ path: '../escape.html' }] }));
  try {
    assert.throws(
      () => cleanupBatch({ listing, activeReleaseId: '20260918T163022Z-dbe419', remoteRoot: root, manifestsDir, onlyReleaseId: releaseId }),
      /unsafe manifest path/,
    );
  } finally {
    fs.writeFileSync(manifestPath, original);
  }
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
