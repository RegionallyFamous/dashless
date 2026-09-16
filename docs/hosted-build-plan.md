# Hosted Dashless: WP Cloud-only build plan

> Current execution candidate: [native WP Cloud task/WP-CLI dispatch](wpcloud-build-isolation-review-2026-09-16.md), with one site explicitly targeted per build. This replaces the earlier long-lived HTTP worker recommendation, pending concurrency validation.

> Follow-up: the [two-worker concurrency test](wpcloud-two-worker-test-2026-09-16.md) completed the full build but failed interactive responsiveness: concurrent reads and a second-job submission timed out. Do not treat standalone build success as proof of usable same-site concurrency.

Revised 2026-09-16. This supersedes the earlier external-service architecture. Feasibility tests are complete and cleaned up; no hosted product has been deployed.

The [on-demand HTTP test](wpcloud-on-demand-probe-2026-09-16.md) completed Teddy's full build on WP Cloud: 115 posts, 240 HTML pages, 115 social images, and 1,111 files in 86.526 seconds. An authenticated request queued a separate PHP worker request; no cron was involved. A private runtime/library bundle and a local WordPress content/media snapshot made this path work. Production still needs dedicated capacity, leases, retries, tenant isolation, durable artifact/key storage, and ChatGPT OAuth/MCP integration.

## Constraint and scope

All Dashless application hosting, accounts, databases, jobs, builds, previews, and artifacts must run on WP Cloud. ChatGPT remains the external interface the user requested. No external application server, database, object storage, identity provider, or hosting panel is assumed.

Start with an invited, free beta: one account, one separately provisioned WP Cloud blog, an assigned platform subdomain, conversational content editing, design settings, private previews, explicit approval, publishing, and rollback. Defer custom domains, teams, arbitrary plugins, arbitrary generated executable code, and billing.

The platform's own DNS remains a prerequisite; WP Cloud documents DNS as the partner's responsibility. Payment processing is not a WP Cloud capability established by this plan. ChatGPT, DNS, and any future payment gateway must not be represented as hosted on WP Cloud. A paid release needs explicit agreement on a payment-provider exception.

## Proposed architecture

A dedicated WordPress site on WP Cloud runs the Dashless Hub plugin. It supplies signup/login, account pages, site memberships, provisioning, OAuth/MCP endpoints, job records, approval records, and operator screens. Use WordPress identities and custom database tables rather than an external identity provider or PostgreSQL.

Each customer receives a separate WP Cloud WordPress site with the Dashless companion/site agent, canonical content, its own durable jobs, private previews, and Astro releases. Hub-to-site communication uses authenticated HTTPS with narrowly scoped credentials. Only the hub's provisioning component holds the fleet WP Cloud API credential.

ChatGPT calls the hub's authenticated MCP tools. The hub dispatches bounded operations to the correct site. Long tasks return job identifiers and status rather than holding a web request open. Public blogs remain available when the hub or ChatGPT is unavailable.

The hub is PHP/WordPress, not a persistent Node server. Its account website uses WordPress login. Its ChatGPT connection requires a vetted OAuth implementation meeting MCP authorization requirements; WordPress cookies and Application Passwords alone do not provide that integration. Prove the selected MCP transport through WP Cloud's proxy and caching behavior. Account, tool, and preview routes must bypass public caches.

## Proven runtime and remaining production gates

Retain Astro. The full build now works through a PHP-launched HTTP worker using a private Node 22.14.0 runtime and compatible shared libraries. PHP's web environment differs from the SSH environment, so the runtime package must include and verify the components needed by the actual web worker.

The test used one CPU, constrained native/runtime threads, serial post normalization, fonts, an internally generated WordPress snapshot, and direct reads of local upload files. Keep those as the initial conservative configuration and benchmark before increasing concurrency. The exact cause of the old SSH exit 131 was not established; the tested HTTP worker completed the full workload.

The initiating request returns a job identifier; a separate token-authenticated WordPress request runs the job. That worker occupies a PHP worker until it finishes. Put build capacity on a dedicated WP Cloud site/pool rather than assuming it is free background compute within a customer blog's worker allocation.

Proven: authenticated on-demand handoff, completion after the initiating request ends, persisted job status, duplicate-key handling, explicit failure status, the full Astro build, owner-authenticated artifact access, and an encrypted persistent-storage canary.

Remaining gates:

1. Reproducible supported runtime packaging, dependency updates, resource budgets, and failure diagnostics.
2. Leases, crash reconciliation, retry policy, and capacity isolation under concurrent use.
3. Durable encrypted artifact storage, key lifecycle/recovery, and all preview asset routes in the browser. Private temporary working files are not durable storage.
4. OAuth/MCP through ChatGPT, per-account authorization, and cross-account negative tests.
5. Fully internal provisioning/bootstrap and real approval-bound deployment using the hosted service.

The API supports scheduled shell commands, but minute-level cron needs advanced-cron access on this account. It is not required by the proven request-triggered path; schedule recovery and housekeeping only as needed. WP Cloud documents that SSH HOME is unavailable to HTTP requests; use deliberately shared working storage and keep persistent artifacts encrypted rather than placing plaintext drafts at public uploads URLs.

## Code changes and reuse

Preserve the local Codex plugin and its existing tests.

- Reuse server/lib/frontend.mjs and templates/astro in a finite CLI build runner on WP Cloud, subject to the runtime gate. Supply explicit site context instead of switching global active-site state.
- Reuse wordpress/dashless-wpcloud.php manifest verification, generation checks, release activation, and rollback.
- Add wordpress/dashless-hub/ for accounts, authorization, MCP routing, provisioning, and wp-admin operator screens.
- Add site-agent operations for WordPress-native draft/stage/preview/publish and local jobs without duplicating the companion's release logic.
- Add scripts/hosted-build.mjs for the bounded build command.
- Port the editorial digest/staging/approval contract to PHP with shared fixtures proving agreement with the JavaScript implementation.

