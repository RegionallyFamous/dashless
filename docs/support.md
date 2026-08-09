# Support and compatibility

## Supported 1.0 environment

| Component | Supported and tested target |
|---|---|
| Codex host | macOS and Linux |
| Windows | Editorial and Astro operations are experimental; local symlink and SSH/rsync deployment are not claimed for 1.0 |
| Node.js | 22.12 or newer even-numbered releases |
| WordPress | 6.5.9 through 7.0.3, single-site |
| PHP | 8.0 through 8.5 syntax; compatibility CI covers PHP 8.0 and 8.4 |
| Astro | Version resolved by the committed `templates/astro/package-lock.json` |
| Browsers | Current browsers supported by the Astro/Vite build target |

The local Codex server uses Node built-ins. Building a generated frontend requires npm and network access for its first locked dependency installation. SSH deployment additionally requires `ssh` and `rsync`; WP Cloud deployment requires `sftp`. Release packaging requires `zip` and `unzip`, but normal editorial use does not.

## WP Cloud scope

Dashless 1.0 deploys to an existing WP Cloud/Atomic site. It requires:

- a WordPress user and dedicated Application Password;
- a key-enabled WP Cloud SSH/SFTP user;
- access to the configured document root's `wp-content` directory (`/srv/htdocs` by default, or `/htdocs` for chrooted accounts); and
- a public hostname served directly by the site.

Dashless installs or safely upgrades its must-use WordPress companion, uploads and verifies immutable Astro releases, activates them, verifies the public release, and can roll back one release.

WP Cloud account creation, billing, purchasing, site provisioning, DNS registration, and domain validation are outside the 1.0 product promise. The optional WP Cloud API is not required for deploying to an existing site.

## Supported content

Dashless supports core Posts, nested Pages, categories, tags, media, and revisions. WooCommerce, multisite, custom post types, arbitrary custom fields, comments, unattended scheduled rebuilding, page builders, and team approval systems are deliberately outside 1.0.

## Credential lifecycle

Use a dedicated WordPress Application Password. `disconnect_site` revokes the credential in WordPress by default, removes its local copy, and erases the site's idempotency, staged-change, preview-lock, and preview-payload records. It deliberately preserves the user-owned Astro project and WordPress content.

If remote revocation fails, Dashless retains the local connection so revocation can be retried. The user may explicitly request local-only removal and revoke the password manually in WordPress.
