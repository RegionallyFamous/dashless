import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { contentDigest, newToken } from "./digest.mjs";
import { DashlessError } from "./errors.mjs";
import {
  dataPath,
  getActiveConnection,
  lookupIdempotentPost,
  rememberIdempotentPost,
  writeDataJson,
} from "./storage.mjs";
import { WordPressClient, mergeCanonical } from "./wordpress.mjs";

export async function activeClient() {
  const { site, password } = await getActiveConnection();
  return {
    site,
    password,
    client: new WordPressClient({ siteUrl: site.site_url, username: site.username, password }),
  };
}

function changePath(siteId, changeId) {
  return path.join("changes", siteId, `${changeId}.json`);
}

function previewPath(siteId, token) {
  return path.join("previews", siteId, `${token}.json`);
}

async function readRequiredJson(file, code, message) {
  try {
    return JSON.parse(await readFile(file, "utf8"));
  } catch (error) {
    if (error?.code === "ENOENT") throw new DashlessError(code, message);
    throw error;
  }
}

export async function createDraft({ postType, clientKey, ...input }) {
  const { site, client } = await activeClient();
  const remembered = await lookupIdempotentPost(site.id, clientKey);
  if (remembered) {
    try {
      const post = await client.getPost(remembered.post_type, remembered.id);
      return { ...post, idempotent_replay: true };
    } catch (error) {
      if (error?.details?.status !== 404) throw error;
    }
  }
  const post = await client.createDraft(postType, input);
  await rememberIdempotentPost(site.id, clientKey, { id: post.id, post_type: postType });
  return { ...post, idempotent_replay: false };
}

export async function stageUpdate({ postType, id, expectedModifiedGmt, changes, sourceRevisionId = null }) {
  const { site, client } = await activeClient();
  const current = await client.getPost(postType, id);
  if (current.modified_gmt !== expectedModifiedGmt) {
    throw new DashlessError("stale_post", "The WordPress item changed after it was read. Review the current version before staging changes.", {
      expected_modified_gmt: expectedModifiedGmt,
      current,
    });
  }
  const payload = mergeCanonical(current, changes, postType);
  const changeId = newToken();
  const record = {
    version: 1,
    id: changeId,
    site_id: site.id,
    post_type: postType,
    post_id: id,
    base_modified_gmt: current.modified_gmt,
    source_revision_id: sourceRevisionId,
    created_at: new Date().toISOString(),
    payload,
    digest: contentDigest(payload, postType),
  };
  await writeDataJson(changePath(site.id, changeId), record);
  return record;
}

export async function stageRevisionRestore({ postType, id, revisionId, expectedModifiedGmt }) {
  const { client } = await activeClient();
  const [current, revision] = await Promise.all([
    client.getPost(postType, id),
    client.getRevision(postType, id, revisionId),
  ]);
  if (current.modified_gmt !== expectedModifiedGmt) {
    throw new DashlessError("stale_post", "The WordPress item changed after it was read. Review the current version before staging a restoration.", {
      expected_modified_gmt: expectedModifiedGmt,
      current,
    });
  }
  return stageUpdate({
    postType,
    id,
    expectedModifiedGmt,
    sourceRevisionId: revisionId,
    changes: {
      title: revision.title?.raw ?? revision.title?.rendered ?? current.title,
      content: revision.content?.raw ?? revision.content?.rendered ?? current.content,
      excerpt: revision.excerpt?.raw ?? revision.excerpt?.rendered ?? current.excerpt,
    },
  });
}

export async function loadChange(siteId, changeId) {
  const change = await readRequiredJson(
    dataPath(changePath(siteId, changeId)),
    "change_not_found",
    "The staged change does not exist or has expired.",
  );
  if (change.site_id !== siteId) throw new DashlessError("change_site_mismatch", "The staged change belongs to another site.");
  return change;
}

export async function resolvePreviewPayload({ postType, id, changeId = null }) {
  const { site, client } = await activeClient();
  const current = await client.getPost(postType, id);
  if (!changeId) {
    return { site, client, current, payload: current, baseModifiedGmt: current.modified_gmt, change: null };
  }
  const change = await loadChange(site.id, changeId);
  if (change.post_type !== postType || change.post_id !== id) {
    throw new DashlessError("change_target_mismatch", "The staged change does not target this WordPress item.");
  }
  if (current.modified_gmt !== change.base_modified_gmt) {
    throw new DashlessError("stale_change", "WordPress changed after this edit was staged. Reconcile the parent before previewing.", {
      staged_against: change.base_modified_gmt,
      current,
    });
  }
  if (contentDigest(change.payload, postType) !== change.digest) {
    throw new DashlessError("change_corrupt", "The staged payload no longer matches its recorded digest.");
  }
  return {
    site,
    client,
    current,
    payload: change.payload,
    baseModifiedGmt: change.base_modified_gmt,
    change,
  };
}

export async function savePreviewLock({ siteId, postType, postId, slug, routePath = null, baseModifiedGmt, changeId, digest, projectPath, previewUrl }) {
  const token = newToken();
  const record = {
    version: 1,
    token,
    site_id: siteId,
    post_type: postType,
    post_id: postId,
    slug,
    route_path: routePath,
    base_modified_gmt: baseModifiedGmt,
    change_id: changeId,
    digest,
    project_path: projectPath,
    preview_url: previewUrl,
    built_at: new Date().toISOString(),
    used_at: null,
  };
  await writeDataJson(previewPath(siteId, token), record);
  return record;
}

export async function loadPreviewLock(token) {
  const { site } = await getActiveConnection();
  const lock = await readRequiredJson(
    dataPath(previewPath(site.id, token)),
    "preview_not_found",
    "The preview token does not exist or has expired.",
  );
  if (lock.site_id !== site.id) throw new DashlessError("preview_site_mismatch", "The preview belongs to another site.");
  if (lock.used_at) throw new DashlessError("preview_already_used", "This preview token was already published and cannot be reused.");
  return lock;
}

export async function markPreviewUsed(lock) {
  lock.used_at = new Date().toISOString();
  await writeDataJson(previewPath(lock.site_id, lock.token), lock);
}

export async function writePreviewPayload(payload, siteId, tokenHint = newToken()) {
  const directory = dataPath("preview-payloads", siteId);
  await mkdir(directory, { recursive: true, mode: 0o700 });
  const file = path.join(directory, `${tokenHint}.json`);
  await writeFile(file, `${JSON.stringify(payload)}\n`, { mode: 0o600 });
  return file;
}
