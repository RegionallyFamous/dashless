import './prepare.mjs';
import { cp, lstat, mkdir, rm, symlink } from 'node:fs/promises';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const exampleRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const repoRoot = path.resolve(exampleRoot, '../..');
const templateRoot = path.join(repoRoot, 'templates/astro');
const generatedRoot = path.join(exampleRoot, '.canonical');
const snapshotPath = path.join(exampleRoot, 'demo/snapshot.json');
const command = process.argv[2] || 'dev';

if (!['build', 'dev', 'preview'].includes(command)) {
  throw new Error(`Unknown command: ${command}`);
}

// The example is intentionally not an Astro app of its own. Generate a disposable
// project from the one canonical frontend so local demos cannot drift from production.
await rm(generatedRoot, { recursive: true, force: true });
await cp(templateRoot, generatedRoot, {
  recursive: true,
  filter(source) {
    return !['node_modules', '.astro', 'dist', '.dashless-cache'].some((name) => source.includes(`${path.sep}${name}`));
  },
});
await cp(path.join(exampleRoot, 'dashless.config.mjs'), path.join(generatedRoot, 'dashless.config.mjs'));
await mkdir(path.join(generatedRoot, 'node_modules'), { recursive: true });

const templateModules = path.join(templateRoot, 'node_modules');
try {
  await lstat(templateModules);
  await rm(path.join(generatedRoot, 'node_modules'), { recursive: true, force: true });
  await symlink(templateModules, path.join(generatedRoot, 'node_modules'), 'dir');
} catch {
  throw new Error(`Canonical dependencies are missing at ${templateModules}; run npm ci in templates/astro first.`);
}

const env = { ...process.env, DASHLESS_SNAPSHOT: snapshotPath };
const astro = path.join(generatedRoot, 'node_modules/astro/bin/astro.mjs');

const run = (args) => new Promise((resolve, reject) => {
  const child = spawn(process.execPath, [astro, ...args], {
    cwd: generatedRoot,
    env,
    stdio: 'inherit',
  });
  child.on('error', reject);
  child.on('exit', (code, signal) => {
    if (signal) reject(new Error(`Astro exited with ${signal}`));
    else if (code) reject(new Error(`Astro exited with code ${code}`));
    else resolve();
  });
});

if (command === 'build') {
  await run(['check']);
  await run(['build']);
  const audit = spawn(process.execPath, [path.join(generatedRoot, 'scripts/check-publication.mjs')], {
    cwd: generatedRoot,
    env,
    stdio: 'inherit',
  });
  await new Promise((resolve, reject) => {
    audit.on('error', reject);
    audit.on('exit', (code) => code ? reject(new Error(`Publication audit exited with code ${code}`)) : resolve());
  });
  const quality = spawn(process.execPath, [path.join(generatedRoot, 'scripts/check-frontend-quality.mjs')], {
    cwd: generatedRoot,
    env,
    stdio: 'inherit',
  });
  await new Promise((resolve, reject) => {
    quality.on('error', reject);
    quality.on('exit', (code) => code ? reject(new Error(`Frontend quality gate exited with code ${code}`)) : resolve());
  });
} else {
  await run([command, '--host', '127.0.0.1', '--port', '4327']);
}
