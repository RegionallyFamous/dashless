import { spawn } from "node:child_process";
import { createHash, randomBytes } from "node:crypto";
import { createReadStream } from "node:fs";
import { cp, lstat, mkdir, readdir, readFile, rename, rm, stat, symlink, writeFile } from "node:fs/promises";
import net from "node:net";
import { homedir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DashlessError } from "./errors.mjs";
import { dataPath, writeDataJson } from "./storage.mjs";

const pluginRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const templateRoot = path.join(pluginRoot, "templates", "astro");
const staticServer = path.join(pluginRoot, "server", "serve-static.mjs");
const wpCloudBridge = path.join(pluginRoot, "wordpress", "dashless-wpcloud.php");

async function exists(file) {
  return Boolean(await lstat(file).catch(() => null));
}

function safeConfig(config) {
  return `export default ${JSON.stringify(config, null, 2)};\n`;
}

export async function createFrontend({ projectPath, siteName, siteDescription, wordpressUrl, publicUrl, postsPath = "stories", topicsPath = "topics", tagsPath = "tags", postsPerPage = 12, homePageId = 0, postsPageId = 0 }) {
  const target = path.resolve(projectPath);
  const targetInfo = await lstat(target).catch(() => null);
  if (targetInfo && !targetInfo.isDirectory()) {
    throw new DashlessError("frontend_path_invalid", "The frontend target exists and is not a directory.");
  }
  if (targetInfo) {
    const entries = (await readdir(target)).filter((name) => !new Set([".git", ".DS_Store"]).has(name));
    if (entries.length) {
      throw new DashlessError("frontend_path_not_empty", "Dashless will not overwrite a non-empty directory. Choose a new frontend path.", { entries: entries.slice(0, 20) });
    }
  } else {
    await mkdir(target, { recursive: true });
  }
  const routeSegments = [postsPath, topicsPath, tagsPath].map((value, index) => {
    const fallback = ["stories", "topics", "tags"][index];
    const normalized = String(value || fallback).replace(/^\/+|\/+$/g, "");
    if (!/^[a-z0-9][a-z0-9-]*$/i.test(normalized)) {
      throw new DashlessError("frontend_route_invalid", "Archive paths must be one URL segment containing only letters, numbers, and hyphens.");
    }
    return normalized;
  });
  if (new Set(routeSegments).size !== routeSegments.length || routeSegments.includes("search")) {
    throw new DashlessError("frontend_route_conflict", "Posts, topics, tags, and search must use distinct URL paths.");
  }
  await cp(templateRoot, target, { recursive: true, errorOnExist: false, force: false });
  const temporaryRoutes = [];
  for (const [index, sourceName] of ["stories", "topics", "tags"].entries()) {
    const temporary = `.dashless-route-${index}`;
    await rename(path.join(target, "src", "pages", sourceName), path.join(target, "src", "pages", temporary));
    temporaryRoutes.push(temporary);
  }
  for (const [index, targetName] of routeSegments.entries()) {
    await rename(path.join(target, "src", "pages", temporaryRoutes[index]), path.join(target, "src", "pages", targetName));
  }
  const config = {
    siteName,
    siteDescription: siteDescription || siteName,
    wordpressUrl,
    publicUrl: publicUrl || "http://localhost:4321",
    postsPath: routeSegments[0],
    topicsPath: routeSegments[1],
    tagsPath: routeSegments[2],
    postsPerPage: Math.min(Math.max(Number(postsPerPage) || 12, 1), 50),
    homePageId: Number(homePageId || 0),
    postsPageId: Number(postsPageId || 0),
    mirrorMedia: true,
  };
  await writeFile(path.join(target, "dashless.config.mjs"), safeConfig(config));
  return { project_path: target, config, created: true };
}

export async function updateFrontendPublicUrl(projectPath, publicUrl) {
  const configPath = path.join(path.resolve(projectPath), "dashless.config.mjs");
  const source = await readFile(configPath, "utf8");
  const match = source.match(/^export default\s+([\s\S]+);\s*$/);
  if (!match) {
    return { updated: false, warning: "dashless.config.mjs is customized; update publicUrl there manually." };
  }
  try {
    const config = JSON.parse(match[1]);
    config.publicUrl = publicUrl;
    await writeFile(configPath, safeConfig(config));
    return { updated: true, public_url: publicUrl };
  } catch {
    return { updated: false, warning: "dashless.config.mjs is customized; update publicUrl there manually." };
  }
}

