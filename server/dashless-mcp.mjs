#!/usr/bin/env node
import { readFile } from "node:fs/promises";
import path from "node:path";
import {
  activeClient,
  createDraft as createDraftEditorial,
  loadChange,
  loadPreviewLock,
  markPreviewUsed,
  resolvePreviewPayload,
  savePreviewLock,
  stageRevisionRestore,
  stageUpdate,
  writePreviewPayload,
} from "./lib/editorial.mjs";
import { contentDigest } from "./lib/digest.mjs";
import { asDashlessError, DashlessError, requireInteger, requireString } from "./lib/errors.mjs";
import {
  buildFrontend,
  checkWpCloudDeployment,
  createReleaseId,
  createFrontend,
  deployFrontend,
  rollbackWpCloudRelease,
  startPreviewServer,
  updateFrontendPublicUrl,
  validateDeployment,
  verifyPublicDigest,
  wpCloudReleasePrefix,
} from "./lib/frontend.mjs";
import { startSetup } from "./lib/setup.mjs";
import {
  disconnectActiveSite,
  getActiveConnection,
  loadConnections,
  updateActiveSite,
} from "./lib/storage.mjs";

const postTypeSchema = { type: "string", enum: ["post", "page"], description: "WordPress core post type." };
const idSchema = { type: "integer", minimum: 1 };
const editorialFields = {
  title: { type: "string" },
  content: { type: "string", description: "Portable semantic HTML." },
  excerpt: { type: "string" },
  slug: { type: "string" },
  featured_media: { type: "integer", minimum: 0 },
  categories: { type: "array", items: idSchema },
  tags: { type: "array", items: idSchema },
  parent: { type: "integer", minimum: 0, description: "Parent Page ID. Used only for pages." },
  menu_order: { type: "integer", description: "Page navigation order. Used only for pages." },
};

function objectSchema(properties, required = []) {
  return { type: "object", properties, required, additionalProperties: false };
}

function tool(name, title, description, inputSchema, annotations, handler) {
  return { name, title, description, inputSchema, annotations, handler };
}

function routeFor(postType, slug, postsPath = "stories") {
  return postType === "post" ? `/${postsPath}/${slug}/` : `/${slug}/`;
}

