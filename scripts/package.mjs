#!/usr/bin/env node
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { cp, mkdir, mkdtemp, readFile, readdir, rm, stat, utimes, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { pathToFileURL, fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const codexVersion = "1.0.0";
const wordpressVersion = "1.0.0";
const dist = path.join(root, "dist");
const codexArchive = path.join(dist, `dashless-${codexVersion}.zip`);
const wordpressArchive = path.join(dist, `dashless-wordpress-${wordpressVersion}.zip`);
const checksumFile = path.join(dist, "SHA256SUMS");
const normalizedTime = new Date("2026-01-01T00:00:00.000Z");

async function listFiles(directory, relative = "") {
  const files = [];
  const entries = await readdir(path.join(directory, relative), { withFileTypes: true });
  for (const entry of entries.sort((left, right) => left.name.localeCompare(right.name))) {
    if (new Set([".DS_Store", ".git", "dist", "node_modules"]).has(entry.name)) continue;
    const child = path.join(relative, entry.name);
    if (entry.isDirectory()) files.push(...await listFiles(directory, child));
    else if (entry.isFile()) files.push(child.split(path.sep).join("/"));
    else throw new Error(`Release packaging refuses the unsupported entry ${child}.`);
  }
  return files;
}

async function normalizeTimes(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  for (const entry of entries) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) await normalizeTimes(absolute);
    await utimes(absolute, normalizedTime, normalizedTime);
  }
  await utimes(directory, normalizedTime, normalizedTime);
}

async function sha256(file) {
  return createHash("sha256").update(await readFile(file)).digest("hex");
}

async function zipDirectory(source, archive) {
  const files = await listFiles(source);
  if (!files.length) throw new Error(`No release files were found in ${source}.`);
  await rm(archive, { force: true });
  execFileSync("zip", ["-X", "-q", archive, "-@"], {
    cwd: source,
    input: `${files.join("\n")}\n`,
    stdio: ["pipe", "inherit", "inherit"],
  });
}

async function stageCodex(directory) {
  const inclusions = [
    ".codex-plugin",
    ".mcp.json",
    "CHANGELOG.md",
    "LICENSE",
    "README.md",
    "SECURITY.md",
    "docs",
    "package.json",
    "server",
    "skills",
    "templates",
    "wordpress",
  ];
  await mkdir(directory, { recursive: true });
  for (const inclusion of inclusions) {
    await cp(path.join(root, inclusion), path.join(directory, inclusion), { recursive: true });
  }
  const packagePath = path.join(directory, "package.json");
  const packageJson = JSON.parse(await readFile(packagePath, "utf8"));
  delete packageJson.scripts;
  await writeFile(packagePath, `${JSON.stringify(packageJson, null, 2)}\n`);
  await normalizeTimes(directory);
}

async function stageWordPress(directory) {
  const pluginDirectory = path.join(directory, "dashless");
  await mkdir(pluginDirectory, { recursive: true });
  await cp(path.join(root, "wordpress", "dashless-wpcloud.php"), path.join(pluginDirectory, "dashless.php"));
  await cp(path.join(root, "wordpress", "readme.txt"), path.join(pluginDirectory, "readme.txt"));
  await cp(path.join(root, "wordpress", "LICENSE"), path.join(pluginDirectory, "LICENSE"));
  await cp(path.join(root, "wordpress", "uninstall.php"), path.join(pluginDirectory, "uninstall.php"));
  await normalizeTimes(directory);
}

async function smokeArchives(staging) {
  execFileSync("unzip", ["-tq", codexArchive], { stdio: "inherit" });
  execFileSync("unzip", ["-tq", wordpressArchive], { stdio: "inherit" });

  const codexExtract = path.join(staging, "smoke-codex");
  const wordpressExtract = path.join(staging, "smoke-wordpress");
  await mkdir(codexExtract, { recursive: true });
  await mkdir(wordpressExtract, { recursive: true });
  execFileSync("unzip", ["-q", codexArchive, "-d", codexExtract]);
  execFileSync("unzip", ["-q", wordpressArchive, "-d", wordpressExtract]);

  const manifest = JSON.parse(await readFile(path.join(codexExtract, ".codex-plugin", "plugin.json"), "utf8"));
  if (manifest.name !== "dashless" || manifest.version !== codexVersion) throw new Error("The packaged Codex manifest is invalid.");
  const moduleUrl = `${pathToFileURL(path.join(codexExtract, "server", "dashless-mcp.mjs")).href}?smoke=${Date.now()}`;
  const server = await import(moduleUrl);
  const initialized = await server.handleRequest({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2025-06-18" } });
  const listed = await server.handleRequest({ jsonrpc: "2.0", id: 2, method: "tools/list", params: {} });
  if (initialized.result?.serverInfo?.version !== codexVersion || listed.result?.tools?.length < 20) {
    throw new Error("The clean packaged MCP server failed its initialization smoke test.");
  }

  const wordpressSource = await readFile(path.join(wordpressExtract, "dashless", "dashless.php"), "utf8");
  if (!wordpressSource.includes(`Version: ${wordpressVersion}`)) throw new Error("The packaged WordPress version is invalid.");
  await stat(path.join(wordpressExtract, "dashless", "LICENSE"));
}

await mkdir(dist, { recursive: true });
await rm(codexArchive, { force: true });
await rm(wordpressArchive, { force: true });
await rm(checksumFile, { force: true });

const staging = await mkdtemp(path.join(tmpdir(), "dashless-release-"));
try {
  const codexStage = path.join(staging, "codex");
  const wordpressStage = path.join(staging, "wordpress");
  await stageCodex(codexStage);
  await stageWordPress(wordpressStage);
  await zipDirectory(codexStage, codexArchive);
  await zipDirectory(wordpressStage, wordpressArchive);

  const codexProbe = path.join(staging, "codex-probe.zip");
  const wordpressProbe = path.join(staging, "wordpress-probe.zip");
  await zipDirectory(codexStage, codexProbe);
  await zipDirectory(wordpressStage, wordpressProbe);
  const [codexHash, codexProbeHash, wordpressHash, wordpressProbeHash] = await Promise.all([
    sha256(codexArchive),
    sha256(codexProbe),
    sha256(wordpressArchive),
    sha256(wordpressProbe),
  ]);
  if (codexHash !== codexProbeHash || wordpressHash !== wordpressProbeHash) {
    throw new Error("Release archives were not reproducible within the same build.");
  }

  await writeFile(checksumFile, `${codexHash}  ${path.basename(codexArchive)}\n${wordpressHash}  ${path.basename(wordpressArchive)}\n`);
  await smokeArchives(staging);
} finally {
  await rm(staging, { recursive: true, force: true });
}

process.stdout.write(`${codexArchive}\n${wordpressArchive}\n${checksumFile}\n`);
