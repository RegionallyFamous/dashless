import assert from 'node:assert/strict';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { createMediaCache } from '../templates/astro/src/lib/media-cache.mjs';
const url = 'https://example.com/wp-content/uploads/picture.png';
const validate = async (bytes) => { if (!bytes.toString().startsWith('valid:')) throw new Error('broken image'); };
const response = (body, etag = '"one"') => new Response(body, { headers: { etag } });

test('media cache persists across builds, revalidates and deduplicates concurrent requests', async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'dashless-media-'));
  let requests = 0;
  const first = createMediaCache({ directory, validate, fetchImpl: async () => { requests++; return response('valid:one'); } });
  const [a, b] = await Promise.all([first.get(url), first.get(url)]);
  assert.deepEqual(a, b); assert.equal(requests, 1);
  const next = createMediaCache({ directory, validate, fetchImpl: async (requested, options) => {
    assert.equal(requested, url); assert.equal(options.headers['If-None-Match'], '"one"');
    return new Response(null, { status: 304 });
  } });
  assert.deepEqual(await next.get(url), a);
  assert.equal(next.stats.revalidated, 1);
  const changed = createMediaCache({ directory, validate, fetchImpl: async () => response('valid:two', '"two"') });
  const c = await changed.get(url);
  assert.notEqual(c.sha256, a.sha256); assert.equal((await readFile(c.file)).toString(), 'valid:two');
});

test('partial cache files trigger unconditional repair; malformed downloads never become valid cache entries', async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'dashless-media-'));
  const first = createMediaCache({ directory, validate, fetchImpl: async () => response('valid:one') });
  const cached = await first.get(url);
  await writeFile(cached.file, 'truncated');
  let calls = 0;
  const repair = createMediaCache({ directory, validate, fetchImpl: async (_, options) => {
    assert.deepEqual(options.headers, {}); return response(++calls === 1 ? 'bad' : 'valid:repaired');
  } });
  await repair.get(url); assert.equal(calls, 2); assert.equal(repair.stats.repaired, 1);
  const before = await readFile(cached.file);
  const failed = createMediaCache({ directory, validate, fetchImpl: async () => response('bad') });
  await assert.rejects(failed.get(url), /Could not cache WordPress media.*broken image/);
  assert.deepEqual(await readFile(cached.file), before);
});

test('last-modified validators and URL-specific keys prevent stale or colliding media', async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'dashless-media-'));
  const lastModified = 'Thu, 10 Sep 2026 12:00:00 GMT';
  const first = createMediaCache({ directory, validate, fetchImpl: async () => new Response('valid:one', { headers: { 'last-modified': lastModified } }) });
  const a = await first.get(url);
  const next = createMediaCache({ directory, validate, fetchImpl: async (_, options) => {
    assert.equal(options.headers['If-Modified-Since'], lastModified); return new Response(null, { status: 304 });
  } });
  await next.get(url);
  const different = await first.get(url.replace('example.com', 'other.example'));
  assert.notEqual(different.file, a.file);
});
