import { createHash, randomUUID } from "node:crypto";
import { copyFile, mkdir, readFile, rename, rm, writeFile } from "node:fs/promises";
import path from "node:path";

const digest = (bytes) => createHash("sha256").update(bytes).digest("hex");
const raster = /\.(?:png|jpe?g|webp|gif|avif|tiff?)$/i;

async function validateMedia(bytes, url) {
  if (!bytes.length) throw new Error("Empty media response");
  if (raster.test(new URL(url).pathname)) {
    const { default: sharp } = await import("sharp");
    // Metadata alone accepts some truncated images; decode the pixels before caching.
    await sharp(bytes).raw().toBuffer();
  }
}

async function atomicWrite(file, bytes) {
  await mkdir(path.dirname(file), { recursive: true });
  const temporary = `${file}.${randomUUID()}.tmp`;
  try {
    await writeFile(temporary, bytes);
    await rename(temporary, file);
  } finally {
    await rm(temporary, { force: true });
  }
}

async function readCached(file) {
  try {
    const [bytes, text] = await Promise.all([readFile(file), readFile(`${file}.json`, "utf8")]);
    const metadata = JSON.parse(text);
    if (bytes.length && metadata.sha256 === digest(bytes)) return { bytes, metadata };
  } catch { /* Missing, partial, or altered cache: fetch a fresh copy. */ }
  return null;
}

/** A per-build request cache backed by validated files outside the generated public tree. */
export function createMediaCache({ directory, fetchImpl = fetch, validate = validateMedia }) {
  const requests = new Map();
  const stats = { downloaded: 0, revalidated: 0, repaired: 0, downloaded_bytes: 0 };
  async function get(url) {
    const key = digest(url);
    const file = path.join(directory, key);
    const cached = await readCached(file);
    let lastError;
    for (let attempt = 0; attempt < 2; attempt += 1) {
      try {
        const headers = {};
        if (attempt === 0 && cached?.metadata.etag) headers["If-None-Match"] = cached.metadata.etag;
        else if (attempt === 0 && cached?.metadata.last_modified) headers["If-Modified-Since"] = cached.metadata.last_modified;
        const response = await fetchImpl(url, { headers, signal: AbortSignal.timeout(30_000) });
        if (response.status === 304 && cached) {
          stats.revalidated += 1;
          return { file, sha256: cached.metadata.sha256 };
        }
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const bytes = Buffer.from(await response.arrayBuffer());
        await validate(bytes, url);
        const metadata = { sha256: digest(bytes), etag: response.headers.get("etag"), last_modified: response.headers.get("last-modified") };
        await atomicWrite(file, bytes);
        await atomicWrite(`${file}.json`, JSON.stringify(metadata));
        stats.downloaded += 1;
        stats.downloaded_bytes += bytes.length;
        if (attempt) stats.repaired += 1;
        return { file, sha256: metadata.sha256 };
      } catch (error) { lastError = error; }
    }
    throw new Error(`Could not cache WordPress media ${url}: ${lastError.message}`, { cause: lastError });
  }
  return {
    stats,
    get(url) {
      if (!requests.has(url)) requests.set(url, get(url));
      return requests.get(url);
    },
  };
}

export const mediaCache = createMediaCache({ directory: path.join(process.cwd(), ".dashless-cache", "media") });

/** Resizes are keyed by source bytes, transform settings, and library version. */
export async function cachedImageVariant(sourcePath, width) {
  const { default: sharp } = await import("sharp");
  const source = await readFile(sourcePath);
  const key = digest(`${digest(source)}:${width}:webp:84:4:rotate:${sharp.versions.sharp}:${sharp.versions.vips}`);
  const file = path.join(process.cwd(), ".dashless-cache", "variants", `${key}.webp`);
  if (!(await readCached(file))) {
    const bytes = await sharp(source).rotate().resize({ width, withoutEnlargement: true }).webp({ quality: 84, effort: 4 }).toBuffer();
    await atomicWrite(file, bytes);
    await atomicWrite(`${file}.json`, JSON.stringify({ sha256: digest(bytes) }));
  }
  return file;
}

/** Publish a cache copy only once the whole file is present. */
export async function copyCachedFile(source, destination) {
  await mkdir(path.dirname(destination), { recursive: true });
  const temporary = `${destination}.${randomUUID()}.tmp`;
  try {
    await copyFile(source, temporary);
    await rename(temporary, destination);
  } finally {
    await rm(temporary, { force: true });
  }
}

if (process.env.DASHLESS_BUILD_METRICS === "1") {
  process.once("exit", () => console.log(`DASHLESS_MEDIA_CACHE ${JSON.stringify(mediaCache.stats)}`));
}