function run(command, args, { cwd, env, timeoutMs = 10 * 60 * 1000 } = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd,
      env: { ...process.env, ...env },
      stdio: ["ignore", "pipe", "pipe"],
      shell: false,
    });
    const output = [];
    let outputSize = 0;
    const collect = (chunk) => {
      if (outputSize > 2 * 1024 * 1024) return;
      output.push(chunk);
      outputSize += chunk.length;
    };
    child.stdout.on("data", collect);
    child.stderr.on("data", collect);
    const timer = setTimeout(() => {
      child.kill("SIGTERM");
      reject(new DashlessError("command_timeout", `${command} exceeded ${Math.round(timeoutMs / 1000)} seconds.`));
    }, timeoutMs);
    child.once("error", (error) => {
      clearTimeout(timer);
      reject(new DashlessError("command_failed", `Could not start ${command}: ${error.message}`));
    });
    child.once("close", (code) => {
      clearTimeout(timer);
      const text = Buffer.concat(output).toString("utf8").slice(-20_000);
      if (code === 0) resolve({ code, output: text });
      else reject(new DashlessError("command_failed", `${command} exited with status ${code}.`, { output: text }));
    });
  });
}

export async function buildFrontend({ projectPath, site, password, previewPayloadPath = null, releasePrefix = null, install = true }) {
  const target = path.resolve(projectPath);
  if (!(await exists(path.join(target, "package.json"))) || !(await exists(path.join(target, "dashless.config.mjs")))) {
    throw new DashlessError("frontend_not_dashless", "The selected directory is not a Dashless Astro frontend.");
  }
  let installed = false;
  if (!(await exists(path.join(target, "node_modules")))) {
    if (!install) throw new DashlessError("frontend_dependencies_missing", "Frontend dependencies are not installed.");
    const installCommand = await exists(path.join(target, "package-lock.json")) ? "ci" : "install";
    await run(process.platform === "win32" ? "npm.cmd" : "npm", [installCommand, "--no-audit", "--no-fund"], { cwd: target });
    installed = true;
  }
  await rm(path.join(target, "public", "_dashless", "media"), { recursive: true, force: true });
  await rm(path.join(target, "public", "_dashless", "social"), { recursive: true, force: true });
  const result = await run(process.platform === "win32" ? "npm.cmd" : "npm", ["run", "build"], {
    cwd: target,
    env: {
      WORDPRESS_URL: site.site_url,
      WORDPRESS_USERNAME: site.username,
      WORDPRESS_APP_PASSWORD: password,
      DASHLESS_PREVIEW_PAYLOAD: previewPayloadPath || "",
      DASHLESS_RELEASE_PREFIX: releasePrefix || "",
      DASHLESS_ASSETS_PREFIX: releasePrefix || "",
    },
  });
  const dist = path.join(target, "dist");
  if (!(await exists(path.join(dist, "index.html")))) {
    throw new DashlessError("frontend_build_incomplete", "Astro completed without producing dist/index.html.");
  }
  return { project_path: target, dist_path: dist, dependencies_installed: installed, output: result.output };
}

async function availablePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.unref();
    server.once("error", reject);
    server.listen(0, "127.0.0.1", () => {
      const { port } = server.address();
      server.close(() => resolve(port));
    });
  });
}

async function stopPriorPreview() {
  const runtimePath = dataPath("runtime", "preview.json");
  try {
    const runtime = JSON.parse(await readFile(runtimePath, "utf8"));
    if (Number.isInteger(runtime.pid)) process.kill(runtime.pid, "SIGTERM");
  } catch {
    // No process, an already stopped process, or invalid stale state all mean there is nothing to stop.
  }
}