function validationWarnings(payload) {
  const warnings = [];
  if (!payload.excerpt?.trim()) warnings.push({ code: "missing_excerpt", message: "No excerpt or dek is set." });
  if (!payload.featured_media) warnings.push({ code: "missing_featured_image", message: "No featured image is set." });
  const images = [...String(payload.content || "").matchAll(/<img\b([^>]*)>/gi)];
  const missingAlt = images.filter((match) => !/\balt\s*=\s*(["'])[^"']*\1/i.test(match[1])).length;
  if (missingAlt) warnings.push({ code: "missing_inline_alt", message: `${missingAlt} inline image${missingAlt === 1 ? " is" : "s are"} missing an alt attribute.` });
  return warnings;
}

async function safeStatus() {
  const environment = await getActiveConnection({ requireCredentials: false });
  const state = await loadConnections();
  const site = environment?.site || (state.active_site_id ? state.sites[state.active_site_id] : null);
  if (!site) {
    return { connected: false, setup_required: true, frontend: null, deployment: null };
  }
  let authenticated = false;
  let authentication_error = null;
  let inspection = null;
  try {
    const { client } = await activeClient();
    inspection = await client.inspectSite();
    authenticated = true;
  } catch (error) {
    authentication_error = { code: error.code || "connection_failed", message: error.message };
  }
  return {
    connected: true,
    authenticated,
    site: {
      id: site.id,
      url: site.site_url,
      name: inspection?.site_name || site.site_name,
      username: site.username,
      user_id: inspection?.user?.id || site.user_id,
      credential_storage: site.secret_storage,
      capabilities: inspection?.capabilities || site.capabilities || {},
      content_model: inspection?.content_model || null,
      content_version: inspection?.dashless_plugin?.content_version || null,
      wordpress_plugin_version: inspection?.dashless_plugin?.plugin_version || null,
    },
    frontend: site.frontend || null,
    deployment: site.deployment || null,
    content_sync: inspection?.dashless_plugin?.content_version ? {
      current_generation: inspection.dashless_plugin.content_version.generation,
      last_built_generation: site.frontend?.last_built_generation ?? null,
      last_deployed_generation: site.frontend?.last_deployed_generation ?? null,
      needs_build: site.frontend?.last_built_generation !== inspection.dashless_plugin.content_version.generation,
      needs_deploy: Boolean(site.deployment) && site.frontend?.last_deployed_generation !== inspection.dashless_plugin.content_version.generation,
    } : null,
    authentication_error,
  };
}

const tools = [
  tool(
    "get_status",
    "Get Dashless status",
    "Check the active WordPress connection, authentication, Astro frontend, and deployment configuration. Call this first.",
    objectSchema({}),
    { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
    safeStatus,
  ),
  tool(
    "inspect_site",
    "Inspect the WordPress site",
    "Read the WordPress content model, settings, content counts, Page hierarchy, generated route map, and Dashless companion status before creating a frontend.",
    objectSchema({}),
    { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
    async () => {
      const { client } = await activeClient();
      return client.inspectContent();
    },
  ),
  tool(
    "start_setup",
    "Connect WordPress",
    "Start a loopback-only browser setup page. The user enters their Application Password there so it never passes through chat.",
    objectSchema({ site_url: { type: "string", format: "uri" } }),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    ({ site_url }) => startSetup({ siteUrl: site_url || "" }),
  ),
  tool(
    "disconnect_site",
    "Disconnect WordPress",
    "Revoke the dedicated WordPress Application Password, remove the active connection, and erase its local Dashless staging records. The generated Astro project and WordPress content are preserved.",
    objectSchema({
      confirm: { type: "boolean", description: "Must be true after the user explicitly asks to disconnect." },
      revoke_application_password: { type: "boolean", default: true, description: "Revoke the credential in WordPress before deleting the local copy." },
      remove_local_data: { type: "boolean", default: true, description: "Erase this site's idempotency, staged-change, preview-lock, and preview-payload records." },
    }, ["confirm"]),
    { readOnlyHint: false, destructiveHint: true, openWorldHint: true },
    async ({ confirm, revoke_application_password, remove_local_data }) => {
      if (confirm !== true) throw new DashlessError("confirmation_required", "Explicit confirmation is required to disconnect the site.");
      let applicationPassword = { attempted: false, revoked: false };
      if (revoke_application_password !== false) {
        const { client } = await activeClient();
        applicationPassword = { attempted: true, ...(await client.revokeCurrentApplicationPassword()) };
      }
      const disconnected = await disconnectActiveSite({ removeSiteData: remove_local_data !== false });
      return { ...disconnected, application_password: applicationPassword };
    },
  ),
  tool(
    "list_posts",
    "List WordPress content",
    "List editable core posts or pages, including drafts and published items, ordered by most recently modified.",
    objectSchema({
      post_type: postTypeSchema,
      status: { type: "string", description: "Optional comma-separated WordPress statuses." },
      search: { type: "string" },
      page: { type: "integer", minimum: 1, default: 1 },
      per_page: { type: "integer", minimum: 1, maximum: 100, default: 20 },
    }, ["post_type"]),
    { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
    async ({ post_type, status, search, page, per_page }) => {
      const { client } = await activeClient();
      return client.listPosts({ postType: post_type, status, search, page, perPage: per_page });
    },
  ),
  tool(
    "get_post",
    "Get a WordPress item",
    "Read the raw editable fields, status, terms, media ID, revision count, digest, and modified timestamp for a post or page.",
    objectSchema({ post_type: postTypeSchema, id: idSchema }, ["post_type", "id"]),
    { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
    async ({ post_type, id }) => {
      const { client } = await activeClient();
      return client.getPost(post_type, requireInteger(id, "id"));
    },
  ),
  tool(
    "create_draft",
    "Create a WordPress draft",
    "Create a core post or page with status draft only after an explicit editorial request. Never use this tool to populate a design, preview, deployment, or launch. client_key makes retries idempotent and must remain stable for the same intended item.",
    objectSchema({
      post_type: postTypeSchema,
      client_key: { type: "string", minLength: 8, description: "Stable UUID-like key for this intended draft." },
      ...editorialFields,
    }, ["post_type", "client_key", "title", "content"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    ({ post_type, client_key, ...input }) => createDraftEditorial({ postType: post_type, clientKey: client_key, ...input }),
  ),
  tool(
    "update_draft",
    "Update a WordPress draft",
    "Update a draft, pending, or future item only. Refuses published parents and stale modified_gmt values.",
    objectSchema({
      post_type: postTypeSchema,
      id: idSchema,
      expected_modified_gmt: { type: "string", minLength: 1 },
      ...editorialFields,
    }, ["post_type", "id", "expected_modified_gmt"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    async ({ post_type, id, expected_modified_gmt, ...input }) => {
      const { client } = await activeClient();
      return client.updateDraft(post_type, id, input, expected_modified_gmt);
    },
  ),
  tool(
    "stage_update",
    "Stage a published revision",
    "Prepare a non-public changeset for an existing item without changing its WordPress parent. Use this for published content.",
    objectSchema({
      post_type: postTypeSchema,
      id: idSchema,
      expected_modified_gmt: { type: "string", minLength: 1 },
      changes: objectSchema(editorialFields),
    }, ["post_type", "id", "expected_modified_gmt", "changes"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    ({ post_type, id, expected_modified_gmt, changes }) => stageUpdate({ postType: post_type, id, expectedModifiedGmt: expected_modified_gmt, changes }),
  ),
  tool(
    "list_revisions",
    "List WordPress revisions",
    "List recent WordPress revisions for a core post or page.",
    objectSchema({ post_type: postTypeSchema, id: idSchema, per_page: { type: "integer", minimum: 1, maximum: 100, default: 20 } }, ["post_type", "id"]),
    { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
    async ({ post_type, id, per_page }) => {
      const { client } = await activeClient();
      return { revisions: await client.listRevisions(post_type, id, per_page) };
    },
  ),
  tool(
    "stage_revision_restore",
    "Stage a revision restoration",
    "Copy an old WordPress revision into a non-public changeset. It must still be previewed and explicitly published.",
    objectSchema({
      post_type: postTypeSchema,
      id: idSchema,
      revision_id: idSchema,
      expected_modified_gmt: { type: "string", minLength: 1 },
    }, ["post_type", "id", "revision_id", "expected_modified_gmt"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    ({ post_type, id, revision_id, expected_modified_gmt }) => stageRevisionRestore({ postType: post_type, id, revisionId: revision_id, expectedModifiedGmt: expected_modified_gmt }),
  ),
  tool(
    "list_terms",
    "List WordPress terms",
    "Find existing categories or tags and return stable term IDs.",
    objectSchema({ taxonomy: { type: "string", enum: ["category", "tag"] }, search: { type: "string" }, page: { type: "integer", minimum: 1 }, per_page: { type: "integer", minimum: 1, maximum: 100 } }, ["taxonomy"]),
    { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
    async ({ taxonomy, search, page, per_page }) => {
      const { client } = await activeClient();
      return { terms: await client.listTerms(taxonomy, { search, page, perPage: per_page }) };
    },
  ),
  tool(
    "ensure_terms",
    "Create missing WordPress terms",
    "Reuse exact existing categories or tags and create only the requested missing names.",
    objectSchema({ taxonomy: { type: "string", enum: ["category", "tag"] }, names: { type: "array", minItems: 1, maxItems: 30, items: { type: "string", minLength: 1 } } }, ["taxonomy", "names"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    async ({ taxonomy, names }) => {
      const { client } = await activeClient();
      return { terms: await client.ensureTerms(taxonomy, names) };
    },
  ),
  tool(
    "upload_media",
    "Upload WordPress media",
    "Upload a local file to the WordPress media library and set its accessible metadata.",
    objectSchema({
      file_path: { type: "string", minLength: 1 },
      alt_text: { type: "string", description: "Required for meaningful images; empty is allowed only for decorative images." },
      caption: { type: "string" },
      title: { type: "string" },
      description: { type: "string" },
    }, ["file_path", "alt_text"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    async ({ file_path, alt_text, caption, title, description }) => {
      const { client } = await activeClient();
      return client.uploadMedia(file_path, { altText: alt_text, caption, title, description });
    },
  ),
  tool(
    "list_media",
    "List WordPress media",
    "Search and browse the WordPress media library, including accessible text and image dimensions.",
    objectSchema({
      search: { type: "string" },
      media_type: { type: "string", enum: ["image", "video", "audio", "application"] },
      page: { type: "integer", minimum: 1, default: 1 },
      per_page: { type: "integer", minimum: 1, maximum: 100, default: 20 },
    }),
    { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
    async ({ search, media_type, page, per_page }) => {
      const { client } = await activeClient();
      return client.listMedia({ search, mediaType: media_type, page, perPage: per_page });
    },
  ),
  tool(
    "update_media",
    "Update WordPress media details",
    "Update alt text, caption, title, or description for an existing media item without replacing the file.",
    objectSchema({
      id: idSchema,
      alt_text: { type: "string" },
      caption: { type: "string" },
      title: { type: "string" },
      description: { type: "string" },
    }, ["id"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    async ({ id, alt_text, caption, title, description }) => {
      const { client } = await activeClient();
      return client.updateMedia(requireInteger(id, "id"), { altText: alt_text, caption, title, description });
    },
  ),
  tool(
    "create_frontend",
    "Create the Dashless Astro site",
    "Generate an owned, editable Astro frontend in a new or empty directory and connect it to the active WordPress site. This reads WordPress content and never authorizes creating starter content.",
    objectSchema({
      project_path: { type: "string", minLength: 1 },
      site_name: { type: "string", minLength: 1, description: "Defaults to the connected WordPress site title." },
      site_description: { type: "string" },
      public_url: { type: "string", format: "uri" },
      posts_path: { type: "string", default: "stories" },
      topics_path: { type: "string", default: "topics" },
      tags_path: { type: "string", default: "tags" },
      posts_per_page: { type: "integer", minimum: 1, maximum: 50, default: 12 },
    }, ["project_path"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: false },
    async ({ project_path, site_name, site_description, public_url, posts_path, topics_path, tags_path, posts_per_page }) => {
      const { site, client } = await activeClient();
      const inspection = await client.inspectSite();
      const postsPageId = inspection.settings?.page_for_posts || 0;
      const postsPage = !posts_path && postsPageId ? await client.getPost("page", postsPageId).catch(() => null) : null;
      const result = await createFrontend({
        projectPath: project_path,
        siteName: site_name || inspection.site_name,
        siteDescription: site_description ?? inspection.description,
        wordpressUrl: site.site_url,
        publicUrl: public_url || site.site_url,
        postsPath: posts_path || postsPage?.slug || "stories",
        topicsPath: topics_path,
        tagsPath: tags_path,
        postsPerPage: posts_per_page || inspection.settings?.posts_per_page || 12,
        homePageId: inspection.settings?.page_on_front || 0,
        postsPageId,
      });
      await updateActiveSite({ frontend: { project_path: result.project_path, posts_path: result.config.postsPath, public_url: result.config.publicUrl, created_at: new Date().toISOString() } });
      return result;
    },
  ),
  tool(
    "build_frontend",
    "Build the Astro site",
    "Install frontend dependencies when needed and run a production Astro check and static build.",
    objectSchema({ project_path: { type: "string", description: "Defaults to the active site's configured frontend." } }),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    async ({ project_path }) => {
      const { site, password, client } = await activeClient();
      const target = project_path || site.frontend?.project_path;
      if (!target) throw new DashlessError("frontend_not_configured", "Create or select a Dashless frontend first.");
      const result = await buildFrontend({ projectPath: target, site, password });
      const generation = (await client.inspectSite()).dashless_plugin?.content_version?.generation ?? null;
      await updateActiveSite({ frontend: { ...(site.frontend || {}), project_path: result.project_path, last_built_at: new Date().toISOString(), last_built_generation: generation } });
      return { ...result, output: result.output.slice(-4000) };
    },
  ),
  tool(
    "preview_frontend",
    "Preview the complete Astro site",
    "Build the current published WordPress content and open a local preview of the complete Astro site without creating a publication lock.",
    objectSchema({ project_path: { type: "string", description: "Defaults to the active site's configured frontend." } }),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    async ({ project_path }) => {
      const { site, password, client } = await activeClient();
      const target = project_path || site.frontend?.project_path;
      if (!target) throw new DashlessError("frontend_not_configured", "Create or select a Dashless frontend first.");
      const build = await buildFrontend({ projectPath: target, site, password });
      const preview = await startPreviewServer({ distPath: build.dist_path, routePath: "/" });
      const generation = (await client.inspectSite()).dashless_plugin?.content_version?.generation ?? null;
      await updateActiveSite({ frontend: { ...(site.frontend || {}), project_path: build.project_path, last_previewed_at: new Date().toISOString(), last_built_generation: generation } });
      return { preview_built: true, preview_url: preview.preview_url, dist_path: build.dist_path, project_path: build.project_path };
    },
  ),
  tool(
    "configure_deployment",
    "Configure static deployment",
    "Configure atomic local, SSH/rsync, or WP Cloud deployment. WP Cloud uses key-based SFTP and installs Dashless for WordPress as a must-use plugin automatically.",
    objectSchema({
      kind: { type: "string", enum: ["local", "ssh", "wpcloud"] },
      releases_path: { type: "string", minLength: 1 },
      public_url: { type: "string", format: "uri" },
      host: { type: "string" },
      user: { type: "string" },
      port: { type: "integer", minimum: 1, maximum: 65535 },
      identity_file: { type: "string" },
      htdocs_path: { type: "string", description: "WP Cloud document root; defaults to /srv/htdocs." },
    }, ["kind", "public_url"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    async (input) => {
      const deployment = validateDeployment(input);
      const { site, password } = await activeClient();
      const diagnostics = deployment.kind === "wpcloud"
        ? await checkWpCloudDeployment({ deployment, site, password })
        : null;
      if (diagnostics && !diagnostics.ready) {
        throw new DashlessError("wpcloud_preflight_blocked", "WP Cloud preflight found a newer installed bridge and will not replace it with an older version.", diagnostics);
      }
      let frontend_config = null;
      if (site.frontend?.project_path) {
        frontend_config = await updateFrontendPublicUrl(site.frontend.project_path, deployment.public_url);
      }
      await updateActiveSite({
        deployment,
        frontend: site.frontend ? { ...site.frontend, public_url: deployment.public_url } : undefined,
      });
      return { configured: true, deployment, frontend_config, diagnostics };
    },
  ),
  tool(
    "check_wpcloud_deployment",
    "Check WP Cloud deployment",
    "Run a read-only WP Cloud readiness check for SFTP, document-root access, WordPress REST, bridge compatibility, and hostname routing.",
    objectSchema({}),
    { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
    async () => {
      const { site, password } = await activeClient();
      if (site.deployment?.kind !== "wpcloud") throw new DashlessError("wpcloud_deployment_required", "Configure a WP Cloud deployment first.");
      return checkWpCloudDeployment({ deployment: site.deployment, site, password });
    },
  ),
  tool(
    "create_preview",
    "Build an exact Astro preview",
    "Build the real Astro site for a draft or staged change, start a local preview, and create a single-use publication lock.",
    objectSchema({
      post_type: postTypeSchema,
      id: idSchema,
      change_id: { type: "string", description: "Optional staged update or restoration ID." },
      project_path: { type: "string", description: "Defaults to the configured frontend." },
    }, ["post_type", "id"]),
    { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
    async ({ post_type, id, change_id, project_path }) => {
      const resolved = await resolvePreviewPayload({ postType: post_type, id, changeId: change_id || null });
      const target = project_path || resolved.site.frontend?.project_path;
      if (!target) throw new DashlessError("frontend_not_configured", "Create a Dashless frontend before previewing.");
      const payloadPath = await writePreviewPayload({ ...resolved.payload, post_type }, resolved.site.id);
      const build = await buildFrontend({ projectPath: target, site: resolved.site, password: (await getActiveConnection()).password, previewPayloadPath: payloadPath });
      const route = post_type === "page"
        ? await resolved.client.pageRoute(resolved.payload)
        : routeFor(post_type, resolved.payload.slug, resolved.site.frontend?.posts_path || "stories");
      const preview = await startPreviewServer({ distPath: build.dist_path, routePath: route });
      const digest = contentDigest(resolved.payload, post_type);
      const lock = await savePreviewLock({
        siteId: resolved.site.id,
        postType: post_type,
        postId: id,
        slug: resolved.payload.slug,
        routePath: route,
        baseModifiedGmt: resolved.baseModifiedGmt,
        changeId: resolved.change?.id || null,
        digest,
        projectPath: target,
        previewUrl: preview.preview_url,
      });
      return {
        preview_built: true,
        preview_url: preview.preview_url,
        preview_token: lock.token,
        digest,
        wordpress_modified_gmt: lock.base_modified_gmt,
        change_id: lock.change_id,
        warnings: validationWarnings(resolved.payload),
      };
    },
  ),
  tool(
    "publish_previewed",
    "Publish the exact preview",
    "Publish only the exact, unchanged content represented by a successful single-use preview token, then build, deploy, and verify when configured.",
    objectSchema({ preview_token: { type: "string", minLength: 20 } }, ["preview_token"]),
    { readOnlyHint: false, destructiveHint: true, openWorldHint: true },
    async ({ preview_token }) => {
      const lock = await loadPreviewLock(requireString(preview_token, "preview_token"));
      const { site, password, client } = await activeClient();
      const current = await client.getPost(lock.post_type, lock.post_id);
      if (current.modified_gmt !== lock.base_modified_gmt) {
        throw new DashlessError("preview_stale", "WordPress changed after this preview. Build a new preview before publishing.", { preview_modified_gmt: lock.base_modified_gmt, current });
      }
      let payload = current;
      let published;
      if (lock.change_id) {
        const change = await loadChange(site.id, lock.change_id);
        if (contentDigest(change.payload, lock.post_type) !== lock.digest) {
          throw new DashlessError("preview_payload_mismatch", "The staged content no longer matches the previewed digest.");
        }
        payload = change.payload;
        published = await client.applyPublishedPayload(lock.post_type, lock.post_id, payload);
      } else {
        if (contentDigest(current, lock.post_type) !== lock.digest) {
          throw new DashlessError("preview_payload_mismatch", "The WordPress draft no longer matches the previewed digest.");
        }
        published = await client.publishCurrent(lock.post_type, lock.post_id);
      }
      await markPreviewUsed(lock);
      const release = {
        saved_in_wordpress: true,
        preview_built: true,
        published_in_wordpress: true,
        wordpress: published,
        production_built: false,
        deployed: false,
        public_verified: false,
      };
      if (published.digest !== lock.digest) {
        release.release_error = {
          code: "wordpress_normalized_content",
          message: "WordPress changed the staged payload while saving it. The prior static release remains online; preview the saved WordPress version before deploying.",
          details: { preview_digest: lock.digest, wordpress_digest: published.digest },
        };
        return release;
      }
      try {
        const releaseId = site.deployment?.kind === "wpcloud" ? createReleaseId() : null;
        const releasePrefix = releaseId ? wpCloudReleasePrefix(site.deployment, releaseId) : null;
        const build = await buildFrontend({ projectPath: lock.project_path, site, password, releasePrefix });
        release.production_built = true;
        release.dist_path = build.dist_path;
        if (!site.deployment) {
          release.next_action = "Configure deployment or deploy the built dist directory manually.";
          return release;
        }
        release.deployment = await deployFrontend({
          distPath: build.dist_path,
          deployment: site.deployment,
          releaseId,
          site,
          password,
        });
        release.deployed = true;
        const generation = (await client.inspectSite()).dashless_plugin?.content_version?.generation ?? null;
        await updateActiveSite({ frontend: { ...(site.frontend || {}), project_path: lock.project_path, last_built_at: new Date().toISOString(), last_deployed_at: new Date().toISOString(), last_built_generation: generation, last_deployed_generation: generation } });
        const publicUrl = `${site.deployment.public_url}${lock.route_path || routeFor(lock.post_type, lock.slug, site.frontend?.posts_path || "stories")}`;
        release.verification = await verifyPublicDigest({
          url: publicUrl,
          digest: lock.digest,
          releaseId: site.deployment.kind === "wpcloud" ? release.deployment.release_id : null,
        });
        release.public_verified = release.verification.verified;
        return release;
      } catch (error) {
        const failure = asDashlessError(error);
        release.release_error = { code: failure.code, message: failure.message, details: failure.details };
        return release;
      }
    },
  ),
  tool(
    "deploy_frontend",
    "Build and deploy the current site",
    "Retry or perform a production Astro build and atomic deployment without changing WordPress publication state.",
    objectSchema({ project_path: { type: "string", description: "Defaults to the configured frontend." } }),
    { readOnlyHint: false, destructiveHint: true, openWorldHint: true },
    async ({ project_path }) => {
      const { site, password, client } = await activeClient();
      if (!site.deployment) throw new DashlessError("deployment_not_configured", "Configure a deployment target first.");
      const target = project_path || site.frontend?.project_path;
      if (!target) throw new DashlessError("frontend_not_configured", "Create or select a Dashless frontend first.");
      const releaseId = site.deployment.kind === "wpcloud" ? createReleaseId() : null;
      const releasePrefix = releaseId ? wpCloudReleasePrefix(site.deployment, releaseId) : null;
      const build = await buildFrontend({ projectPath: target, site, password, releasePrefix });
      const deployment = await deployFrontend({ distPath: build.dist_path, deployment: site.deployment, releaseId, site, password });
      const generation = (await client.inspectSite()).dashless_plugin?.content_version?.generation ?? null;
      await updateActiveSite({ frontend: { ...(site.frontend || {}), project_path: target, last_built_at: new Date().toISOString(), last_deployed_at: new Date().toISOString(), last_built_generation: generation, last_deployed_generation: generation } });
      const verification = site.deployment.kind === "wpcloud"
        ? await verifyPublicDigest({ url: `${site.deployment.public_url}/`, releaseId: deployment.release_id })
        : null;
      return {
        production_built: true,
        deployed: true,
        public_verified: verification ? verification.verified : false,
        dist_path: build.dist_path,
        deployment,
        verification,
      };
    },
  ),
  tool(
    "rollback_wpcloud_release",
    "Roll back the WP Cloud release",
    "Atomically reactivate the immediately previous verified Astro release without changing WordPress editorial content.",
    objectSchema({ confirm: { type: "boolean", description: "Must be true after explicit rollback approval." } }, ["confirm"]),
    { readOnlyHint: false, destructiveHint: true, openWorldHint: true },
    async ({ confirm }) => {
      if (confirm !== true) throw new DashlessError("confirmation_required", "Explicit confirmation is required to roll back the public site.");
      const { site, password } = await activeClient();
      if (site.deployment?.kind !== "wpcloud") throw new DashlessError("wpcloud_deployment_required", "The active site is not configured for WP Cloud deployment.");
      return rollbackWpCloudRelease({ deployment: site.deployment, site, password });
    },
  ),
];

const registry = new Map(tools.map((entry) => [entry.name, entry]));

function publicTool(entry) {
  const { handler, ...metadata } = entry;
  return metadata;
}

function resultPayload(value) {
  return {
    content: [{ type: "text", text: JSON.stringify(value, null, 2) }],
    structuredContent: value,
    isError: false,
  };
}

function errorPayload(error) {
  const failure = asDashlessError(error);
  const value = { error: { code: failure.code, message: failure.message, details: failure.details } };
  return {
    content: [{ type: "text", text: JSON.stringify(value, null, 2) }],
    structuredContent: value,
    isError: true,
  };
}

export async function handleRequest(message) {
  const { id, method, params = {} } = message;
  if (method === "initialize") {
    return {
      jsonrpc: "2.0",
      id,
      result: {
        protocolVersion: params.protocolVersion || "2025-06-18",
        capabilities: { tools: { listChanged: false } },
        serverInfo: { name: "dashless", title: "Dashless", version: "1.0.0" },
        instructions: "Call get_status first. WordPress is the sole production content source. Never fabricate or seed Posts, Pages, media, or terms for a design, preview, deployment, or launch; an empty site gets an honest empty state. Create or rewrite content only after an explicit editorial request, and save new content as a draft. Never ask for an Application Password in chat; use start_setup. Read modified_gmt before edits. Preview the real Astro build before publication, and publish only with the exact single-use preview token after explicit user approval. Report WordPress publication, production build, deployment, and public verification as separate states.",
      },
    };
  }
  if (method === "ping") return { jsonrpc: "2.0", id, result: {} };
  if (method === "tools/list") {
    return { jsonrpc: "2.0", id, result: { tools: tools.map(publicTool) } };
  }
  if (method === "tools/call") {
    const entry = registry.get(params.name);
    if (!entry) return { jsonrpc: "2.0", id, result: errorPayload(new DashlessError("tool_not_found", `Unknown Dashless tool: ${params.name}`)) };
    try {
      return { jsonrpc: "2.0", id, result: resultPayload(await entry.handler(params.arguments || {})) };
    } catch (error) {
      return { jsonrpc: "2.0", id, result: errorPayload(error) };
    }
  }
  if (method?.startsWith("notifications/")) return null;
  return { jsonrpc: "2.0", id, error: { code: -32601, message: `Method not found: ${method}` } };
}

async function runStdio() {
  process.stdin.setEncoding("utf8");
  let buffer = "";
  for await (const chunk of process.stdin) {
    buffer += chunk;
    let newline;
    while ((newline = buffer.indexOf("\n")) >= 0) {
      const line = buffer.slice(0, newline).trim();
      buffer = buffer.slice(newline + 1);
      if (!line) continue;
      let message;
      try {
        message = JSON.parse(line);
      } catch {
        process.stdout.write(`${JSON.stringify({ jsonrpc: "2.0", id: null, error: { code: -32700, message: "Parse error" } })}\n`);
        continue;
      }
      const response = await handleRequest(message);
      if (response) process.stdout.write(`${JSON.stringify(response)}\n`);
    }
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname)) {
  runStdio().catch((error) => {
    process.stderr.write(`Dashless MCP server failed: ${error.stack || error.message}\n`);
    process.exitCode = 1;
  });
}
