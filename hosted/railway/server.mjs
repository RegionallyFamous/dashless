import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash, createHmac, timingSafeEqual, randomUUID } from 'node:crypto';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const digest = data => createHash('sha256').update(data).digest('hex');
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
const assetPath = /^media\/[A-Za-z0-9._-]+$/;
const safePath = p => typeof p === 'string' && p.length < 512 && !p.startsWith('/') && !p.includes('\\') && p.split('/').every(s => s && s !== '.' && s !== '..' && !/[\x00-\x1f]/.test(s));
const fail = (status, code) => { throw Object.assign(new Error(code), { status, code }); };
const atomic = async (file, data) => { const temp = `${file}.${randomUUID()}.tmp`; await fs.writeFile(temp, data, { mode: 0o600 }); await fs.rename(temp, file); };
export const siteToken = (master, site) => createHmac('sha256', master).update(`dashless-builder-v1:${site}`).digest('hex');

export async function createBuilder({ root, master, runtime = path.resolve('hosted/runtime'), deadlineMs = 240000, executor } = {}) {
  if (!master || master.length < 64) throw new Error('Builder master key must contain at least 64 characters');
  await fs.mkdir(root, { recursive: true, mode: 0o700 });
  // Deploy exactly one replica; the attached Railway volume serializes deployments.
  const jobs = new Map(), locks = new Map(); let running = null, closing = false;
  async function save(j) { await atomic(path.join(root, j.key, 'job.json'), JSON.stringify(j)); }
  for (const name of await fs.readdir(root)) {
    if (!/^\d+-[a-f0-9-]{36}$/.test(name)) continue;
    try {
      const j = JSON.parse(await fs.readFile(path.join(root, name, 'job.json'), 'utf8'));
      if (j.key !== name) continue;
      if (j.status === 'running') { j.status = 'failed'; j.error = 'worker_restarted'; await save(j); }
      jobs.set(name, j);
    } catch { /* Incomplete pre-commit uploads have no accepted job. */ }
  }
  async function exclusive(key, fn) {
    const before = locks.get(key) || Promise.resolve();
    const next = before.catch(() => {}).then(fn); locks.set(key, next);
    try { return await next; } finally { if (locks.get(key) === next) locks.delete(key); }
  }
  const view = j => ({ job_id: j.id, site_id: j.site, status: j.status, input_sha256: j.input_sha256, error: j.error || null, uploaded: j.uploaded || [] });
  async function execute(j) {
    const work = path.join(root, j.key);
    if (executor) return executor(work, j);
    await new Promise((resolve, reject) => {
      // No Railway/API credentials in the child; only pinned template code executes.
      const child = spawn(process.execPath, [path.join(runtime, 'build.mjs'), work], {
        cwd: work, detached: true, stdio: ['ignore', 'pipe', 'pipe'], env: {
          PATH: process.env.PATH, HOME: work, TMPDIR: work, NODE_OPTIONS: '--max-old-space-size=1024',
          ASTRO_TELEMETRY_DISABLED: '1', UV_THREADPOOL_SIZE: '2', VIPS_CONCURRENCY: '1', RAYON_NUM_THREADS: '2'
        }
      });
      j.child = child;
      let output = '';
      const capture = chunk => { output = (output + chunk.toString()).slice(-8000); };
      child.stdout.on('data', capture); child.stderr.on('data', capture);
      const stop = () => { try { process.kill(-child.pid, 'SIGKILL'); } catch {} };
      const timer = setTimeout(stop, deadlineMs);
      child.once('error', e => { clearTimeout(timer); delete j.child; reject(e); });
      child.once('close', code => { clearTimeout(timer); stop(); delete j.child; if (code === 0) resolve(); else { const detail=output.replace(/\s+/g,' ').trim().slice(-500); reject(new Error(detail ? `build_failed: ${detail}` : 'build_failed')); } });
    });
  }
  async function pump() {
    if (running || closing) return;
    const j = [...jobs.values()].find(j => j.status === 'queued'); if (!j) return;
    running = j; j.status = 'running'; await save(j);
    try {
      await execute(j);
      const work = path.join(root, j.key), result = JSON.parse(await fs.readFile(path.join(work, 'build-result.json'), 'utf8'));
      if (result.frontend_contract !== 1 || result.quality?.passed !== true || !Array.isArray(result.files) || result.files.length > 20000) throw new Error('invalid_output');
      let total = 0; const seen = new Set();
      for (const f of result.files) {
        if (!safePath(f.path) || seen.has(f.path) || !Number.isSafeInteger(f.bytes) || f.bytes < 0 || f.bytes > 64*1024*1024 || !/^[a-f0-9]{64}$/.test(f.sha256)) throw new Error('invalid_output');
        seen.add(f.path); total += f.bytes; if (total > 1024*1024*1024) throw new Error('output_too_large');
        const file = path.join(work, 'source/dist', f.path), stat = await fs.lstat(file);
        if (!stat.isFile() || stat.isSymbolicLink()) throw new Error('invalid_output');
        const bytes = await fs.readFile(file); if (bytes.length !== f.bytes || digest(bytes) !== f.sha256) throw new Error('invalid_output');
      }
      await new Promise((resolve, reject) => {
        const zip = spawn('zip', ['-q', '-r', path.join(work, 'output.zip'), '.'], { cwd: path.join(work, 'source/dist'), stdio: 'ignore' });
        zip.once('error', reject); zip.once('close', code => code === 0 ? resolve() : reject(new Error('archive_failed')));
      });
      const archive = await fs.readFile(path.join(work, 'output.zip'));
      result.archive = { bytes: archive.length, sha256: digest(archive) };
      await atomic(path.join(work, 'build-result.json'), JSON.stringify(result));
      j.status = 'succeeded';
    } catch (error) { const detail=error instanceof Error ? error.message : String(error); console.error(`Dashless build failed for ${j.site}/${j.id}: ${detail}`); j.status = 'failed'; j.error = detail.replace(/\s+/g,' ').slice(-600); }
    finally { j.finished = Date.now(); await save(j); running = null; setImmediate(() => pump().catch(() => {})); }
  }
  async function body(req, limit) {
    const chunks = []; let size = 0;
    for await (const b of req) { size += b.length; if (size > limit) fail(413, 'payload_too_large'); chunks.push(b); }
    return Buffer.concat(chunks);
  }
  const server = http.createServer(async (req, res) => {
    res.setHeader('Cache-Control', 'no-store'); res.setHeader('X-Content-Type-Options', 'nosniff');
    const json = (status, data) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(data)); };
    try {
      const u = new URL(req.url, 'http://builder');
      if (u.pathname === '/health' && req.method === 'GET') return json(closing ? 503 : 200, { ok: !closing, contract: 1 });
      const m = u.pathname.match(/^\/v1\/sites\/([1-9][0-9]{0,15})\/jobs\/([a-f0-9-]{36})(?:\/(.*))?$/);
      if (!m || !uuid.test(m[2])) fail(404, 'not_found');
      const [, site, id, action = ''] = m, expected = siteToken(master, site), supplied = (req.headers.authorization || '').replace(/^Bearer /, '');
      if (supplied.length !== expected.length || !timingSafeEqual(Buffer.from(supplied), Buffer.from(expected))) fail(401, 'unauthorized');
      await exclusive(`${site}-${id}`, async () => {
        const key = `${site}-${id}`, work = path.join(root, key); let j = jobs.get(key);
        if (req.method === 'POST' && action === '') {
          const raw = await body(req, 16*1024*1024); let snapshot; try { snapshot = JSON.parse(raw); } catch { fail(400, 'invalid_snapshot'); }
          if (snapshot.frontend_contract !== 1 || String(snapshot.site_id) !== site || !snapshot.assets || !Array.isArray(snapshot.items)) fail(400, 'invalid_snapshot');
          let total = 0;
          for (const [name, a] of Object.entries(snapshot.assets)) {
            if (!assetPath.test(name) || !Number.isSafeInteger(a.bytes) || a.bytes < 0 || a.bytes > 64*1024*1024 || !/^[a-f0-9]{64}$/.test(a.sha256)) fail(400, 'invalid_asset');
            total += a.bytes;
          }
          if (Object.keys(snapshot.assets).length > 10000 || total > 1024*1024*1024) fail(413, 'snapshot_too_large');
          const hash = digest(raw);
          if (j) { if (j.input_sha256 !== hash) fail(409, 'input_changed'); return json(200, view(j)); }
          if (jobs.size >= 100 || [...jobs.values()].filter(x => x.site === site && !['failed','succeeded'].includes(x.status)).length >= 2) { res.setHeader('Retry-After', '30'); fail(429, 'capacity_limited'); }
          await fs.mkdir(path.join(work, 'media'), { recursive: true, mode: 0o700 }); await atomic(path.join(work, 'snapshot.json'), raw);
          j = { key, id, site, input_sha256: hash, status: 'uploading', created: Date.now() }; await save(j); jobs.set(key, j); return json(202, view(j));
        }
        if (!j) fail(404, 'not_found');
        if (req.method === 'GET' && action === '') return json(200, view(j));
        if (req.method === 'PUT' && action.startsWith('media/')) {
          if (j.status !== 'uploading' || !assetPath.test(action)) fail(409, 'upload_closed');
          const snapshot = JSON.parse(await fs.readFile(path.join(work, 'snapshot.json'), 'utf8')), a = snapshot.assets[action];
          if (!a) fail(404, 'asset_missing');
          const bytes = await body(req, a.bytes); if (bytes.length !== a.bytes || digest(bytes) !== a.sha256) fail(400, 'asset_mismatch');
          await atomic(path.join(work, action), bytes); j.uploaded = [...new Set([...(j.uploaded || []), action])]; await save(j); return json(200, { accepted: true });
        }
        if (req.method === 'POST' && action === 'start') {
          if (j.status === 'uploading') {
            const snapshot = JSON.parse(await fs.readFile(path.join(work, 'snapshot.json'), 'utf8'));
            for (const [name, a] of Object.entries(snapshot.assets)) {
              const bytes = await fs.readFile(path.join(work, name)).catch(() => null);
              if (!bytes || bytes.length !== a.bytes || digest(bytes) !== a.sha256) fail(409, 'upload_incomplete');
            }
            j.status = 'queued'; await save(j); setImmediate(() => pump().catch(() => {}));
          }
          return json(202, view(j));
        }
        if (req.method === 'GET' && action === 'result') {
          if (j.status !== 'succeeded') fail(409, 'not_ready');
          return json(200, JSON.parse(await fs.readFile(path.join(work, 'build-result.json'), 'utf8')));
        }
        if (req.method === 'GET' && action === 'archive') {
          if (j.status !== 'succeeded') fail(409, 'not_ready');
          const { createReadStream } = await import('node:fs');
          res.writeHead(200, { 'Content-Type': 'application/zip' }); createReadStream(path.join(work, 'output.zip')).pipe(res); return;
        }
        if (req.method === 'GET' && action.startsWith('files/')) {
          if (j.status !== 'succeeded') fail(409, 'not_ready');
          const rel = decodeURIComponent(action.slice(6)); if (!safePath(rel)) fail(400, 'invalid_path');
          const result = JSON.parse(await fs.readFile(path.join(work, 'build-result.json'), 'utf8'));
          if (!result.files.some(f => f.path === rel)) fail(404, 'not_found');
          const bytes = await fs.readFile(path.join(work, 'source/dist', rel)); res.writeHead(200, { 'Content-Type': 'application/octet-stream' }); return res.end(bytes);
        }
        if (req.method === 'DELETE' && action === '') {
          if (!['succeeded','failed'].includes(j.status)) fail(409, 'job_active');
          await fs.rm(work, { recursive: true, force: true }); jobs.delete(key); return json(200, { deleted: true });
        }
        fail(404, 'not_found');
      });
    } catch (e) { if (!res.headersSent) json(e.status || 500, { error: e.code && e.status ? e.code : 'operation_failed' }); else res.end(); }
  });
  server.requestTimeout = 60000;
  const cleanup = setInterval(async () => {
    for (const j of jobs.values()) if (j !== running && Date.now() - (j.finished || j.created) > 86400000) {
      await exclusive(j.key, async () => { await fs.rm(path.join(root, j.key), { recursive: true, force: true }); jobs.delete(j.key); }).catch(() => {});
    }
  }, 60000); cleanup.unref();
  setImmediate(() => pump().catch(() => {}));
  return { server, async close() { closing = true; clearInterval(cleanup); if (running?.child) { try { process.kill(-running.child.pid, 'SIGKILL'); } catch {} } await new Promise(r => server.close(r));  } };
}
if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const app = await createBuilder({ root: process.env.BUILD_DATA_DIR || '/data', master: process.env.BUILD_MASTER_KEY, runtime: process.env.BUILD_RUNTIME || '/app/runtime' });
  app.server.listen(Number(process.env.PORT || 3000), '0.0.0.0');
  for (const signal of ['SIGTERM','SIGINT']) process.once(signal, async () => { await app.close(); process.exit(0); });
}