export async function startPreviewServer({ distPath, routePath }) {
  await stopPriorPreview();
  const port = await availablePort();
  const child = spawn(process.execPath, [staticServer, "--root", path.resolve(distPath), "--port", String(port)], {
    cwd: pluginRoot,
    detached: true,
    stdio: "ignore",
  });
  child.unref();
  const baseUrl = `http://127.0.0.1:${port}`;
  let ready = false;
  for (let attempt = 0; attempt < 30; attempt += 1) {
    try {
      const response = await fetch(`${baseUrl}/`);
      if (response.ok) { ready = true; break; }
    } catch {
      // The process is still starting.
    }
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  if (!ready) {
    try { process.kill(child.pid, "SIGTERM"); } catch {}
    throw new DashlessError("preview_server_failed", "The Astro build succeeded, but the local preview server did not start.");
  }
  const url = new URL(routePath.replace(/^\//, ""), `${baseUrl}/`).toString();
  await writeDataJson(path.join("runtime", "preview.json"), { pid: child.pid, port, dist_path: path.resolve(distPath), url, started_at: new Date().toISOString() });
  return { preview_url: url, preview_pid: child.pid, port };
}

export function createReleaseId() {
  return `${new Date().toISOString().replace(/[-:.]/g, "")}-${randomBytes(3).toString("hex")}`;
}

function validateLocalReleasesPath(value) {
  if (typeof value !== "string" || !path.isAbsolute(value)) {
    throw new DashlessError("deployment_path_invalid", "releases_path must be an absolute path.");
  }
  if (value.includes("\0") || value.includes("\n") || value.includes("\r")) {
    throw new DashlessError("deployment_path_invalid", "releases_path contains unsupported control characters.");
  }
  return path.normalize(value);
}

function validateRemotePath(value, field = "releases_path") {
  if (typeof value !== "string" || !path.posix.isAbsolute(value)) {
    throw new DashlessError("deployment_path_invalid", `${field} must be an absolute POSIX path.`);
  }
  if (!/^\/[A-Za-z0-9._/@+ -]+$/.test(value)) {
    throw new DashlessError("deployment_path_invalid", `${field} contains unsupported characters.`);
  }
  return path.posix.normalize(value);
}

function normalizeDeploymentPublicUrl(value) {
  let parsed;
  try {
    parsed = new URL(value);
  } catch {
    throw new DashlessError("deployment_url_invalid", "The public URL must be a valid absolute URL.");
  }
  const local = parsed.protocol === "http:" && ["localhost", "127.0.0.1", "::1", "[::1]"].includes(parsed.hostname);
  if (parsed.protocol !== "https:" && !local) {
    throw new DashlessError("deployment_url_invalid", "The public URL must use HTTPS except for localhost.");
  }
  if (parsed.username || parsed.password || parsed.search || parsed.hash || !new Set(["", "/"]).has(parsed.pathname)) {
    throw new DashlessError("deployment_url_invalid", "The public URL must be a clean origin without credentials, a path, a query, or a fragment.");
  }
  parsed.hostname = parsed.hostname.replace(/\.$/, "");
  return parsed.origin;
}

export function validateDeployment(input) {
  if (!input || !new Set(["local", "ssh", "wpcloud"]).has(input.kind)) {
    throw new DashlessError("deployment_kind_invalid", "Deployment kind must be local, ssh, or wpcloud.");
  }
  const deployment = {
    kind: input.kind,
    public_url: normalizeDeploymentPublicUrl(input.public_url),
  };
  if (input.kind === "local") deployment.releases_path = validateLocalReleasesPath(input.releases_path);
  if (input.kind === "ssh") deployment.releases_path = validateRemotePath(input.releases_path);
  if (input.kind === "ssh" || input.kind === "wpcloud") {
    if (!/^[A-Za-z0-9.-]+$/.test(input.host || "")) throw new DashlessError("ssh_host_invalid", "SSH host contains unsupported characters.");
    if (input.user && !/^[A-Za-z0-9._-]+$/.test(input.user)) throw new DashlessError("ssh_user_invalid", "SSH user contains unsupported characters.");
    if (input.port !== undefined && (!Number.isInteger(input.port) || input.port < 1 || input.port > 65535)) {
      throw new DashlessError("ssh_port_invalid", "SSH port must be between 1 and 65535.");
    }
    deployment.host = input.host;
    deployment.user = input.user || null;
    deployment.port = input.port || 22;
    if (input.kind === "wpcloud" && !deployment.user) {
      throw new DashlessError("wpcloud_user_required", "WP Cloud deployment requires an SSH/SFTP username.");
    }
    if (input.identity_file) {
      const identity = path.resolve(input.identity_file);
      if (!(identity.startsWith(`${homedir()}${path.sep}`))) {
        throw new DashlessError("ssh_identity_invalid", "SSH identity file must be under the current user's home directory.");
      }
      deployment.identity_file = identity;
    }
  }
  if (input.kind === "wpcloud") {
    deployment.htdocs_path = validateRemotePath(input.htdocs_path || "/srv/htdocs", "htdocs_path");
    deployment.releases_path = path.posix.join(deployment.htdocs_path, "wp-content", "uploads", "dashless");
  }
  return deployment;
}

async function deployLocal(distPath, deployment, id) {
  const base = deployment.releases_path;
  const releases = path.join(base, "releases");
  const release = path.join(releases, id);
  await mkdir(releases, { recursive: true });
  await cp(path.resolve(distPath), release, { recursive: true, force: false, errorOnExist: true });
  const current = path.join(base, "current");
  const next = path.join(base, `.current-${id}`);
  const currentInfo = await lstat(current).catch(() => null);
  if (currentInfo && !currentInfo.isSymbolicLink()) {
    throw new DashlessError("deployment_current_not_symlink", `${current} exists and is not a symlink; Dashless will not replace it.`);
  }
  await symlink(path.relative(base, release), next);
  await rename(next, current);
  return { release_id: id, release_path: release, current_path: current };
}

function remoteQuote(value) {
  return `'${String(value).replaceAll("'", `'"'"'`)}'`;
}

async function deploySsh(distPath, deployment, id) {
  const target = `${deployment.user ? `${deployment.user}@` : ""}${deployment.host}`;
  const release = path.posix.join(deployment.releases_path, "releases", id);
  const sshArgs = [];
  const rsyncSsh = ["ssh"];
  if (deployment.port !== 22) { sshArgs.push("-p", String(deployment.port)); rsyncSsh.push("-p", String(deployment.port)); }
  if (deployment.identity_file) { sshArgs.push("-i", deployment.identity_file); rsyncSsh.push("-i", deployment.identity_file); }
  await run("ssh", [...sshArgs, target, `mkdir -p ${remoteQuote(release)}`], { timeoutMs: 60_000 });
  await run("rsync", ["-az", "--delete", "-e", rsyncSsh.join(" "), `${path.resolve(distPath)}${path.sep}`, `${target}:${release}/`]);
  const base = deployment.releases_path;
  const next = path.posix.join(base, `.current-${id}`);
  const current = path.posix.join(base, "current");
  const activate = `ln -sfn ${remoteQuote(path.posix.relative(base, release))} ${remoteQuote(next)} && mv -f ${remoteQuote(next)} ${remoteQuote(current)}`;
  await run("ssh", [...sshArgs, target, activate], { timeoutMs: 60_000 });
  return { release_id: id, release_path: release, current_path: current, host: target };
}

function sftpQuote(value) {
  return `"${String(value).replaceAll("\\", "\\\\").replaceAll('"', '\\"')}"`;
}

async function localTree(root) {
  const directories = [];
  const files = [];
  async function visit(relative = "") {
    const directory = path.join(root, relative);
    for (const entry of await readdir(directory, { withFileTypes: true })) {
      const child = path.join(relative, entry.name);
      if (child.includes("\n") || child.includes("\r")) {
        throw new DashlessError("wpcloud_build_filename_invalid", "The Astro build contains a filename that cannot be uploaded safely.");
      }
      if (entry.isDirectory()) {
        directories.push(child);
        await visit(child);
      } else if (entry.isFile()) {
        if (/(?:^|[/\\])(?:\.htaccess|\.user\.ini)$/i.test(child) || /\.(?:php\d*|phtml|phar)$/i.test(child)) {
          throw new DashlessError("wpcloud_build_file_unsafe", `WP Cloud deployment refuses a server-executable build file: ${child}`);
        }
        files.push(child);
      } else {
        throw new DashlessError("wpcloud_build_entry_unsupported", `The Astro build contains an unsupported filesystem entry: ${child}`);
      }
    }
  }
  await visit();
  return { directories, files };
}

function hashFile(file) {
  return new Promise((resolve, reject) => {
    const hash = createHash("sha256");
    const input = createReadStream(file);
    input.on("error", reject);
    input.on("data", (chunk) => hash.update(chunk));
    input.on("end", () => resolve(hash.digest("hex")));
  });
}

function normalizedContentGeneration(value) {
  if (value === null || value === undefined || value === "") return null;
  const generation = Number(value);
  if (!Number.isSafeInteger(generation) || generation < 0) {
    throw new DashlessError("content_generation_invalid", "WordPress reported an invalid Dashless content generation.", { generation: value });
  }
  return generation;
}

export function assertContentGenerationMatches(expected, current, phase = "deployment") {
  const expectedGeneration = normalizedContentGeneration(expected);
  const currentGeneration = normalizedContentGeneration(current);
  if (expectedGeneration === null) {
    return { expected_generation: null, current_generation: currentGeneration, verified: false };
  }
  if (currentGeneration !== expectedGeneration) {
    throw new DashlessError(
      "content_changed_during_deployment",
      "WordPress changed after the production build. The prior static release remains live; build again from the current WordPress generation.",
      { phase, expected_generation: expectedGeneration, current_generation: currentGeneration },
    );
  }
  return { expected_generation: expectedGeneration, current_generation: currentGeneration, verified: true };
}

export async function createWpCloudReleaseManifest({ distPath, deployment, releaseId, contentGeneration = null }) {
  const source = path.resolve(distPath);
  const generation = normalizedContentGeneration(contentGeneration);
  const { files } = await localTree(source);
  const entries = [];
  for (const file of files.filter((name) => name !== "dashless-release.json").sort()) {
    const absolute = path.join(source, file);
    const info = await stat(absolute);
    entries.push({
      path: file.split(path.sep).join("/"),
      bytes: info.size,
      sha256: await hashFile(absolute),
    });
  }
  if (!entries.some((entry) => entry.path === "index.html") || !entries.some((entry) => entry.path === "404.html")) {
    throw new DashlessError("wpcloud_build_incomplete", "A WP Cloud release must contain index.html and 404.html.");
  }
  let indexNowKey = null;
  if (entries.some((entry) => entry.path === "indexnow-key.txt")) {
    const candidate = (await readFile(path.join(source, "indexnow-key.txt"), "utf8")).trim();
    if (!/^[A-Za-z0-9_-]{8,128}$/.test(candidate)) {
      throw new DashlessError("indexnow_key_invalid", "indexnow-key.txt must contain one 8–128 character IndexNow key.");
    }
    indexNowKey = candidate;
  }
  const manifest = {
    version: 1,
    release_id: releaseId,
    public_host: new URL(deployment.public_url).hostname.toLowerCase(),
    ...(generation !== null ? { content_generation: generation } : {}),
    ...(indexNowKey ? { indexnow_key: indexNowKey } : {}),
    files: entries,
  };
  await writeFile(path.join(source, "dashless-release.json"), `${JSON.stringify(manifest, null, 2)}\n`, { mode: 0o644 });
  return manifest;
}

function parentDirectories(target, stopAt) {
  const result = [];
  let current = path.posix.normalize(target);
  const stop = path.posix.normalize(stopAt);
  while (current.startsWith(`${stop}/`) && current !== stop) {
    result.unshift(current);
    current = path.posix.dirname(current);
  }
  return result;
}

export async function buildWpCloudSftpBatch({ distPath, deployment, releaseId, bridgeMode = "install", contentGeneration = null }) {
  const source = path.resolve(distPath);
  const release = path.posix.join(deployment.releases_path, "releases", releaseId);
  const muPlugins = path.posix.join(deployment.htdocs_path, "wp-content", "mu-plugins");
  const remoteBridge = path.posix.join(muPlugins, "dashless-wpcloud.php");
  const stagedBridgeName = `dashless-wpcloud-${releaseId}.stage`;
  const stagedBridge = path.posix.join(muPlugins, stagedBridgeName);
  await createWpCloudReleaseManifest({ distPath: source, deployment, releaseId, contentGeneration });
  const { directories, files } = await localTree(source);
  const commands = [];

  for (const directory of parentDirectories(muPlugins, deployment.htdocs_path)) commands.push(`-mkdir ${sftpQuote(directory)}`);
  if (bridgeMode !== "skip") commands.push(`put ${sftpQuote(wpCloudBridge)} ${sftpQuote(stagedBridge)}`);
  for (const directory of parentDirectories(release, deployment.htdocs_path)) commands.push(`-mkdir ${sftpQuote(directory)}`);
  for (const directory of directories) commands.push(`-mkdir ${sftpQuote(path.posix.join(release, directory.split(path.sep).join("/")))}`);
  for (const file of files) {
    commands.push(`put ${sftpQuote(path.join(source, file))} ${sftpQuote(path.posix.join(release, file.split(path.sep).join("/")))}`);
  }

  return { batch: `${commands.join("\n")}\n`, release, remoteBridge, stagedBridge, stagedBridgeName, fileCount: files.length, bridgeMode };
}

function sftpArguments(deployment, batchPath) {
  const target = `${deployment.user}@${deployment.host}`;
  const args = [
    "-b", batchPath,
    "-oBatchMode=yes",
    "-oConnectTimeout=15",
    "-oServerAliveInterval=15",
    "-oServerAliveCountMax=3",
    "-P", String(deployment.port),
  ];
  if (deployment.identity_file) args.push("-i", deployment.identity_file);
  args.push(target);
  return { args, target };
}

async function runSftpBatch({ deployment, batch, name, attempts = 1 }) {
  const batchPath = dataPath("runtime", `${name}.sftp`);
  await mkdir(path.dirname(batchPath), { recursive: true, mode: 0o700 });
  await writeFile(batchPath, batch, { mode: 0o600 });
  const { args, target } = sftpArguments(deployment, batchPath);
  try {
    let lastError;
    for (let attempt = 1; attempt <= attempts; attempt += 1) {
      try {
        await run("sftp", args, { timeoutMs: 10 * 60 * 1000 });
        return { target, attempts: attempt };
      } catch (error) {
        lastError = error;
      }
    }
    throw lastError;
  } finally {
    await rm(batchPath, { force: true });
  }
}

async function uploadWpCloudRelease(distPath, deployment, id, bridgeMode, contentGeneration = null) {
  if (!/^[0-9T]+Z-[a-f0-9]{6}$/.test(id)) {
    throw new DashlessError("wpcloud_release_id_invalid", "WP Cloud release identifiers must be generated by Dashless.");
  }
  const source = path.resolve(distPath);
  const prepared = await buildWpCloudSftpBatch({ distPath: source, deployment, releaseId: id, bridgeMode, contentGeneration });
  let transfer;
  try {
    transfer = await runSftpBatch({ deployment, batch: prepared.batch, name: `wpcloud-upload-${id}`, attempts: 3 });
  } catch (error) {
    throw new DashlessError("wpcloud_upload_failed", "WP Cloud SFTP upload failed after three retry attempts. The prior release remains live.", {
      cause: error.message,
      file_count: prepared.fileCount,
    });
  }
  return {
    release_id: id,
    release_path: prepared.release,
    bridge_path: prepared.remoteBridge,
    staged_bridge_path: bridgeMode === "skip" ? null : prepared.stagedBridge,
    staged_bridge_name: bridgeMode === "skip" ? null : prepared.stagedBridgeName,
    host: transfer.target,
    transport: "sftp",
    transfer_attempts: transfer.attempts,
    file_count: prepared.fileCount,
  };
}

async function activateWpCloudRelease({ deployment, id, site, password, contentGeneration = null }) {
  const generation = normalizedContentGeneration(contentGeneration);
  const body = await wpCloudBridgeRequest({
    site,
    password,
    route: "release/activate",
    body: {
      release_id: id,
      public_url: deployment.public_url,
      ...(generation !== null ? { content_generation: generation } : {}),
    },
  });
  if (!body?.activated) throw new DashlessError("wpcloud_activation_failed", "The release uploaded, but WordPress did not confirm activation. The previous release remains live.");
  return body;
}

async function wpCloudBridgeRequest({ site, password, route, method = "POST", body = null }) {
  if (!site?.site_url || !site?.username || !password) {
    throw new DashlessError("wpcloud_activation_credentials_missing", "WP Cloud release operations require the connected WordPress Application Password.");
  }
  const endpoint = `${site.site_url.replace(/\/$/, "")}/wp-json/dashless/v1/${route.replace(/^\//, "")}`;
  const response = await fetch(endpoint, {
    method,
    signal: AbortSignal.timeout(30_000),
    headers: {
      Authorization: `Basic ${Buffer.from(`${site.username}:${password}`, "utf8").toString("base64")}`,
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });
  let value;
  try { value = await response.json(); } catch { value = null; }
  if (!response.ok) {
    throw new DashlessError("wpcloud_bridge_request_failed", `The WP Cloud bridge refused ${route} (${response.status}).`, {
      status: response.status,
      wordpress_code: value?.code || null,
      wordpress_message: value?.message || null,
    });
  }
  return value;
}

async function inspectWpCloudBridge({ site, password }) {
  try {
    const status = await wpCloudBridgeRequest({ site, password, route: "release", method: "GET" });
    return status?.bridge ? { installed: true, ...status } : { installed: false };
  } catch (error) {
    if (error.details?.status === 404) return { installed: false };
    throw error;
  }
}

function compareVersions(left, right) {
  const a = String(left).split(".").map(Number);
  const b = String(right).split(".").map(Number);
  for (let index = 0; index < 3; index += 1) {
    if ((a[index] || 0) !== (b[index] || 0)) return (a[index] || 0) - (b[index] || 0);
  }
  return 0;
}

async function wpCloudBridgeDescriptor() {
  const source = await readFile(wpCloudBridge, "utf8");
  const version = source.match(/^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$/m)?.[1];
  if (!version) throw new DashlessError("wpcloud_bridge_invalid", "The packaged WP Cloud bridge has no valid version header.");
  return { version, sha256: await hashFile(wpCloudBridge) };
}

async function prepareWpCloudBridge({ site, password }) {
  const [descriptor, status] = await Promise.all([
    wpCloudBridgeDescriptor(),
    inspectWpCloudBridge({ site, password }),
  ]);
  if (!status.installed) return { mode: "install", descriptor, status };
  if (!/^\d+\.\d+\.\d+$/.test(status.bridge)) throw new DashlessError("wpcloud_bridge_version_invalid", "The installed WP Cloud bridge reported an invalid version.");
  if (status.bridge === descriptor.version && status.bridge_sha256 === descriptor.sha256) return { mode: "skip", descriptor, status };
  if (compareVersions(status.bridge, descriptor.version) > 0) {
    throw new DashlessError("wpcloud_bridge_newer", `WP Cloud already has newer Dashless bridge ${status.bridge}; this plugin will not downgrade it to ${descriptor.version}.`);
  }
  return { mode: "upgrade", descriptor, status };
}

async function finalizeWpCloudBridge({ deployment, site, password, plan, uploaded }) {
  if (plan.mode === "install") {
    try {
      await runSftpBatch({
        deployment,
        batch: `rename ${sftpQuote(uploaded.staged_bridge_path)} ${sftpQuote(uploaded.bridge_path)}\n`,
        name: `wpcloud-bridge-install-${uploaded.release_id}`,
      });
    } catch (error) {
      const status = await inspectWpCloudBridge({ site, password }).catch(() => ({ installed: false }));
      if (!status.installed || status.bridge !== plan.descriptor.version) {
        throw new DashlessError("wpcloud_bridge_install_failed", "The bridge could not be atomically installed. The prior WordPress site remains unchanged.", { cause: error.message });
      }
    }
  } else if (plan.mode === "upgrade") {
    let result;
    try {
      result = await wpCloudBridgeRequest({
        site,
        password,
        route: "bridge/upgrade",
        body: {
          staged_name: uploaded.staged_bridge_name,
          sha256: plan.descriptor.sha256,
          version: plan.descriptor.version,
        },
      });
    } catch (error) {
      throw new DashlessError("wpcloud_bridge_upgrade_failed", "The installed bridge cannot perform a safe atomic upgrade. The prior bridge and public release remain live.", { cause: error.message });
    }
    if (!result?.upgraded) throw new DashlessError("wpcloud_bridge_upgrade_failed", "WordPress did not confirm the atomic bridge upgrade.");
  }
  const verified = await inspectWpCloudBridge({ site, password });
  if (!verified.installed || verified.bridge !== plan.descriptor.version || verified.bridge_sha256 !== plan.descriptor.sha256) {
    throw new DashlessError("wpcloud_bridge_version_mismatch", `Expected WP Cloud bridge ${plan.descriptor.version}, but WordPress reported ${verified.bridge || "none"}.`);
  }
  return { mode: plan.mode, version: verified.bridge, content_version: verified.content_version || null };
}

export async function checkWpCloudDeployment({ deployment, site, password }) {
  if (deployment?.kind !== "wpcloud") throw new DashlessError("wpcloud_deployment_required", "WP Cloud diagnostics require a WP Cloud deployment configuration.");
  if (deployment.identity_file && !(await lstat(deployment.identity_file).catch(() => null))?.isFile()) {
    throw new DashlessError("wpcloud_identity_unavailable", "The configured SSH identity file does not exist or is not a regular file.");
  }
  let sftp;
  try {
    sftp = await runSftpBatch({
      deployment,
      batch: `pwd\nls ${sftpQuote(path.posix.join(deployment.htdocs_path, "wp-content"))}\n`,
      name: `wpcloud-check-${createReleaseId()}`,
    });
  } catch (error) {
    throw new DashlessError(
      "wpcloud_sftp_unavailable",
      "Dashless could not open key-based SFTP or read the WP Cloud wp-content directory. Check the site username, key, host, and trusted host fingerprint.",
      { cause: error.message },
    );
  }
  const [descriptor, bridge] = await Promise.all([
    wpCloudBridgeDescriptor(),
    inspectWpCloudBridge({ site, password }),
  ]);
  const sameHost = new URL(site.site_url).hostname.toLowerCase().replace(/\.$/, "") === new URL(deployment.public_url).hostname.toLowerCase().replace(/\.$/, "");
  const bridgeNewer = bridge.installed && /^\d+\.\d+\.\d+$/.test(bridge.bridge) && compareVersions(bridge.bridge, descriptor.version) > 0;
  return {
    ready: !bridgeNewer,
    sftp: { reachable: true, host: sftp.target, htdocs_path: deployment.htdocs_path },
    wordpress_rest: { reachable: true, site_url: site.site_url },
    bridge: {
      installed: bridge.installed,
      installed_version: bridge.bridge || null,
      expected_version: descriptor.version,
      integrity_matches: bridge.installed ? bridge.bridge_sha256 === descriptor.sha256 : null,
      action: !bridge.installed ? "install" : bridge.bridge === descriptor.version && bridge.bridge_sha256 === descriptor.sha256 ? "none" : bridgeNewer ? "blocked_newer_version" : "upgrade",
    },
    routing: {
      mode: sameHost ? "single_domain" : "public_alias",
      public_url: deployment.public_url,
      alias_requirement: sameHost ? null : "WP Cloud must serve this alias directly with canonicalize_aliases disabled.",
      static_file_404_requirement: "Keep WP Cloud static_file_404 set to wordpress (the platform default).",
    },
  };
}

export async function rollbackWpCloudRelease({ deployment, site, password, expectedReleaseId = null }) {
  if (deployment?.kind !== "wpcloud") throw new DashlessError("wpcloud_deployment_required", "Rollback is available only for WP Cloud deployments.");
  if (expectedReleaseId) {
    const status = await inspectWpCloudBridge({ site, password });
    if (status.release_id !== expectedReleaseId) {
      throw new DashlessError(
        "wpcloud_rollback_target_changed",
        "The active WP Cloud release changed before automatic rollback, so Dashless did not roll back a different release.",
        { expected_release_id: expectedReleaseId, active_release_id: status.release_id || null },
      );
    }
  }
  const result = await wpCloudBridgeRequest({
    site,
    password,
    route: "release/rollback",
    ...(expectedReleaseId ? { body: { expected_release_id: expectedReleaseId } } : {}),
  });
  if (!result?.rolled_back || !result?.release_id) {
    throw new DashlessError("wpcloud_rollback_failed", "WordPress did not confirm the WP Cloud rollback.");
  }
  return result;
}

export function wpCloudReleasePrefix(deployment, id) {
  return `${deployment.public_url}/wp-content/uploads/dashless/releases/${id}`;
}

export async function deployFrontend({ distPath, deployment, releaseId = null, site = null, password = null, contentGeneration = null }) {
  const id = releaseId || createReleaseId();
  if (deployment.kind === "local") return deployLocal(distPath, deployment, id);
  if (deployment.kind === "ssh") return deploySsh(distPath, deployment, id);
  await createWpCloudReleaseManifest({ distPath, deployment, releaseId: id, contentGeneration });
  const bridgePlan = await prepareWpCloudBridge({ site, password });
  const uploaded = await uploadWpCloudRelease(distPath, deployment, id, bridgePlan.mode, contentGeneration);
  const bridge = await finalizeWpCloudBridge({ deployment, site, password, plan: bridgePlan, uploaded });
  const generation = assertContentGenerationMatches(contentGeneration, bridge.content_version?.generation ?? null, "pre_activation");
  const activation = await activateWpCloudRelease({ deployment, id, site, password, contentGeneration });
  return { ...uploaded, bridge, activation, content_generation: generation, active: true };
}

export async function verifyPublicDigest({ url, digest = null, releaseId = null, contentGeneration = null, attempts = 12 }) {
  const expectedGeneration = normalizedContentGeneration(contentGeneration);
  if (!digest && !releaseId && expectedGeneration === null) throw new DashlessError("verification_target_missing", "Public verification requires a content digest, release ID, or content generation.");
  let last = null;
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    try {
      const probeUrl = new URL(url);
      probeUrl.searchParams.set("dashless_verify", `${releaseId || digest?.slice(0, 16) || "release"}-${attempt + 1}`);
      const response = await fetch(probeUrl, { redirect: "follow", headers: { "Cache-Control": "no-cache" }, signal: AbortSignal.timeout(15_000) });
      const html = await response.text();
      const match = html.match(/<meta\s+name=["']dashless-content-digest["']\s+content=["']([a-f0-9]{64})["']/i)
        || html.match(/<meta\s+content=["']([a-f0-9]{64})["']\s+name=["']dashless-content-digest["']/i);
      const foundReleaseId = response.headers.get("x-dashless-release");
      const foundGeneration = normalizedContentGeneration(response.headers.get("x-dashless-content-generation"));
      const digestMatches = !digest || match?.[1] === digest;
      const releaseMatches = !releaseId || foundReleaseId === releaseId;
      const generationMatches = expectedGeneration === null || foundGeneration === expectedGeneration;
      last = { status: response.status, found_digest: match?.[1] || null, found_release_id: foundReleaseId, found_content_generation: foundGeneration };
      if (response.ok && digestMatches && releaseMatches && generationMatches) {
        return { verified: true, url, status: response.status, digest, release_id: releaseId, content_generation: expectedGeneration };
      }
    } catch (error) {
      last = { error: error.message };
    }
    if (attempt + 1 < attempts) await new Promise((resolve) => setTimeout(resolve, 500 * (attempt + 1)));
  }
  return { verified: false, url, expected_digest: digest, expected_release_id: releaseId, expected_content_generation: expectedGeneration, last };
}
