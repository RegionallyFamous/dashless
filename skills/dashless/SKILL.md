---
name: dashless
description: Use Dashless to connect and inspect a WordPress site, create or revise posts and nested pages, manage media and terms, generate or build the owned Astro frontend, preview exact revisions or the complete site, publish with stale-write protection, deploy, verify public pages, or stage a WordPress revision for restoration.
---

# Dashless

Dashless makes WordPress the canonical publishing engine, Astro the reader-facing site, and Codex the everyday editorial interface.

## Start every workflow

1. Call `get_status` before assuming a connection, frontend, or deployment exists.
2. If no site is connected, call `start_setup`. Give the user the local setup URL and pause the publishing workflow until they finish it. Never ask them to paste a WordPress password or Application Password into chat.
3. Call `get_status` again after setup. State which site is active and whether a frontend and deployment are configured.
4. Call `inspect_site` before generating the frontend. Use its title, description, Page tree, counts, route map, and plugin warning instead of asking the user to enumerate WordPress content.
5. When disconnecting, revoke the dedicated WordPress Application Password and remove site-specific local data unless the user explicitly requests local-only removal. Preserve WordPress content and the user-owned Astro project.

## Editorial contract

- WordPress is the sole source of production editorial content. Astro may render only Posts, Pages, media, terms, and metadata returned by the connected WordPress site.
- Never fabricate, seed, backfill, or import editorial content merely to populate a design, preview, deployment, or launch. `create_frontend`, `preview_frontend`, design work, and deployment do not authorize creating or rewriting WordPress content.
- Create or rewrite content only when the user explicitly asks for that editorial action. Use user-supplied copy when provided; if the user asks Codex to author copy, state that authorship plainly and save it as a draft.
- Treat theme demos, local fixtures, screenshots, generated HTML, and sample copy as design references only. Never sync them into WordPress or include fallback editorial content in the deployable Astro source.
- If WordPress has no published content, build an honest empty state from that WordPress response. Do not add starter posts, Pages, media, or terms.
- New content is always a draft. Never interpret writing, revising, fixing, or uploading as permission to publish.
- Use semantic HTML in `content`; restrained Markdown may be converted before calling a write tool. Do not store MDX, Astro components, page-builder markup, or theme-specific shortcodes unless the user explicitly requires legacy compatibility.
- Before updating a draft, read it and pass its exact `modified_gmt` as `expected_modified_gmt`. If Dashless reports a conflict, stop and reconcile; never retry with a newer timestamp without showing the competing version.
- Existing published content must be revised with `stage_update`, not `update_draft`. A staged change does not alter the WordPress parent until the exact preview is published.
- Use stable UUID-like `client_key` values with `create_draft`. Reuse the same key when retrying so a network retry cannot duplicate a post.
- Keep post IDs, media IDs, term IDs, revision IDs, change IDs, and preview tokens intact. Do not substitute titles or slugs where a stable ID is requested.

## Draft and media workflow

1. Confirm that the user explicitly requested the editorial change. A request to design, preview, launch, deploy, or make the site look populated is not editorial authorization.
2. Use `list_posts`, `get_post`, and `list_terms` to inspect current state.
3. Use `ensure_terms` only for terms the user actually wants created; reuse exact existing terms when available.
4. Use `upload_media` for a local file. Require useful alt text for meaningful images. Do not invent a credit.
5. Call `create_draft` or `update_draft` with the final IDs and semantic HTML.
6. Report the WordPress post ID, status, revision, warnings, and `modified_gmt`.
7. Use `list_media` and `update_media` to reuse existing files and repair alt text, captions, titles, or descriptions instead of uploading duplicates.

## Preview and publish workflow

1. A frontend must exist. Use `create_frontend` when the user wants the default owned Astro site. It includes nested Pages, story pagination, category and tag archives, search, navigation, RSS, sitemap, robots, SEO metadata, and a 404 page.
2. Use `preview_frontend` when the user wants to inspect the complete published site or design. It does not authorize publication and does not create a publication token.
3. Call `create_preview` for the draft or staged change. A successful result returns a preview URL, content digest, and opaque preview token.
4. Give the user the preview URL and validation warnings. Do not call a textual summary a site preview.
5. Publish only after explicit approval that clearly refers to the previewed content.
6. Call `publish_previewed` with that exact preview token. Never create or edit content between preview and publish.
7. Report the release states separately: saved in WordPress, preview built, published in WordPress, production built, deployed, and public page verified. Never say simply “published” when a later state failed.

## Astro design and quality workflow

- For requests to design, restyle, polish, review, or audit the public frontend, also use the bundled `design-dashless-astro-sites` skill. Its quality contract governs visual direction, representative content, responsive QA, accessibility, metadata, media resilience, and the deterministic production audit.
- Use existing WordPress content when judging the real design. If the site is empty or sparse, verify intentional empty and sparse states; local QA fixtures may test additional shapes only in isolation and must never be written to WordPress or deployed as editorial content.
- Keep theme work separate from publication authority. A design preview does not authorize content publication or deployment.
- Run the design skill's `audit-dist.mjs` after a production build. Fix every error and resolve or document warnings before release packaging.
- Generated Astro sites are owned source. Never replace a user's customized generated site with the bundled template.

## Revisions

- Use `list_revisions` to locate a revision.
- Use `stage_revision_restore` to turn an old WordPress revision into a non-public staged change.
- Preview and publish that staged change through the normal locked workflow. Never overwrite a live post directly from a revision.

## Deployment

- `configure_deployment` supports an atomic local release directory, SSH/rsync, or WP Cloud with key-based SFTP. It never stores an SSH/SFTP password.
- `get_status` reports `content_sync` when the WordPress companion is available. If `needs_build` or `needs_deploy` is true, use `preview_frontend` or `deploy_frontend` as appropriate.
- For same-server hosting, keep Astro and WordPress in separate web roots. Point the public virtual host at `<releases_path>/current`; keep WordPress on a separate hostname or directory.
- For WP Cloud, use `kind: wpcloud`. Dashless uploads immutable builds under `/srv/htdocs/wp-content/uploads/dashless/releases`, installs the WordPress companion as a must-use plugin, and activates the release through authenticated WordPress REST. Astro does not run on WP Cloud.
- WP Cloud 1.0 deployment assumes an existing site. Purchasing, account management, site provisioning, and DNS registration are outside the Dashless product workflow.
- `configure_deployment` performs the WP Cloud readiness preflight. Use `check_wpcloud_deployment` to repeat it without changing configuration.
- WP Cloud may use the same hostname for Astro reader routes and WordPress system routes. A separate public alias also works when WP Cloud is configured to serve aliases directly instead of canonicalizing them.
- Use `rollback_wpcloud_release` only after explicit approval. It reactivates the immediately previous verified static release and does not change WordPress editorial content.
- A failed build or deployment must leave the previous static release intact.

## Complete local scope

Dashless 1.0 is complete for the core local-Codex workflow: WordPress Posts, nested Pages, categories, tags, media metadata, revisions, exact previews, static archives, search, production builds, local/SSH/WP Cloud deployment, verification, and WP Cloud rollback. Do not claim support for WooCommerce, multisite, custom post types, arbitrary custom fields, comments, unattended scheduled rebuilds, page-builder reconstruction, or team approval systems.
