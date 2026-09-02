=== Dashless ===
Contributors: regionallyfamous
Tags: headless, static site, astro, codex, wp cloud
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WordPress to the local Dashless Codex workflow and serves atomic Astro releases on WP Cloud.

== Description ==

Dashless keeps WordPress as the canonical store for Posts, Pages, categories, tags, media, and revisions while a local Codex plugin generates and deploys the reader-facing Astro site.

The companion plugin exposes an authenticated site contract, records a monotonic content generation, reserves WordPress system routes, verifies every uploaded static release against its SHA-256 manifest, activates releases atomically, and supports one-step rollback.

No WordPress or Application Password credential is stored by this plugin. The local Dashless Codex plugin connects through WordPress Application Passwords.

The plugin stores only the active/previous static release record and a monotonic content-generation record. A normal plugin uninstall removes those options but deliberately preserves uploaded static releases for recovery. Dashless has no telemetry.

== Installation ==

1. Install and activate the plugin normally, or let the Dashless Codex plugin install it as a must-use plugin during the first WP Cloud deployment.
2. In Codex, install Dashless and say “Connect my WordPress site to Dashless.”
3. Complete the loopback setup page and generate the Astro frontend.

== Changelog ==

= 1.0.0 =
* Initial complete local-Codex workflow.
* Authenticated content-model and content-generation endpoint.
* Atomic WP Cloud releases, integrity verification, upgrades, and rollback.
