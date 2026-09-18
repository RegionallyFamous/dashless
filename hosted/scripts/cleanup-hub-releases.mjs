#!/usr/bin/env node

/**
 * Turn an SFTP directory listing into a safe batch that removes superseded
 * Hub release directories. The active release must be present in the listing;
 * otherwise this fails closed and emits no deletion commands.
 */

import fs from 'node:fs';
import path from 'node:path';

export const RELEASE_ID_PATTERN = /^\d{8}T\d{6}Z-[0-9a-f]{6}$/;

export function releaseIdsFromListing(listing, remoteRoot) {
  const root = remoteRoot.replace(/\/+$/, '');
  const ids = new Set();

  for (const rawLine of listing.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('sftp>')) continue;
    const path = line.replace(/^.*?\s+/, (match, offset) => (offset === 0 ? '' : match));
    const candidate = path.startsWith(`${root}/`) ? path.slice(root.length + 1) : path.split('/').at(-1);
    if (RELEASE_ID_PATTERN.test(candidate)) ids.add(candidate);
  }

  return [...ids].sort();
}

function manifestEntries(manifestsDir, releaseId) {
  const manifestPath = path.join(manifestsDir, `${releaseId}.json`);
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  if (manifest.release_id !== releaseId || !Array.isArray(manifest.files)) {
    throw new Error(`Refusing cleanup: invalid manifest for release ${releaseId}`);
  }
  const files = manifest.files.map((entry) => entry.path);
  for (const file of files) {
    if (typeof file !== 'string' || !file || path.posix.isAbsolute(file) || file.split('/').includes('..')) {
      throw new Error(`Refusing cleanup: unsafe manifest path in release ${releaseId}`);
    }
  }
  const directories = new Set();
  for (const file of files) {
    const parts = file.split('/');
    parts.pop();
    for (let index = 1; index <= parts.length; index += 1) directories.add(parts.slice(0, index).join('/'));
  }
  return { files, directories: [...directories].sort((a, b) => b.length - a.length) };
}

export function cleanupBatch({ listing, activeReleaseId, remoteRoot, manifestsDir }) {
  if (!RELEASE_ID_PATTERN.test(activeReleaseId)) {
    throw new Error(`Refusing cleanup: invalid active release id: ${activeReleaseId}`);
  }

  const root = remoteRoot.replace(/\/+$/, '');
  const ids = releaseIdsFromListing(listing, root);
  if (!ids.includes(activeReleaseId)) {
    throw new Error(`Refusing cleanup: active release ${activeReleaseId} was not present in the SFTP listing`);
  }
  if (!manifestsDir || !fs.statSync(manifestsDir).isDirectory()) {
    throw new Error(`Refusing cleanup: release manifest directory is unavailable: ${manifestsDir}`);
  }

  return ids
    .filter((id) => id !== activeReleaseId)
    .flatMap((id) => {
      const entries = manifestEntries(manifestsDir, id);
      return [
      ...entries.files.map((file) => `rm ${root}/${id}/${file}`),
      `rm ${root}/${id}/dashless-release.json`,
      ...entries.directories.map((directory) => `rmdir ${root}/${id}/${directory}`),
      `rmdir ${root}/${id}`,
      ];
    })
    .join('\n');
}

function arg(name) {
  const index = process.argv.indexOf(name);
  return index === -1 ? null : process.argv[index + 1] ?? null;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const activeReleaseId = arg('--active');
  const remoteRoot = arg('--remote-root');
  const manifestsDir = arg('--manifests-dir');
  if (!activeReleaseId || !remoteRoot || !manifestsDir) {
    console.error('Usage: cleanup-hub-releases.mjs --active RELEASE_ID --remote-root REMOTE_PATH --manifests-dir LOCAL_PATH');
    process.exit(2);
  }

  try {
    const listing = fs.readFileSync(0, 'utf8');
    const batch = cleanupBatch({ listing, activeReleaseId, remoteRoot, manifestsDir });
    if (batch) process.stdout.write(`${batch}\n`);
  } catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
  }
}
