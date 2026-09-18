# Installation and operations

## Dedicated Hub setup

1. Create a fresh WP Cloud WordPress site for `dashless.blog`. Install the packaged Hub ZIP and activate the Dashless theme. Run `wp dashless-hub install-pages`. Keep `DASHLESS_LIVE_CHECKOUT=false`.
2. Set constants/environment following `config.example.php`. Generate a stable random 32-byte encryption key. Auth0 owns token-signing keys. Store them outside public web roots and outside database backups; restrict PHP/native CLI file permissions. Back them up in the operator's existing secure recovery system. Do not write keys into `_data`, cloned blueprints, screenshots or logs.
3. Configure API egress allowlisting for the Hub's actual WP Cloud outgoing addresses. Verify from both HTTP and native CLI. Local API success is not Hub egress evidence.
4. Set actual DNS provider records for the apex and customer subdomains using WP Cloud's returned routing target. Do not invent a wildcard target. Verify new-host TLS and expected release header before declaring ready. DNS provider credentials and actual records are not supplied yet; DNS mutation is intentionally not implemented against an assumed provider.
5. Configure Auth0 using the [sign-in and migration guide](auth0.md) and protected settings in `config.example.php`. Verify passwordless email receipt through a production sender and actual Auth0/ChatGPT connection, refresh and disconnect. Separately verify WP Cloud delivery of service notices; `wp_mail=true` alone is not delivery evidence.
6. Keep cookies host-only. Shared `.dashless.blog` cookies are rejected. Force HTTPS in production. Exclude account/sign-in/OAuth/MCP and private APIs from every page/CDN cache. Avoid request-body/query logging on sign-in and OAuth endpoints. Disable public subscriber author enumeration where it would reveal membership.

## Stripe test mode

Create a single active USD recurring monthly Price with amount 999, interval count 1. Configure a Customer Portal that supports invoices, payment-method updates and cancellation **at period end only**, without plan changes or quantity changes. Verify this actual Portal setting before `billing_verified` passes; the application does not override arbitrary changes made in Stripe's dashboard.

Set test API key, Price and endpoint signing secret. Enable `DASHLESS_TEST_CHECKOUT=true` only in the gated test environment. Register `POST /wp-json/dashless-hub/v1/stripe/webhook` for the events listed in `Billing::receive`. Use a current API version compatible with stripe-php's pinned version. Checkout creates quantity one using server configuration; the success URL never grants entitlement. The webhook stores minimal identifiers and refreshes Stripe before transitions.

Verify duplicate/out-of-order delivery, initial payment failure, renewal failure, portal cancellation, recovery and refund with real Stripe test clocks/events. The local fixtures exercise orchestration but do not verify Stripe dashboard configuration. Disable test checkout before configuring live keys. Never mark the published-app gate based on developer-mode connection alone.

## Background execution

`wp dashless-hub reconcile` runs a bounded native drain (internal 240-second window), releasing its durable lease before any continuation. `--once` performs one reconciliation pass. Normal webhooks/user actions request immediate native dispatch. The Hub's WP Cloud native cron runs `wp dashless-hub reconcile --once` twice per hour (cron entry `10132` in the current Hub environment); the registered hourly WP-Cron hook remains a fallback, not proof of reliable external scheduling. All Hub task dispatch must target `DASHLESS_HUB_SITE_ID` alone.

## Customer plugin rollouts

Customer site-plugin updates are fleet operations, not WordPress admin actions. The Hub stores an immutable package URL, SHA-256, version, target site IDs, task IDs, attempts, and health results in its durable table. WP Cloud receives one explicitly scoped site task at a time because the provider serializes these operations.

The next Hub reconciliation automatically creates one rollout for the newly published package when no active or paused rollout is in progress. It is idempotent by package checksum; there is no duplicate rollout when cron, a webhook and an operator action overlap. To create one manually for a canary or recovery operation, use:

```sh
wp dashless-hub rollout-create --all --cohort=25
wp dashless-hub reconcile
```

The first target is the canary. Each target is installed, checked through `/capabilities`, and only then does the queue advance. Transient dispatch or health failures retry with backoff up to three times. Uncertain dispatches and a failure rate above the rollout threshold pause the rollout; they never submit a blind duplicate task. Inspect or control a rollout with:

```sh
wp dashless-hub rollout-status <rollout-id>
wp dashless-hub rollout-pause <rollout-id>
wp dashless-hub rollout-resume <rollout-id>
```

