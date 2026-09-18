# Verification — 2026-09-16

> Authentication results below are historical. The September 17 Auth0 migration supersedes the local OAuth issuer; see [current setup and cutover checklist](auth0.md). Fresh local authentication results are recorded in [Auth0 verification](auth0-verification-2026-09-17.md). Live login remains a separate acceptance step.

## Current public transport check — 2026-09-18

The read-only checks in [live-readiness evidence](../evidence/live-readiness-2026-09-18.json) passed against `https://dashless.blog`: MCP discovery and the 28-tool catalog, theme discovery and preview assets, Auth0 protected-resource metadata, PKCE/S256 discovery, public policy routes, and retirement of the old identity endpoints. This is availability and contract evidence only; it does not certify email delivery, a real sign-in, billing, provisioning, restore, or an end-to-end ChatGPT session.

## Passed locally

- `npm run check`: **42 existing local workflow tests passed**, including the WordPress bridge and Astro tests. No existing local source file changed.
- `npm run check:hosted`: PHP/JavaScript syntax checks and 23 scoped MCP schemas passed.
- `php hosted/tests/integration.php /tmp/dashless-wp-test`: **46 checks passed** against a real local WordPress installation (PHP 8.5, WordPress 7.1, SQLite integration). OAuth uses the actual League library, ephemeral RSA keys and database-backed token repositories; Stripe, WP Cloud and site-agent responses are fixtures.
- Browser test: **20 checks passed**, including landing/account/sign-in/support/policy routes, mobile overflow, scanner-safe sign-in, successful POST login, disabled checkout, missing nonce and unsigned webhook rejection.
- Composer audit: no advisories or abandoned packages reported for the locked dependencies.
- Desktop and mobile screenshots inspected. Customer admin bar is hidden; operator WordPress administration remains available. Theme now uses the selected The Rip SVG and actual Hot Type raster wordmark from the branding task, with paper/ink/acid/lilac palette, heavy campaign type and no external font dependency. The earlier Georgia approximation was removed; final vector lettering and optical favicon refinement remain brand-production work.

Integration tests cover hashed/expired/replayed login, account uniqueness, encryption, lease exclusion, OAuth S256/resource/issuer/code and refresh replay, revocation, same-address reservation contention, checkout reuse, webhook replay and stale-event reconciliation, cancellation timing, seven-day grace, payment reactivation, serialized builds, cross-account jobs, retained exports, untrusted routing, disabled launch, uncertain create/recovery without duplicates, readiness checks, canceled-subscription recovery and idempotent initial refunds. These are not substitutes for real Stripe test-mode or WP Cloud integration.

## WP Cloud native dispatch probe

A task was submitted to **Teddy only (atomic site 152056190)** with arguments:

```text
dashless build-job 00000000-0000-4000-8000-000000000000
```

Task **758910** was accepted at 11:56:40 UTC and completed at 11:56:43 UTC, recording one failure and zero successes. It used an intentionally nonexistent job. The existing companion does not implement the hosted command; the task response does not include stderr, so its precise failure reason is not confirmed. **This proves API acceptance of the proposed custom command, not successful execution or worker isolation.** No publication or customer resource was created by this probe.

An earlier attempted read-only `wp eval` probe was rejected before execution with HTTP 400, “Command disallowed in Tasks API.” Do not use `eval` as the runtime strategy. Raw task output is retained in `evidence/native-dispatch.json`; no credentials are present.

The mandatory full Teddy build / three-user p95-under-two-seconds test **has not passed**. There is no measured claim of two-worker capacity, task memory compliance or task pricing. `scripts/native-acceptance.py` is ready for the site-plugin workstream: it requires two persisted full-snapshot jobs, explicit atomic ID, HTTPS origin and authenticated uncached page diagnostic. It defaults to dry-run, runs three users, checks the second stays queued, polls platform task completion and records p95, error/cache status and runtime metrics. No gate is automatically marked passed.

## Outstanding launch evidence

- Real Hub egress allowlisting, bootstrap/plugin installation, DNS provider/routing, new-subdomain certificates.
- Real mail delivery, sender authentication and secure cookies on production HTTPS.
- Stripe test Price/Checkout/Portal, signed real webhook lifecycle, failed renewal/cancellation/recovery/refund and deletion races.
- Site-plugin capabilities, pinned runtime build, memory peak, crash/retry, releases, private draft/media access, approvals, exports.
- Real ChatGPT connection, component/file transfer, published integration and reviewer account.
- Two accounts completing the full path, backup/key restore drill, provider retention/costs, final policies/support identity.

Live checkout is disabled. The initial local-only report above is historical. The Hub and branded theme are now deployed on WP Cloud site 152161516, with Cloudflare DNS and HTTPS verified. Stripe sandbox Price, Customer Portal, and webhook are configured. A signed synthetic webhook was accepted and an invalid signature rejected; this is not a real payment lifecycle acceptance test. No payment acceptance or public integration publication has occurred. See [deployment status](deployment-2026-09-16.md).

The separate WordPress and ChatGPT workstreams now implement the hosted routes, native command, and component. Their latest evidence is in `../handoffs/wordpress-result.md`; local checks pass, but native task builds still terminate before completion. The original nonexistent-job probe is retained above as historical evidence, not a description of the current plugin.

## Reproduce browser fixture

Use a disposable local WordPress with Hub/theme active, `WP_ENVIRONMENT_TYPE=local`, permalinks enabled and `DISABLE_WP_CRON=true`. The local MU mail sink returns true from `pre_wp_mail` and writes only test messages to `/tmp/dashless-local-mail.json`; it must never be deployed. Run:

```sh
PLAYWRIGHT_MODULE=/path/to/playwright node hosted/tests/browser.cjs
```

`DASHLESS_TEST_URL` defaults to `http://localhost:8874`; only localhost HTTP ports are allowed. Browser screenshots go to `/tmp/dashless-*.png`. Runtime secrets and sign-in tokens are excluded from repository evidence.

## Documentation checked

- [WP Cloud OpenAPI](https://wp.cloud/docs/api/openapi.json): create/meta/software/task API and resource limits.
- [Stripe subscription webhooks](https://docs.stripe.com/billing/subscriptions/webhooks): entitlement from payment/subscription state.
- [OpenAI authentication](https://developers.openai.com/plugins/build/auth): OAuth/PKCE/resource requirements.
- [OpenAI app guidelines](https://developers.openai.com/plugins/app-guidelines) and [submission](https://developers.openai.com/plugins/deploy/submission): existing-subscriber integration and public review gate.

## Railway build transition (September 16)

The new Railway worker passed three local service tests, a live 115-post/3-page/1-media build (134 HTML pages, 258 files, about 5.2 seconds including transfer), and real HTTPS integration with a disposable WordPress installation. Private preview creation, draft preservation and human approval were exercised; public activation HTTP was a local fixture. ZIP bootstrap verifies the plugin and portable source without installing Node on customer sites. Existing site integration (57 checks) and Hub integration (46 checks) passed.

WP Cloud Hub task 759107 succeeded with one target, one success and zero failures in 6.164 seconds: actual WP Cloud PHP submitted 115 fixture posts to Railway, waited for completion, verified the output archive checksum, and deleted the remote fixture. Temporary native probe files were removed. This proves WP Cloud-to-Railway build transport, not full customer provisioning or a media-heavy Teddy rebuild. See `../railway/README.md` and `../evidence/railway-*.json`.
