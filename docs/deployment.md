# Deployment

## WP Cloud

WP Cloud exposes one public document root per Atomic site. Dashless works within that constraint instead of trying to run Astro as a second server:

```text
Build machine                          WP Cloud /srv/htdocs
─────────────                          ─────────────────────
Astro builds static HTML and assets -> wp-content/uploads/dashless/releases/<id>
Dashless stages by key-based SFTP -> wp-content/mu-plugins/dashless-wpcloud-<id>.stage
Dashless installs its WP companion -> wp-content/mu-plugins/dashless-wpcloud.php
Dashless activates through WP REST  -> dashless_wpcloud_release option
```

Astro never runs as a persistent process on WP Cloud. The included must-use companion reserves WordPress system routes such as `/wp-admin`, `/wp-json`, and `/wp-login.php`, reports content generations, then serves the active prebuilt Astro file for reader-facing requests. Hashed Astro assets and mirrored media use immutable release URLs and are served directly as ordinary static files.

The release switch is atomic: Dashless uploads the complete new directory first, then the authenticated bridge verifies every file against the build's SHA-256 manifest before changing one WordPress option. An upload, integrity, bridge, or activation failure leaves the prior release selected. The immediately previous verified release can be restored with `rollback_wpcloud_release`.

Configure this mode with:

```text
kind: wpcloud
public_url: https://example.com
host: <the site's WP Cloud SSH/SFTP host>
user: <a key-enabled site SSH/SFTP user>
port: 22
htdocs_path: /srv/htdocs
```

Configuration runs a read-only preflight first. It proves key-based SFTP access to `wp-content`, checks WordPress REST authentication, compares the installed bridge version and hash with the packaged bridge, and reports whether the site uses the recommended single-domain route or an alias.

The same hostname can serve both experiences: readers receive Astro while Dashless talks to WordPress through `/wp-json`. A separate `cms.example.com` is optional. When using a WP Cloud alias as the public hostname, the alias must be served directly rather than canonicalized to the primary hostname.

Keep WP Cloud's `static_file_404` behavior set to `wordpress` (the platform default) so generated routes with extensions, such as `/rss.xml`, can reach the bridge when no root-level file exists. The bridge flushes WordPress caches when activating a release and emits release-specific cache validators.

This is a static-build architecture, but reader HTML passes through a small PHP file router before WP Cloud's edge cache. It does not require Astro, Node, or a second virtual host at request time.

## Same server

Use separate web roots even when WordPress and Astro share one machine:

```text
example.com      -> /var/www/dashless/current
cms.example.com  -> /var/www/wordpress
```

Configure Dashless with `/var/www/dashless` as the releases path. Each deploy creates a new directory under `releases/` and atomically changes the `current` symlink. Point Nginx, Apache, cPanel, or Plesk at `current`.

WordPress remains reachable for its REST API and emergency wp-admin access. The Astro reader site does not share WordPress's directory or rewrite rules.

## Local release directory

Choose `kind: local` when the Dashless process can write directly to the public server's filesystem. The releases path must be absolute. Dashless 1.0 keeps old releases; retention can be managed by the host after deployment is proven.

## SSH release directory

Choose `kind: ssh` when the static root is on another machine or Dashless is running from a workstation. SSH must already work with a key or agent. Dashless stores the hostname, username, port, and paths, but never asks for or stores an SSH password.

The remote path is restricted to ordinary absolute path characters. Dashless uploads into a new release with rsync, then changes the remote `current` symlink. The previous release stays intact when upload or activation fails.