The `Publish site release` workflow builds the site and runtime archives, uploads them to the Hub's `wp-content/dashless-packages/` directory, and atomically replaces `current.json`. Set the production environment variable `DASHLESS_RELEASE_ENABLED=true` only after the WP Cloud SSH secrets are present; otherwise the workflow stays intentionally skipped. The Hub reads that pointer on the next request, while existing rollouts retain their original immutable URL and checksum. GitHub builds and publishes artifacts; customer sites never contact GitHub and no customer needs to log in.

Customer builds use `wp dashless build-job <uuid>` on exactly one site. A running or uncertain task blocks later builds. Job polling reconciles completion and releases the next queued build. Site/plugin must implement the internal runtime deadline, local snapshot and child-process resource measurement. Native task results are authoritative; callback payloads only wake reconciliation and must carry the configured callback secret. Keep callbacks disabled until their delivery/authentication configuration is verified.

## Recovery and operational controls

The WordPress Dashless Hub screen requires `manage_options` and per-action nonces. It provides signup pause, launch evidence, account/state/error view, reconciliation, credential rotation, jobs, staged canary plugin rollouts and last-maintenance status. Rollout targets are explicit owner IDs, first is canary; each advance handles one site and verifies version before continuing. The configured immutable package and checksum cannot change mid-rollout. No fleet actions exist in customer MCP tools.

A create request is recorded before dispatch. If the response is lost, reconcile by hostname **and matching `_data` operation/account**. Unknown absence after a sent create never causes blind creation. Uncertain task submission/update similarly stops for operator reconciliation. Operator retries should inspect platform records; never delete the pending marker to force an unverified retry. Failed bootstrap/initial tasks currently need explicit diagnosis and a new verified task ID; the generic Reconcile button does not pretend a terminal failed task can succeed unchanged.

Canceled/past-due sites become offline through the site plugin, retaining authenticated export. Every deletion re-fetches billing state and verifies the exact atomic ID. Names remain tombstoned after deletion. Cancel/refund an unresolved initial setup only after 24 hours and positive proof that no ready site or running native task exists. Network failure is not proof. Cancellation/refund idempotency keys persist across retries. Ambiguous payment/provisioning cases remain operator-visible rather than creating another paid site.

## Backup and restore drill

Back up the Hub database, plugin packages/manifests and protected encryption key and Auth0 client configuration separately. WP Cloud site backups must cover customer database, runtime and release artifacts. Restore a disposable Hub copy without exposing production hostname, outbound email, live Stripe webhooks or native dispatch. Load the original encryption key and verify one fixture credential decrypts; verify OAuth tokens as appropriate, then rotate/revoke fixture credentials. Restore one customer site and compare snapshot generation, release manifest and private asset access. Document retention/deletion behavior for provider backups. Record actual restore evidence before launch.

If the encryption key is lost, do not overwrite encrypted values with new ciphertext under an unrelated key. Pause signup and reconcile recovery first. Key rotation requires decrypt/re-encrypt under a migration procedure and a backup of the previous key; Auth0 signing-key rotation uses provider JWKS discovery and must be verified before retiring old keys. Rotating a per-site secret is separate and supported by the operator action.

## Launch evidence

Use the gate table for dated evidence of task concurrency, runtime memory, provisioning, DNS/TLS, email delivery, OAuth, tenant isolation, backup restore, published ChatGPT integration, billing and policies. Also confirm task/retention pricing with the account agreement; $4.99 after the stated $5 site cost excludes Stripe, Hub, support and other costs. There is no automatically assumed profit margin. Public subscriptions stay disabled while any required evidence is missing.

## Customer identity migration

Follow [Auth0 sign-in](auth0.md). Existing owners need a fresh verified Auth0 attempt and explicit operator linking; blog and billing records are preserved. Retired provider routes and local OAuth endpoints cannot issue credentials. Reconnect ChatGPT after cutover. Customer local passwords remain disabled, while restricted operator recovery remains available.

## Live billing configuration (September 16)

The Hub now uses the separate Dashless live Stripe account and an explicit `DASHLESS_STRIPE_PORTAL_CONFIGURATION_ID`. Checkout and Portal return to `/#account`. The previous sandbox webhook is disabled; future sandbox testing must use an isolated endpoint. See [billing deployment and exact test boundaries](billing-deployment-2026-09-16.md). Live keys alone do not enable customer checkout; all launch gates still apply.
