import { execFileSync } from "node:child_process";
import { chmod, mkdir, readFile, rename, rm, writeFile } from "node:fs/promises";
import { homedir, platform } from "node:os";
import path from "node:path";
import { DashlessError } from "./errors.mjs";
import { newToken, safeId } from "./digest.mjs";

const SERVICE_NAME = "Dashless WordPress";

function defaultDataRoot() {
  if (process.env.DASHLESS_DATA_DIR) return path.resolve(process.env.DASHLESS_DATA_DIR);
  if (platform() === "darwin") {
    return path.join(homedir(), "Library", "Application Support", "Dashless");
  }
  if (platform() === "win32") {
    return path.join(process.env.LOCALAPPDATA || path.join(homedir(), "AppData", "Local"), "Dashless");
  }
  return path.join(process.env.XDG_DATA_HOME || path.join(homedir(), ".local", "share"), "dashless");
}

export const dataRoot = defaultDataRoot();
const configPath = path.join(dataRoot, "connections.json");
const fallbackSecretsPath = path.join(dataRoot, "secrets.json");

async function ensureDataRoot() {
  await mkdir(dataRoot, { recursive: true, mode: 0o700 });
  await chmod(dataRoot, 0o700).catch(() => {});
}

async function readJson(file, fallback) {
  try {
    return JSON.parse(await readFile(file, "utf8"));
  } catch (error) {
    if (error?.code === "ENOENT") return structuredClone(fallback);
    throw new DashlessError("local_data_invalid", `Dashless could not read ${file}: ${error.message}`);
  }
}

async function writeJsonAtomic(file, value, mode = 0o600) {
  await ensureDataRoot();
  const temporary = `${file}.${process.pid}.${newToken()}.tmp`;
  await writeFile(temporary, `${JSON.stringify(value, null, 2)}\n`, { mode });
  await chmod(temporary, mode).catch(() => {});
  await rename(temporary, file);
}

function keychainAccount(siteId) {
  return `dashless:${siteId}`;
}

function canUseKeychain() {
  if (process.env.DASHLESS_DISABLE_KEYCHAIN === "1") return false;
  if (platform() !== "darwin") return false;
  try {
    execFileSync("security", ["help"], { stdio: "ignore" });
    return true;
  } catch {
    return false;
  }
}

function saveKeychainSecret(siteId, password) {
  execFileSync(
    "security",
    [
      "add-generic-password",
      "-a",
      keychainAccount(siteId),
      "-s",
      SERVICE_NAME,
      "-w",
      password,
      "-U",
    ],
    { stdio: "ignore" },
  );
}

function readKeychainSecret(siteId) {
  return execFileSync(
    "security",
    ["find-generic-password", "-a", keychainAccount(siteId), "-s", SERVICE_NAME, "-w"],
    { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] },
  ).trim();
}

function deleteKeychainSecret(siteId) {
  try {
    execFileSync(
      "security",
      ["delete-generic-password", "-a", keychainAccount(siteId), "-s", SERVICE_NAME],
      { stdio: "ignore" },
    );
  } catch {
    // Already absent is the desired state.
  }
}

export function normalizeSiteUrl(value) {
  let parsed;
  try {
    parsed = new URL(value);
  } catch {
    throw new DashlessError("invalid_site_url", "WordPress site URL must be a valid absolute URL.");
  }
  if (parsed.protocol !== "https:" && !(parsed.protocol === "http:" && ["localhost", "127.0.0.1"].includes(parsed.hostname))) {
    throw new DashlessError("https_required", "Dashless requires HTTPS except for a loopback development site.");
  }
  parsed.hash = "";
  parsed.search = "";
  parsed.pathname = parsed.pathname
    .replace(/\/wp-json\/?$/i, "")
    .replace(/\/$/, "");
  return parsed.toString().replace(/\/$/, "");
}

export async function loadConnections() {
  const state = await readJson(configPath, { version: 1, active_site_id: null, sites: {}, idempotency: {} });
  state.sites ||= {};
  state.idempotency ||= {};
  return state;
}

export async function saveConnections(state) {
  await writeJsonAtomic(configPath, state);
}

async function saveFallbackSecret(siteId, password) {
  const secrets = await readJson(fallbackSecretsPath, { version: 1, passwords: {} });
  secrets.passwords[siteId] = password;
  await writeJsonAtomic(fallbackSecretsPath, secrets);
}

async function readFallbackSecret(siteId) {
  const secrets = await readJson(fallbackSecretsPath, { version: 1, passwords: {} });
  return secrets.passwords[siteId] || null;
}

async function deleteFallbackSecret(siteId) {
  const secrets = await readJson(fallbackSecretsPath, { version: 1, passwords: {} });
  delete secrets.passwords[siteId];
  await writeJsonAtomic(fallbackSecretsPath, secrets);
}