Use custom database tables for memberships, provisioning attempts, jobs, idempotency keys, changesets, previews, approvals, design versions, releases, and audit events. WordPress remains the only editorial source of truth.

Store credentials encrypted using a protected server-side key separated from database records. Verify supported key placement, rotation, and recovery on WP Cloud. Keep the hub's plugin surface minimal. Never expose fleet credentials or arbitrary shell commands to customer sites or model tools.

## Provisioning and durable jobs

Use database leases, heartbeats, retry limits, dead-letter states, and per-site serialization. Start finite worker requests directly when customers request builds, using the proven WordPress HTTP handoff. Allocate long-running build work separate from customer-facing traffic on WP Cloud. Use a supported lower-frequency schedule for recovery and housekeeping if needed; advanced cron is not a prerequisite for the proven request path. Do not rely only on visitor-triggered WP-Cron or persistent daemons.

Signup proceeds through recorded steps: verify account/invitation; reserve platform subdomain; create provisioning operation; create WP Cloud site; persist external ID; configure hostname/HTTPS; install pinned companion and credentials; configure worker; generate default design; build and verify an empty publication; mark ready; connect ChatGPT.

Reconcile timed-out creation against the reserved hostname before retrying, so lost responses do not create duplicate billable sites. Checkpoint external effects and expose operator repair states. Do not automatically delete a possibly valid site after a network failure. Prove a supported bootstrap path through the API or internal SSH access without an outside server.

## Publishing and previews

Preserve five distinct states: saved, preview built, published in WordPress, deployed, publicly verified. A failed deployment retries without duplicating an accepted WordPress publication.

Bind approval to account/site, exact content digest and revision/generation, design/source version, assets, and build inputs. Changes invalidate approval. Atomically claim an approval for one operation, serialize publication per site, and reconcile after crashes. Preserve the previous verified public release through failed build/upload/activation and use rollback after failed public verification.

Private previews must protect both HTML and media from direct unauthenticated access. Ordinary public WordPress uploads do not satisfy confidential draft-media requirements. Validate protected storage and serving before beta.

Start with an authenticated preview page with an explicit approval control. ChatGPT links to it and reports the publication result. A tool-supplied boolean or possession of a preview URL alone is not publication authority. In-chat approval can follow after host integration testing.

## Minimal interfaces

Host signup/login, My Blog, Connect ChatGPT, job status, recovery, export, and support pages on the hub. Use restricted wp-admin screens for initial operator workflows: provisioning failures, jobs, releases, credential rotation, and recovery. No separate dashboard framework is required.

Design tools edit a validated versioned configuration: colors, typography presets, layout options, navigation, logo, and site identity. Preview and approve design changes before release. Do not seed invented editorial content or allow arbitrary code/packages in v1.

## Delivery sequence

1. Finish service integration gates: build execution is proven; now validate OAuth/MCP, durable preview/key storage, and internal provisioning/bootstrap. Preserve the measured build baseline.
2. Hub and provisioning: accounts, memberships, schema, resumable jobs, empty-site creation, and operator repair screens.
3. Editorial engine: site-local drafts, staged edits, previews, approval, publication reconciliation, media privacy, and rollback; test PHP/JS contract parity.
4. ChatGPT workflow: narrow tools, accurate status, reconnect/revoke, and supported image handoff or a secure WP Cloud-hosted upload-page fallback.
5. Design workflow: controlled settings, exact design previews, approved release, and content/source export.
6. Invite beta: real-user testing, limits, costs, restore drills, hosted privacy/support documentation, and integration submission preparation.
7. Optional paid release: evaluate WordPress-hosted checkout/subscriptions. Any external payment gateway must be explicitly accepted; none is part of the free beta plan.

## Acceptance criteria

- Local plugin checks still pass.
- Two accounts cannot access each other's content, jobs, previews, files, credentials, or releases.
- Duplicate requests and terminated workers do not duplicate sites, posts, or publication.
- Stale, reused, or changed-design/asset approvals fail.
- Failed deployment preserves or restores the prior verified public release.
- Direct draft/asset paths cannot bypass authentication.
- All Dashless execution and data storage occur on WP Cloud.
- Database, credential, and artifact recovery and account export work without ChatGPT.

First milestone: an invited user receives a blog, connects ChatGPT, creates a draft, sees a private preview, approves, and gets a verified public post entirely through WP Cloud-hosted Dashless services. Repeat with a second isolated account and an interrupted job.

The earlier 6–10 week estimate assumed external managed services and is withdrawn. Use the now-proven build path to re-estimate after the OAuth, durable-storage, and bootstrap integration spikes.

## Sources and remaining uncertainty

- WP Cloud current API including cron: https://wp.cloud/docs/api/openapi.json
- SSH capabilities, filesystem contexts, and limits: https://wp.cloud/wp-cloud-technical-details/wpcloud-site-ssh-and-sftp/
- DNS, SSL, and provisioning responsibilities: https://wp.cloud/faqs/
- MCP authorization: https://developers.openai.com/plugins/build/auth
- Public integration review: https://developers.openai.com/plugins/deploy/submission

SSH is documented and tested. Node/Astro and request-triggered PHP execution are demonstrated. Production runtime support, durable preview/key storage, queue recovery, hosted OAuth/MCP, and internal bootstrap remain integration/release gates.
