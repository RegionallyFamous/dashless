# Dashless

**WordPress without the dashboard.**

Dashless turns Codex into a careful WordPress newsroom and Astro into the public website. WordPress remains the canonical store for posts, pages, media, taxonomy, and revisions; everyday drafting, previewing, publishing, and restoration happen conversationally.

Dashless never invents starter posts to populate a theme. Designing, previewing, deploying, or launching an Astro site only reads what is already published in WordPress. If WordPress is empty, the public site shows an intentional empty state. Codex creates or rewrites WordPress content only after a separate, explicit editorial request, and new content begins as a draft.

## What ships in 1.0

- A local Codex plugin and an installable WordPress companion. There is no remote Dashless service or account.
- A local setup page that sends the WordPress Application Password directly to Dashless, never through chat.
- A site-inspection workflow that discovers the WordPress settings, content counts, Page tree, content model, companion version, and build freshness.
- Core WordPress post, page, category, tag, media, and revision operations.
- Media-library browsing and accessible metadata repair, plus nested Page parent and menu-order editing.
- Stable create keys and `modified_gmt` checks to prevent duplicates and stale overwrites.
- Revision-locked publishing: the exact content digest that built successfully is the only content a preview token can publish.
- An attractive, editable Astro starter with nested Pages, story pagination, topic and tag archives, dynamic navigation, static search, local media mirroring, build-generated 1200×630 Post share cards, RSS, sitemap, robots, structured SEO data, and a 404 route.
- The “Hypertext Diary” default theme: a colorful late-’90s personal-web aesthetic with browser chrome, chunky cards, blog widgets, a day/night switch, native cross-document transitions, reduced-motion support, responsive layouts, and highly readable article pages.
- A reusable Astro site-director skill that teaches Codex and other skill-aware LLM agents the design workflow, WordPress/Astro trust boundaries, three-pass visual QA method, and release criteria, plus a dependency-free production audit for markup, metadata, accessibility, internal links, and bundle size.
- Atomic deployment to a local release directory, a server reachable with SSH keys and rsync, or a WP Cloud site reachable with key-based SFTP.
- A WP Cloud mode that automatically installs the companion as a must-use plugin and serves prebuilt Astro releases beside WordPress in its single document root.
- SHA-256 release manifests, bounded upload retries, atomic companion installation and upgrades, and one-command WP Cloud rollback.
- Read-only WP Cloud setup diagnostics for SFTP, document-root access, companion compatibility, and domain routing.
- Public verification through both the editorial content digest and the exact active WP Cloud release ID.

Dashless does not require a Dashless cloud account. The WordPress companion records content changes and exposes the site contract. WP Cloud mode installs the same companion automatically as a must-use plugin because WP Cloud exposes one public document root per site.

## Install for local development

This repository is already a complete plugin root. Add it to a local Codex marketplace or package the root directory without changing its layout. The required entry point is `.codex-plugin/plugin.json`.

After installing, start a new Codex task and say:

> Connect my WordPress site to Dashless.

Dashless returns a loopback-only setup URL. The setup page links to WordPress's built-in Application Password authorization screen and stores the resulting credential locally. On macOS it uses Login Keychain; elsewhere it uses a mode-`0600` file under the platform's application-data directory.

See [support and compatibility](docs/support.md) for the tested platform matrix and exact 1.0 scope. WP Cloud support deploys to an existing site; account purchasing, site provisioning, and DNS registration are not part of Dashless 1.0.

## Golden path

1. Connect an HTTPS WordPress site.
2. Inspect the WordPress site and generate the complete owned Astro frontend.
3. Create a draft, assign terms, and upload a featured image.
4. Build and open the actual Astro preview.
5. Revise and preview again.
6. Explicitly approve the preview.
7. Publish the locked preview, build production, deploy atomically, and verify the public digest.
8. Stage an older WordPress revision, preview it, and publish it through the same contract.
9. Roll the public WP Cloud release back independently if a later site-wide problem is discovered.

Disconnecting revokes the dedicated Application Password in WordPress by default and removes the site's local Dashless staging records. It preserves WordPress content and the user-owned Astro project.

See [the complete local workflow](docs/local-workflow.md) for first installation, daily publishing, design changes, out-of-band WordPress edits, and recovery. See [the Hypertext Diary theme guide](docs/theme.md) for its content mapping, visual tokens, accessibility behavior, and customization points.

## Data ownership and secrets

- WordPress is the sole production source of editorial content; the Astro project contains presentation code, not fallback posts or demo copy.
- The generated Astro project is an ordinary project owned by the user.
- Dashless configuration and preview locks live outside the source repository.
- Credentials are never returned in tool results or written into the Astro project.
- Set `DASHLESS_DATA_DIR` to override the local data directory for tests or managed environments.
- `DASHLESS_WORDPRESS_URL`, `DASHLESS_WORDPRESS_USERNAME`, and `DASHLESS_WORDPRESS_APP_PASSWORD` can provide an ephemeral connection for CI; they are never persisted.

## Development

Dashless's local server uses only Node.js built-ins. Node 22.12 or newer is required.

```sh
npm test
npm run check
npm run release
npm run test:wordpress:playground
node skills/design-dashless-astro-sites/scripts/audit-dist.mjs --project /path/to/generated/site
```

The generated frontend installs its own Astro dependencies the first time Dashless builds it.

`npm run release` produces deterministic Codex and WordPress ZIPs, clean-extraction smoke-tests both archives, and writes `dist/SHA256SUMS`.

## Deliberate boundaries

Version 1.0 is complete for core Posts, nested Pages, categories, tags, media, revisions, previews, archives, search, builds, and deployment. It intentionally excludes WooCommerce, multisite, custom post types and arbitrary custom fields, comments, unattended scheduled rebuilds, page-builder reconstruction, and team approvals. The public Astro site and WordPress should use separate web roots when the host permits it. WP Cloud mode is the deliberate exception: it uses immutable releases under WordPress uploads and a host-aware companion inside WP Cloud's single web root.

See [the publishing contract](docs/publishing-contract.md) for the safety model and [deployment](docs/deployment.md) for conventional and WP Cloud layouts.

Security issues should follow [the private reporting policy](SECURITY.md). See [privacy and local data](docs/privacy.md) for stored data and removal, and [the release checklist](docs/release-checklist.md) for the automated and real-WP-Cloud acceptance gates.