export async function saveSiteConnection({ siteUrl, username, password, siteName, userId, capabilities = {} }) {
  const normalizedUrl = normalizeSiteUrl(siteUrl);
  const siteId = safeId(normalizedUrl);
  const state = await loadConnections();
  const secretStorage = canUseKeychain() ? "keychain" : "file";

  try {
    if (secretStorage === "keychain") saveKeychainSecret(siteId, password);
    else await saveFallbackSecret(siteId, password);
  } catch (error) {
    throw new DashlessError("credential_storage_failed", `Dashless could not store the Application Password: ${error.message}`);
  }

  state.sites[siteId] = {
    ...(state.sites[siteId] || {}),
    id: siteId,
    site_url: normalizedUrl,
    site_name: siteName || new URL(normalizedUrl).hostname,
    username,
    user_id: userId || null,
    secret_storage: secretStorage,
    capabilities,
    connected_at: new Date().toISOString(),
  };
  state.active_site_id = siteId;
  await saveConnections(state);
  return structuredClone(state.sites[siteId]);
}

function envConnection() {
  const siteUrl = process.env.DASHLESS_WORDPRESS_URL;
  const username = process.env.DASHLESS_WORDPRESS_USERNAME;
  const password = process.env.DASHLESS_WORDPRESS_APP_PASSWORD;
  if (!siteUrl || !username || !password) return null;
  const normalizedUrl = normalizeSiteUrl(siteUrl);
  return {
    site: {
      id: `env-${safeId(normalizedUrl)}`,
      site_url: normalizedUrl,
      site_name: new URL(normalizedUrl).hostname,
      username,
      secret_storage: "environment",
      capabilities: {},
      ephemeral: true,
    },
    password,
  };
}

export async function getActiveConnection({ requireCredentials = true } = {}) {
  const environment = envConnection();
  if (environment) return environment;

  const state = await loadConnections();
  const site = state.active_site_id ? state.sites[state.active_site_id] : null;
  if (!site) {
    if (!requireCredentials) return null;
    throw new DashlessError("not_connected", "No WordPress site is connected. Run start_setup first.");
  }
  let password = null;
  if (requireCredentials) {
    try {
      password = site.secret_storage === "keychain" ? readKeychainSecret(site.id) : await readFallbackSecret(site.id);
    } catch (error) {
      throw new DashlessError("credential_unavailable", `The saved WordPress credential is unavailable: ${error.message}`);
    }
    if (!password) throw new DashlessError("credential_unavailable", "The saved WordPress credential is missing. Reconnect the site.");
  }
  return { site: structuredClone(site), password };
}

export async function updateActiveSite(patch) {
  const state = await loadConnections();
  const site = state.active_site_id ? state.sites[state.active_site_id] : null;
  if (!site) throw new DashlessError("not_connected", "No WordPress site is connected.");
  Object.assign(site, patch, { updated_at: new Date().toISOString() });
  await saveConnections(state);
  return structuredClone(site);
}

export async function disconnectActiveSite({ removeSiteData = true } = {}) {
  const state = await loadConnections();
  const siteId = state.active_site_id;
  if (!siteId || !state.sites[siteId]) return { disconnected: false };
  const site = state.sites[siteId];
  if (site.secret_storage === "keychain") deleteKeychainSecret(siteId);
  else await deleteFallbackSecret(siteId);
  delete state.sites[siteId];
  if (removeSiteData) {
    delete state.idempotency[siteId];
    for (const directory of ["changes", "previews", "preview-payloads"]) {
      await rm(dataPath(directory, siteId), { recursive: true, force: true });
    }
  }
  state.active_site_id = Object.keys(state.sites)[0] || null;
  await saveConnections(state);
  return { disconnected: true, site_id: siteId, local_site_data_removed: removeSiteData };
}

export async function lookupIdempotentPost(siteId, clientKey) {
  const state = await loadConnections();
  return state.idempotency?.[siteId]?.[clientKey] || null;
}

export async function rememberIdempotentPost(siteId, clientKey, record) {
  const state = await loadConnections();
  state.idempotency[siteId] ||= {};
  state.idempotency[siteId][clientKey] = record;
  await saveConnections(state);
}

export function dataPath(...parts) {
  return path.join(dataRoot, ...parts);
}

export async function readDataJson(relativePath, fallback = null) {
  return readJson(dataPath(relativePath), fallback);
}

export async function writeDataJson(relativePath, value) {
  const destination = dataPath(relativePath);
  await mkdir(path.dirname(destination), { recursive: true, mode: 0o700 });
  await writeJsonAtomic(destination, value);
  return destination;
}
