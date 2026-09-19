#!/usr/bin/env node

import { createHash } from "node:crypto";
import { readFile, stat } from "node:fs/promises";
import path from "node:path";

const releaseIdPattern = /^\d{8}T\d{6,9}Z-[0-9a-f]{6}$/;

function fail(message) {
  throw new Error(`Theme release contract failed: ${message}`);
}

function argument(name) {
  const prefix = `${name}=`;
  const inline = process.argv.find((value) => value.startsWith(prefix));
  if (inline) return inline.slice(prefix.length) || null;
  const index = process.argv.indexOf(name);
  return index === -1 ? null : process.argv[index + 1] ?? null;
}

function safeRelativeFile(relative) {
  return typeof relative === "string"
    && relative.length > 0
    && !path.posix.isAbsolute(relative)
    && !relative.split("/").includes("..")
    && !relative.includes("\\");
}

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

async function sha256(file) {
  return createHash("sha256").update(await readFile(file)).digest("hex");
}

export async function verifyReleaseDirectory(directory) {
  const dist = path.resolve(directory);
  for (const required of ["index.html", "404.html", "dashless-release.json"]) {
    try {
      await stat(path.join(dist, required));
    } catch {
      fail(`release is missing ${required}`);
    }
  }

  const manifest = JSON.parse(await readFile(path.join(dist, "dashless-release.json"), "utf8"));
  if (![1, 2].includes(manifest.version)) fail("release manifest version is unsupported");
  if (!releaseIdPattern.test(manifest.release_id || "")) fail("release_id is not a generated release identifier");
  if (!manifest.public_host || !Array.isArray(manifest.files) || manifest.files.length === 0) {
    fail("release manifest is missing public_host or files");
  }
  if (manifest.version >= 2) {
    if (manifest.build_system !== "canonical-astro-hub-v1") fail("release manifest has an unknown build system");
    if (JSON.stringify(manifest.build_order) !== JSON.stringify(["hub-astro", "theme-demos", "immutable-release"])) {
      fail("release manifest has an invalid build order");
    }
  }

  const paths = new Set();
  for (const entry of manifest.files) {
    if (!entry || !safeRelativeFile(entry.path) || paths.has(entry.path)) {
      fail("release manifest contains a duplicate or unsafe file path");
    }
    if (!Number.isSafeInteger(entry.bytes) || entry.bytes < 0 || !/^[0-9a-f]{64}$/.test(entry.sha256 || "")) {
      fail(`release manifest entry is malformed: ${entry.path}`);
    }
    paths.add(entry.path);
    const file = path.join(dist, entry.path);
    const info = await stat(file).catch(() => null);
    if (!info?.isFile()) fail(`release manifest file is missing: ${entry.path}`);
    if (info.size !== entry.bytes) fail(`release manifest byte count is stale: ${entry.path}`);
    if (await sha256(file) !== entry.sha256) fail(`release manifest hash is stale: ${entry.path}`);
  }

  for (const required of ["index.html", "404.html"]) {
    if (!paths.has(required)) fail(`release manifest does not list ${required}`);
  }

  return { release_id: manifest.release_id, public_host: manifest.public_host, files: manifest.files.length };
}

export async function verifyLiveRelease({ url, releaseId, theme = null }) {
  if (!url || !releaseId) fail("live verification requires --url and --release-id");
  if (!releaseIdPattern.test(releaseId)) fail("live release_id is not a generated release identifier");
  const target = new URL(url);
  target.searchParams.set("dashless_theme_contract", `${releaseId}-${Date.now()}`);
  const response = await fetch(target, { headers: { "cache-control": "no-cache" }, redirect: "follow" });
  const html = await response.text();
  if (!response.ok) fail(`live page returned HTTP ${response.status}`);
  if (response.headers.get("x-dashless-release") !== releaseId) {
    fail(`live page served release ${response.headers.get("x-dashless-release") || "none"}, expected ${releaseId}`);
  }
  let liveTheme = null;
  if (theme) {
    const { chromium } = await import("playwright");
    const browser = await chromium.launch({ headless: true });
    try {
      const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
      await page.goto(target.href, { waitUntil: "networkidle" });
      liveTheme = await page.evaluate(() => document.documentElement.dataset.demoStyle
        || document.documentElement.dataset.design
        || document.documentElement.dataset.theme
        || null);
    } finally {
      await browser.close();
    }
    if (liveTheme !== theme && !new RegExp(`data-(?:theme|design)=["']${escapeRegex(theme)}(["'])`).test(html)) {
      fail(`live page exposed selected theme ${liveTheme || "none"}, expected ${theme}`);
    }
  }
  return { url: target.href, status: response.status, release_id: releaseId, theme, live_theme: liveTheme };
}

const dist = argument("--dist");
const url = argument("--url");
const releaseId = argument("--release-id");
const theme = argument("--theme");

if (import.meta.url === `file://${process.argv[1]}`) {
  try {
    const artifact = dist ? await verifyReleaseDirectory(dist) : null;
    const live = url || releaseId || theme ? await verifyLiveRelease({ url, releaseId, theme }) : null;
    if (!artifact && !live) fail("provide --dist for builder verification or --url/--release-id for live verification");
    console.log(JSON.stringify({ ok: true, artifact, live }, null, 2));
  } catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
  }
}
