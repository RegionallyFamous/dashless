import fs from 'node:fs';

function argument(name) {
  const inline = process.argv.find((arg) => arg.startsWith(`${name}=`));
  if (inline) return inline.slice(name.length + 1);
  const index = process.argv.indexOf(name);
  return index === -1 ? null : process.argv[index + 1] ?? null;
}
const manifestPath = argument('--manifest');
const remoteRoot = argument('--remote-root')?.replace(/\/+$/, '');
if (!manifestPath || !remoteRoot) throw new Error('Usage: cleanup-hub-root-assets.mjs --manifest=FILE --remote-root=REMOTE_PATH');

const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const expected = new Set(manifest.files.filter((file) => file.path.startsWith('_astro/')).map((file) => file.path.slice('_astro/'.length)));
if (!expected.size) throw new Error('Refusing root asset cleanup: active manifest has no _astro assets');

const actual = new Set();
for (const rawLine of fs.readFileSync(0, 'utf8').split(/\r?\n/)) {
  const line = rawLine.trim();
  if (!line || line.startsWith('sftp>')) continue;
  const name = line.split('/').at(-1);
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(name)) throw new Error(`Refusing root asset cleanup: unsafe remote entry ${line}`);
  actual.add(name);
}
for (const name of expected) if (!actual.has(name)) throw new Error(`Refusing root asset cleanup: active asset is missing remotely: ${name}`);
for (const name of [...actual].sort()) if (!expected.has(name)) console.log(`rm ${remoteRoot}/${name}`);
