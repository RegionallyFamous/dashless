# Complete local workflow

Dashless has two installable pieces and no hosted service:

1. The Dashless Codex plugin runs locally, owns credentials, generates the Astro project, builds previews and production releases, and deploys them.
2. Dashless for WordPress keeps WordPress system routes available, reports the site contract and content generation, and activates verified WP Cloud releases.

The generated Astro project is ordinary user-owned source code, not another product or account.

## First installation

1. Install the Dashless Codex plugin and start a new Codex task.
2. Say “Connect my WordPress site to Dashless.”
3. Open the returned loopback URL and complete WordPress Application Password authorization. The credential goes directly to local Dashless storage.
4. Dashless calls `inspect_site`, reports the discovered content model and Page tree, and identifies whether the WordPress companion is present.
5. Choose an empty local project directory. Dashless generates the Astro site, installs its dependencies, builds it from the content already published in WordPress, and opens the complete local preview. If WordPress is empty, Dashless previews the real empty state instead of adding starter content.
6. Configure WP Cloud with its key-enabled SFTP host and user. The first deployment installs Dashless for WordPress as a must-use plugin, uploads an immutable release, verifies every file, activates it, and checks the public release ID.

For a conventional host, install `dashless-wordpress-1.0.0.zip` in WordPress and configure a separate static web root through local or SSH deployment.

Dashless 1.0 deploys to an existing WP Cloud site. It does not create or purchase the site, manage billing, or register DNS.

## Daily publishing

1. Codex reads the current item and its `modified_gmt` value.
2. New content is saved as a WordPress draft. Changes to published content remain in a local staged changeset.
3. Dashless builds the real Astro route and returns a loopback preview URL, validation warnings, content digest, and single-use token.
4. After explicit approval, Dashless verifies that WordPress is unchanged, publishes the exact approved payload, builds production, uploads a new immutable release, activates it, and verifies the public route.
5. Every state is reported independently so a WordPress success cannot be confused with a deployment success.

## Site and design changes

Use `preview_frontend` to build and inspect the complete site without changing WordPress publication state. Design, preview, launch, and deployment requests never authorize new WordPress content. The Astro project is editable: layouts, components, styles, and routes can be changed like any normal local project. `build_frontend` proves the customized project still passes Astro's checks.

## WordPress changes outside Codex

The WordPress companion increments a monotonic content generation when a Post, Page, term, or attachment changes. `get_status` compares that generation with the last local build and deployment. If WordPress is newer, `deploy_frontend` rebuilds the canonical published state and activates a fresh release.

Because there is no remote worker, a future-dated WordPress publication cannot rebuild while the local computer and Codex are not running. Unattended scheduled rebuilds are therefore intentionally outside the local-only product.

## Recovery

- A failed Astro build never changes the active release.
- A failed upload or integrity check never activates the candidate release.
- If WordPress published successfully but deployment failed, `deploy_frontend` retries without republishing the content.
- `rollback_wpcloud_release` reactivates the immediately previous verified static release without changing WordPress content.
- `stage_revision_restore` previews an older WordPress revision before it can replace the current public content.

## Disconnect and removal

`disconnect_site` requires explicit confirmation. By default it revokes the current Application Password in WordPress, removes the local credential and connection, and erases site-specific idempotency, staged-change, preview-lock, and preview-payload data. It does not delete WordPress content, the generated Astro project, or static deployment releases.
