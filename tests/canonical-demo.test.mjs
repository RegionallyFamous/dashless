import test from 'node:test';
import assert from 'node:assert/strict';
import { access, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const example = path.join(repo, 'examples/small-problems');

test('Small Problems has no second frontend', async () => {
  await assert.rejects(access(path.join(example, 'src')));

  const packageJson = JSON.parse(await readFile(path.join(example, 'package.json'), 'utf8'));
  assert.equal(packageJson.scripts.build, 'node demo/run.mjs build');
  assert.equal(packageJson.scripts.dev, 'node demo/run.mjs dev');
  assert.equal(packageJson.scripts.preview, 'node demo/run.mjs preview');

  const runner = await readFile(path.join(example, 'demo/run.mjs'), 'utf8');
  assert.match(runner, /templates\/astro/);
  assert.match(runner, /canonical frontend/);
});
