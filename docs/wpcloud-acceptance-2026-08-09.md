# WP Cloud acceptance — 2026-08-09

Result: **PASS**

This is the credential-free acceptance record for Dashless 1.0. The site was created specifically for the requested Teddy launch and remains online; it is not a disposable test site.

## Environment

- Public origin: `https://teddy.wpcomstaging.com`
- WP Cloud site ID: `152056190`
- Datacenter affinity: DFW
- PHP channel: 8.5
- WordPress channel: latest
- Storage quota: 25 GB
- Firewall profile: default
- Static-file 404 mode: `wordpress`
- SFTP: dedicated key-only `dashless` user through `sftp.wp.com`
- Chrooted document root: `/htdocs`
- Dashless WordPress companion: 1.0.0 with matching installed/source integrity

No API key, WordPress Application Password, SSH private key, password, cookie, authorization header, database credential, or site API key is recorded here.

## Editorial acceptance

- Connected through the loopback-only setup flow; the generated Application Password is stored locally in macOS Login Keychain.
- Removed the one-time bootstrap and its credential file immediately after connection.
- Set the publication identity to Teddy.
- Added one accessible 1200×800 launch image, three categories, and three tags.
- Converted the default post and Page through staged, exact-previewed updates.
- Created and published five additional starter stories through idempotent drafts, real Astro previews, and single-use preview locks.
- Final published inventory: six Posts and one About Page; the default Privacy Policy remains a non-public draft.
- WordPress content generation: 20; final local build and deployed generation both match 20.

## Deployment acceptance

- WP Cloud readiness reported SFTP reachable, WordPress REST reachable, single-domain routing, and companion integrity matching.
- The first fresh-file transfer exposed an OpenSSH 10.3 incompatibility: `reput` refuses a destination that does not yet exist. The incomplete release was never activated. Dashless now uses immutable `put` transfers with three bounded whole-release retries.
- Public verification initially exposed separate CDN variants for different content encodings. Verification now adds a release-specific cache-busting query while still validating the canonical response's `X-Dashless-Release` header.
- Media mirroring exposed Astro's public-directory copy timing: newly fetched WordPress media could miss the same build. Dashless now copies mirrored media into the active `dist` tree as well as the owned public cache.
- The populated production audit passed with 0 errors and 0 warnings.

## Rollback record

- First verified release: `20260809T163426066Z-368394`
- Second verified release: `20260809T163443580Z-8d3184`
- Rollback reactivated: `20260809T163426066Z-368394`
- Rollback public verification: passed
- WordPress editorial content and content generation were unchanged by rollback.
- Current polished release: `20260809T164044208Z-5ebde3`

## Reader-facing verification

- Desktop width: 1280×720
- Mobile width: 390×844
- Homepage, story, About, and live search passed without horizontal overflow.
- Featured images loaded at their expected 1200×800 dimensions with no fallback UI.
- Long featured and article headlines stayed within their columns.
- Article body first-letter styling matches the body text; no drop cap is present.
- Skip navigation, header, navigation, main, footer, canonical URL, Article JSON-LD, meaningful hero alt text, responsive layout, and search results were verified in the rendered site.
- Homepage, story, About, search, RSS, sitemap, and robots return 200 with the current release header.
- An unknown route returns the Dashless 404 page with HTTP 404 and the current release header.
- HTTPS responses include HSTS and `X-Content-Type-Options: nosniff`.

## Retained access

Because this is the requested live site, the dedicated WordPress Application Password and key-only SFTP user remain active so local Codex can publish future updates. Both are isolated and revocable. The WP Cloud API key remains in its existing private environment and was never copied into Dashless or this repository.
