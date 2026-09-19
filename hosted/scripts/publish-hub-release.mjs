import { cp, mkdtemp, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import os from "node:os";
import path from "node:path";
import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";
import { cleanupBatch, releaseIdsFromListing, releasesToCleanup } from "./cleanup-hub-releases.mjs";
import { verifyReleaseDirectory } from "../../scripts/check-theme-release.mjs";

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const deploymentContract = JSON.parse(await readFile(path.join(repo, "config/deployment-contract.json"), "utf8"));
const hub = path.join(repo, "hosted/hub-frontend");
const previewSource = path.join(repo, "wordpress/hosted/theme-previews");
const previewTargets = [
  path.join(repo, "hosted/theme/assets"),
  path.join(hub, "src/assets"),
];
const catalog = JSON.parse(await readFile(path.join(repo, "templates/astro/src/lib/themes.json"), "utf8"));
const themeIds = catalog.map(({ id }) => id);
const demoSlug = (id) => id === "after-hours" ? "afterhours" : id;
const demoSlugs = themeIds.map(demoSlug);
const origin = (process.env.DASHLESS_RELEASE_ORIGIN || "https://dashless.blog").replace(/\/$/, "");
const sshKey = process.env.DASHLESS_HUB_SSH_KEY || path.join(os.homedir(), ".config/dashless/hub/deploy_ed25519");
const sshTarget = process.env.DASHLESS_HUB_SSH_TARGET || "dashlessdeploy@sftp.wp.com";
const sshPort = process.env.DASHLESS_HUB_SSH_PORT || "22";
const remoteRoot = process.env.DASHLESS_HUB_REMOTE_ROOT || "/htdocs/wp-content/uploads/dashless/releases";
const apply = process.argv.includes("--apply");
const cleanupOnly = process.argv.includes("--cleanup-only");
const allowNonProductionTarget = process.env.DASHLESS_ALLOW_NONPROD_TARGET === "1";

export function deploymentTarget() {
  return { origin, sshKey, sshTarget, sshPort, remoteRoot };
}

function assertDeploymentTarget() {
  const hostname = new URL(origin).hostname;
  const expected = deploymentContract.canonical_hub;
  if (allowNonProductionTarget) return;
  if (origin !== expected.origin || hostname !== expected.host) {
    throw new Error(`Refusing deployment: ${origin} is not the canonical Dashless Hub origin; set DASHLESS_ALLOW_NONPROD_TARGET=1 only for an intentional staging target`);
  }
  if (sshTarget !== expected.ssh_target || remoteRoot !== expected.remote_root) {
    throw new Error(`Refusing deployment: WP Cloud target is ${sshTarget} / ${remoteRoot}, expected ${expected.ssh_target} / ${expected.remote_root}`);
  }
}

const run = (command, args, cwd = repo) => new Promise((resolve, reject) => {
  const child = spawn(command, args, { cwd, stdio: "inherit" });
  child.on("error", reject);
  child.on("close", code => code ? reject(new Error(`${command} exited with ${code}`)) : resolve());
});

const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

function sftp(commands) {
  return new Promise((resolve, reject) => {
    const child = spawn("sftp", ["-b", "-", "-P", sshPort, "-oBatchMode=yes", "-oStrictHostKeyChecking=yes", "-i", sshKey, sshTarget], { stdio: ["pipe", "pipe", "pipe"] });
    let stdout = "";
    let stderr = "";
    child.stdout.on("data", chunk => { stdout += chunk; });
    child.stderr.on("data", chunk => { stderr += chunk; });
    // OpenSSH may close stdin after accepting the final batch command. This
    // is harmless when the process exits successfully, but Node otherwise
    // treats the pipe error as an uncaught exception and can report a
    // verified deployment as failed during cleanup.
    child.stdin.on("error", error => {
      if (error.code !== "EPIPE") stderr += String(error);
    });
    child.on("error", reject);
    child.on("close", code => code ? reject(new Error(`sftp exited with ${code}: ${stderr || stdout}`)) : resolve(stdout));
    child.stdin.end(`${commands.join("\n")}\n`);
  });
}

const quote = value => `"${value.replaceAll("\\", "\\\\").replaceAll('"', '\\"')}"`;

async function syncPreviews() {
  for (const theme of catalog) {
    const { id } = theme;
    const source = path.join(previewSource, theme.preview_image);
    for (const target of previewTargets) {
      const filename = target.endsWith("/assets") ? `theme-preview-${id}.png` : `${id}.png`;
      await cp(source, path.join(target, filename));
    }
  }
}

async function localFiles(root, relative = "") {
  const files = [];
  const directories = [];
  for (const entry of await readdir(path.join(root, relative), { withFileTypes: true })) {
    const child = relative ? `${relative}/${entry.name}` : entry.name;
    if (entry.isDirectory()) {
      directories.push(child);
      const nested = await localFiles(root, child);
      files.push(...nested.files);
      directories.push(...nested.directories);
    } else if (entry.isFile()) files.push(child);
  }
  return { files, directories };
}

async function buildArtifact() {
  assertDeploymentTarget();
  await syncPreviews();
  await run("npm", ["run", "build"], hub);
  await run(process.execPath, ["hosted/scripts/check-hub-frontend.mjs"]);
  await run("npm", ["ci", "--prefix", "templates/astro", "--ignore-scripts", "--no-audit", "--no-fund"]);
  await run(process.execPath, ["hosted/scripts/build-theme-demos.mjs"]);
  await run(process.execPath, ["hosted/scripts/make-hub-release.mjs"]);
  const manifest = JSON.parse(await readFile(path.join(repo, "hosted/dist/hub/release.json"), "utf8"));
  const releaseRoot = path.join(repo, "hosted/dist/hub", manifest.release_id);
  await verifyReleaseDirectory(releaseRoot);
  const files = await localFiles(releaseRoot);
  return { manifest, releaseRoot, files };
}

export async function verifyLive(releaseId, manifest = null) {
  const headers = { "cache-control": "no-cache", pragma: "no-cache", "X-Dashless-Verify": releaseId };
  const manifestHashes = new Map((manifest?.files || []).map(entry => [entry.path, entry.sha256]));
  const cleanPaths = new Set();
  const verifyClean = async (pathname, label) => {
    let last = "no response";
    for (let attempt = 0; attempt < 5; attempt += 1) {
      const response = await fetch(`${origin}${pathname}`, { headers });
      last = `${response.status} / ${response.headers.get("x-dashless-release") || "none"}`;
      if (response.status === 200 && response.headers.get("x-dashless-release") === releaseId) return;
      if (attempt < 4) await wait(1000);
    }
    throw new Error(`clean live ${label} did not converge to ${releaseId}: ${last}`);
  };
  const verifyAsset = async (pathname, label) => {
    const expected = manifestHashes.get(pathname.replace(/^\/+/, ""));
    if (!expected) throw new Error(`live asset is not listed in release manifest: ${label}`);
    let last = "no response";
    for (let attempt = 0; attempt < 5; attempt += 1) {
      const response = await fetch(`${origin}${pathname}`, { headers });
      const bytes = Buffer.from(await response.arrayBuffer());
      const actual = createHash("sha256").update(bytes).digest("hex");
      last = `${response.status} / ${response.headers.get("x-dashless-release") || "none"} / ${actual}`;
      if (response.status === 200 && /^image\/png/i.test(response.headers.get("content-type") || "") && actual === expected) return;
      if (attempt < 4) await wait(1000);
    }
    throw new Error(`clean live asset ${label} did not match the release manifest: ${last}`);
  };
  const page = await fetch(`${origin}/themes/?dashless_verify=${releaseId}`, { headers });
  const html = await page.text();
  if (page.status !== 200 || page.headers.get("x-dashless-release") !== releaseId) {
    throw new Error(`live Hub served status ${page.status} / release ${page.headers.get("x-dashless-release") || "none"}; expected ${releaseId}`);
  }
  cleanPaths.add("/themes/");
  for (const slug of demoSlugs) {
    const response = await fetch(`${origin}/demos/${slug}/?dashless_verify=${releaseId}`, { headers });
    const body = await response.text();
    if (response.status !== 200 || /Page not found/i.test(body)) throw new Error(`live demo failed: ${slug} (${response.status})`);
    if (response.headers.get("x-dashless-release") !== releaseId) {
      throw new Error(`live demo ${slug} served release ${response.headers.get("x-dashless-release") || "none"}; expected ${releaseId}`);
    }
    if (!html.includes(`/demos/${slug}/`)) throw new Error(`themes page is missing demo link: ${slug}`);
    cleanPaths.add(`/demos/${slug}/`);
  }
  for (const route of ["/", "/support/", "/privacy/", "/terms/"]) {
    const response = await fetch(`${origin}${route}?dashless_verify=${releaseId}`, { headers });
    const body = await response.text();
    if (response.status !== 200 || /WordPress\.com/i.test(body)) throw new Error(`live public page failed: ${route} (${response.status})`);
    if (response.headers.get("x-dashless-release") !== releaseId) {
      throw new Error(`live public page ${route} served release ${response.headers.get("x-dashless-release") || "none"}; expected ${releaseId}`);
    }
    if (!/mailto:|cdn-cgi\/l\/email-protection|__cf_email__/i.test(body)) throw new Error(`live public page is missing a support contact: ${route}`);
    cleanPaths.add(route);
  }
  const previewPaths = [];
  for (const id of themeIds) {
    const match = html.match(new RegExp(`(?:src|href)="([^"]*theme-preview-${id}[^\"]*\\.png)"`));
    if (!match) throw new Error(`themes page is missing preview asset: ${id}`);
    const assetPath = match[1].startsWith("http") ? match[1] : `${origin}${match[1].startsWith("/") ? "" : "/"}${match[1]}`;
    const response = await fetch(`${assetPath}?dashless_verify=${releaseId}`, { headers });
    const bytes = Buffer.from(await response.arrayBuffer());
    const expected = manifestHashes.get(new URL(assetPath).pathname.replace(/^\/+/, ""));
    const actual = createHash("sha256").update(bytes).digest("hex");
    if (response.status !== 200 || !/^image\/png/i.test(response.headers.get("content-type") || "")) {
      throw new Error(`live theme preview failed: ${id} (${response.status})`);
    }
    if (!expected || actual !== expected) throw new Error(`live theme preview ${id} does not match the release manifest`);
    previewPaths.push(new URL(assetPath).pathname);
  }
  for (const pathname of cleanPaths) await verifyClean(pathname, pathname);
  for (const pathname of previewPaths) await verifyAsset(pathname, pathname);
  return { verified: true, release_id: releaseId, demos: demoSlugs.length };
}

async function cleanupOldReleases(activeReleaseId, manifestsDir) {
  const listing = await sftp([`ls ${quote(remoteRoot)}`]);
  const ids = releaseIdsFromListing(listing, remoteRoot);
  const removable = releasesToCleanup(ids, activeReleaseId);
  const retained = ids
    .filter((id) => id !== activeReleaseId && !removable.includes(id))
    .sort();
  for (const id of [...removable, ...retained]) {
    await sftp([`get ${quote(`${remoteRoot}/${id}/dashless-release.json`)} ${quote(path.join(manifestsDir, `${id}.json`))}`]);
  }
  // Validate the retained rollback candidate with the same manifest/path
  // checks used for deletion, but never emit or execute its commands.
  for (const id of retained) {
    cleanupBatch({ listing, activeReleaseId, remoteRoot, manifestsDir, onlyReleaseId: id });
  }
  const removed = [];
  for (const id of removable) {
    const batch = cleanupBatch({ listing, activeReleaseId, remoteRoot, manifestsDir, onlyReleaseId: id, continueOnError: true });
    if (batch) {
      await sftp(batch.split("\n"));
      removed.push(id);
    }
  }
  return { removed, retained };
}

export async function publish() {
  assertDeploymentTarget();
  if (cleanupOnly) {
    if (!apply) throw new Error("Cleanup-only mode requires --apply");
    const temporary = await mkdtemp(path.join(os.tmpdir(), "dashless-hub-cleanup-"));
    try {
      const pointer = path.join(temporary, "current.json");
      await sftp([`get ${quote(`${remoteRoot}/current.json`)} ${quote(pointer)}`]);
      const activeReleaseId = JSON.parse(await readFile(pointer, "utf8")).release_id;
      console.log(JSON.stringify({ cleanup: await cleanupOldReleases(activeReleaseId, temporary), active_release_id: activeReleaseId }, null, 2));
    } finally {
      await rm(temporary, { recursive: true, force: true });
    }
    return;
  }
  const artifact = await buildArtifact();
  console.log(JSON.stringify({ mode: apply ? "publish" : "dry-run", origin, release_id: artifact.manifest.release_id, files: artifact.files.files.length, demos: demoSlugs.length }, null, 2));
  if (!apply) {
    console.log("Dry run complete. Re-run with --apply to upload and activate this exact release.");
    return;
  }

  const releaseId = artifact.manifest.release_id;
  const remoteRelease = `${remoteRoot}/${releaseId}`;
  const temporary = await mkdtemp(path.join(os.tmpdir(), "dashless-hub-publish-"));
  const previousPointer = path.join(temporary, "current.json");
  try {
    await sftp([`get ${quote(`${remoteRoot}/current.json`)} ${quote(previousPointer)}`]);
    const commands = [
      `-mkdir ${quote(remoteRelease)}`,
      ...artifact.files.directories.sort().map(directory => `-mkdir ${quote(`${remoteRelease}/${directory}`)}`),
      ...artifact.files.files.sort().map(file => `put ${quote(path.join(artifact.releaseRoot, file))} ${quote(`${remoteRelease}/${file}`)}`),
      `put ${quote(path.join(repo, "hosted/dist/hub/current.json"))} ${quote(`${remoteRoot}/current.json.next`)}`,
      `rename ${quote(`${remoteRoot}/current.json.next`)} ${quote(`${remoteRoot}/current.json`)}`,
    ];
    await sftp(commands);
    try {
      console.log(JSON.stringify(await verifyLive(releaseId, artifact.manifest), null, 2));
    } catch (error) {
      await sftp([`put ${quote(previousPointer)} ${quote(`${remoteRoot}/current.json.rollback`)}`, `rename ${quote(`${remoteRoot}/current.json.rollback`)} ${quote(`${remoteRoot}/current.json`)}`]);
      throw new Error(`${error.message}; previous Hub pointer was restored`);
    }
    try {
      console.log(JSON.stringify({ cleanup: await cleanupOldReleases(releaseId, temporary) }, null, 2));
    } catch (error) {
      throw new Error(`release ${releaseId} is live, but superseded-release cleanup failed: ${error.message}`);
    }
  } finally {
    await rm(temporary, { recursive: true, force: true });
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  publish().then(() => process.exit(0)).catch(error => {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
  });
}
