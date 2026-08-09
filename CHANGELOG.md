# Changelog

All notable changes to Dashless are documented here. Versions follow semantic versioning across the Codex plugin and the separately packaged WordPress companion.

## 1.0.0 — 2026-08-09

- Complete local Codex workflow for WordPress Posts, nested Pages, categories, tags, media metadata, and revisions.
- Idempotent draft creation, stale-write detection, staged updates to published content, exact revision previews, single-use publication locks, and independent publication/deployment state reporting.
- Owned Astro publication with archives, search, navigation, media mirroring, build-generated 1200×630 Post share cards, RSS, sitemap, robots, structured metadata, 404 handling, native cross-document transitions, light/dark presentation, and accessible responsive behavior.
- Local, SSH/rsync, and WP Cloud deployment with immutable releases, SHA-256 manifests, atomic activation, public verification, companion upgrades, and WP Cloud rollback.
- Live WP Cloud hardening for chrooted `/htdocs` roots, fresh-file SFTP uploads on OpenSSH 10.3, cache-busted edge verification, and same-build WordPress media mirroring.
- Native Dashless workflow and Astro site-director skills, including a deterministic production audit.
- WordPress-only content provenance guardrails: design, preview, deployment, and launch workflows never seed editorial content, while empty sites render an intentional empty state.
- Loopback-only credential setup, local secret protection, remote Application Password revocation on disconnect, and per-site local staging-data removal.
- Locked Astro dependencies, clean-package smoke tests, deterministic archives, release checksums, and compatibility CI.

The 1.0 release candidate was accepted on a newly provisioned WP Cloud site, including exact-preview publication, media, search, responsive visual QA, atomic deployment, public release-header verification, rollback, and re-deployment.

## Deliberate 1.0 boundaries

Dashless 1.0 does not support WooCommerce, multisite, custom post types, arbitrary custom fields, comments, unattended scheduled rebuilds, page-builder reconstruction, team approvals, or WP Cloud account/site purchasing and provisioning.
