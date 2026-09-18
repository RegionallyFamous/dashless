# Dashless launch audit — September 16, 2026

**Decision: not ready for paid launch or public ChatGPT submission.** All three pieces have substantive implementations and local tests. The largest remaining gap is proving the complete deployed customer lifecycle. No checkout, production deployment, provider setting, or submission was enabled by this audit.

## Repository and component map

Private source of truth: https://github.com/RegionallyFamous/dashless-platform. One monorepo preserves the shared contracts, frontend, lockfiles and coordinated release changes. The deployable packages remain separate:

- ChatGPT: `hosted/chatgpt/`; PHP MCP adapter and UI ship inside the Hub ZIP.
- Customer WordPress plugin: `wordpress/dashless-hosted.php` and `wordpress/hosted/`; automatically installed pinned site ZIP.
- Hub: `hosted/hub/` and `hosted/theme/`; accounts, authentication, subscriptions, provisioning and marketing.
- Supporting builder: `hosted/railway/`, `hosted/runtime/`, `templates/astro/`. Railway is part of the launch surface even though it is not a fourth customer-facing product.
- Original local Codex plugin: `.codex-plugin/`, `server/`, `skills/`. Do not confuse its existing 1.0 acceptance with hosted-service acceptance.

The existing `RegionallyFamous/dashless` repository was public. It remains available as `public-origin`, with local pushes disabled. `origin` and the default push destination now point to the private repository. Existing source history is preserved. Secrets, local fixture state, dependencies and generated archives are excluded from source control. A pattern scan of candidate source files found only two known invalid-URL test fixtures; this is not a comprehensive secret-security certification.

## Findings addressed in this audit

1. **Critical dependency advisory:** hosted runtime pinned Astro 7.2.0. Updated runtime and shared template to exact 7.2.8; resolved Sharp is 0.35.4. Refreshed vulnerable template transitive dependencies. Fresh production dependency audits report zero advisories for those two locks and ChatGPT; Composer reports no advisories/abandoned packages. [Astro advisory](https://github.com/advisories/GHSA-26w7-cxv4-gfx2). **The deployed Railway image and existing runtime artifacts are not patched by a Git commit: rebuild, deploy and verify them before accepting customer inputs.** This audit did not establish exploitability of the deployed service.
2. **Missing hosted CI coverage:** added a dedicated hosted workflow for PHP/JS/contract checks, dependency audits, component/browser tests, Railway/runtime tests, real WordPress/League integration using external-service fixtures, and Hub/theme packaging. Existing WordPress compatibility and frontend workflows remain. Cloud/customer-site integration and capacity evidence remain separate gates.
3. **Stale integration assertion:** component resource test expected an obsolete heading. It now verifies nonempty exact packaged HTML, so copy changes cannot conceal a missing or different resource.
4. **Template copy hygiene:** generating a frontend copied development dependencies if installed in the template. Excluded dependencies, build/cache directories and environment files. The existing clean-install test caught this during the dependency update.
5. **Incomplete syntax coverage:** hosted checks now include the customer WordPress plugin, not only files under `hosted/`.
6. **Stale launch docs:** several handoffs described native WP Cloud Node failures as the current architecture. They remain historical evidence; Railway is the current build path. Capacity, memory and recovery evidence must now cover Railway plus WP Cloud snapshot/transfer/activation, not demand a successful obsolete native build.

## ChatGPT plugin

Implemented: OAuth/PKCE, 26 tools (23 shared plus 3 additive), component progress UI, private browser approval, attachment relay, tenant-bound routing, model-visible credential filtering, rollback/export handoffs, submission drafts and reviewer scenarios.

Before submission:

- [ ] Complete two real isolated account journeys in actual ChatGPT against deployed WP Cloud sites: connect, attach image, save draft, preview, browser approve, verify public release, edit, rollback, export, disconnect/reconnect. Retain sanitized evidence and actual ChatGPT screenshots.
- [ ] Confirm live OAuth client/redirect, widget origin/CSP, Safari/mobile browser sign-in and secure cross-surface handoff. Test anonymous/private media denial and cross-account access in production-like HTTPS.
- [ ] Verify the actual ChatGPT file origin/envelope and expired/replayed download behavior. Fixture file transfer does not establish compatibility.
- [ ] Prepare usable isolated reviewer accounts and a verified publisher identity; complete domain challenge, tool scan and accurate annotations; submit and publish as separate recorded steps.
- [ ] Resolve workspace domain restriction support: live discovery currently advertises blog scopes only, no `openid`, `email` or UserInfo endpoint. Do not claim enterprise domain restriction support. Official submission guidance requires UserInfo email claims and the scopes for that capability; record the applicable submission route and decision.
- [ ] Finalize public publisher/support/privacy/terms/retention/region information and production brand assets.

Current official reference: [Submit plugins](https://developers.openai.com/plugins/deploy/submission), checked September 16. Local MCP Apps bridge screenshots do not substitute for actual ChatGPT review evidence.

## Customer WordPress plugin and builder

Implemented/tested locally: private drafts/media/previews, immutable snapshot approval, job leases, build verification, exports, credential rotation, staged edits, rollback interfaces and suspended-account behavior. Railway fixture transport from WP Cloud previously passed; native full-build failures are historical, not resolved native acceptance.

Before accepting subscribers:

- [ ] Fresh automatic installation from the exact pinned ZIP, identity/bootstrap verification, DNS and certificate creation, empty first release and real public response/release ID. A provider success response previously failed to install software on the Hub; verify actual installed files and activation on customer sites.
- [ ] Rebuild and deploy patched Railway image and source/runtime packages; record commit, hashes, image/deployment identifier and rollback target.
- [ ] Complete the real media-heavy workload (115 posts / approximately 150 media) through Railway, including snapshot upload, continuations, archive download, exact approval and WP Cloud public activation. The prior 115-post/one-image fixture is insufficient.
- [ ] Prove two consecutive jobs and multiple customers remain responsive with two customer PHP workers/no bursting; measure uncached three-reader p95, errors, PHP memory and Railway memory/queue age. Investigate the observed provider task conflict rather than assuming fleet concurrency works.
- [ ] Exercise timeouts, worker restart, full volume, large uploads/output and lost responses. Preserve the previous public release and avoid duplicate publication. The builder currently reads the finished ZIP into memory; PHP archive download has a 20-second request timeout. Advertised maxima need measurement or lower supported limits.
- [ ] Prove cleanup and storage bounds for encrypted site previews/exports. The site store has no general expired-artifact garbage collector. Railway cleanup alone does not reclaim WordPress vault artifacts.
- [ ] Test exports at the supported quota and retention expiry. Revision/metadata preparation remains in memory and individual tar entries have an 8 GiB limit; do not advertise unlimited export capacity.
- [ ] Demonstrate a canary site-plugin upgrade, exact version verification and rollback. First bootstrap deliberately refuses silently replacing a pinned installation.

Railway currently runs one replica/one global build at a time, caps retained jobs at 100 and retains temporary data for 24 hours. Burst behavior, fair capacity and aggregate disk exhaustion remain unproven. See [builder operations](../hosted/railway/README.md). Confirm the documented Railway configuration-format migration deadline with the provider before relying on it.

## Hub

Implemented/tested locally: verified email links, isolated accounts, Stripe reconciliation/deduplication, grace/cancel/recovery/refund paths, provision orchestration, protected credentials, operator controls, launch gates and canary records.

Before charging customers:

- [ ] Deliver actual sign-in mail to representative inboxes; verify sender authentication, one-use/expiry behavior and support mailbox ownership.
- [ ] Real sandbox Checkout → paid webhook → provision → ready site, with Portal cancellation, failed renewal, grace, reactivation, expired retention, refund and event replay/out-of-order cases. Synthetic signed webhook success is insufficient.
- [ ] Prove the fresh-site flow and two-account isolation end to end; record deployed package hashes.
- [ ] Verify maintenance runs without traffic, retries/reconciliation are observable, and stuck jobs/failed webhooks/provisioning failures alert an operator. Code contains hourly maintenance and immediate native dispatch, but operational delivery is not established by local tests.
- [ ] Restore Hub database plus encryption/OAuth/builder keys and one customer database/vault into an isolated recovery environment; verify content, previews, exports and connection recovery. Exercise credential rotation and lost-key response.
- [ ] Finalize policies and support ownership, cancellation/refund/retention language, abuse/takedown process, incident handling and customer deletion procedure.
- [ ] Measure per-customer WP Cloud cost plus shared Railway, storage/egress, Stripe and support at the advertised $9.99 price; set enforceable supported quotas and an initial cohort/capacity limit.
- [ ] Run the canary and rollback drill, verify every launch gate and credential, then explicitly authorize live checkout. Never set gates passed just to remove blockers.

Read-only live checks in this audit: homepage, OAuth discovery and Railway `/health` returned HTTP 200. Discovery returned issuer `https://dashless.blog`, S256 and blog scopes. Builder returned `ok: true`, contract 1. These prove endpoint availability, not subscription/provisioning success. Private production launch-gate state was not inspected; deployment records say checkout remains off.

## Evidence required by the existing Hub launch gates

| Gate | Evidence to record |
| --- | --- |
| `task_concurrency` | Successful full Railway-backed jobs across customer sites with two-worker responsiveness and task-conflict recovery |
| `runtime_memory` | Measured Railway process/container and WP Cloud snapshot/transfer memory under supported workload |
| `provisioning` | Fresh exact-package customer setup through verified initial public release |
| `dns_tls` | New customer hostname/certificate plus routing and isolation |
| `email_delivery` | Real authenticated sender and received single-use sign-in link |
| `oauth` | Actual ChatGPT connect/refresh/revoke and browser handoff |
| `tenant_isolation` | Two real customers, negative cross-account/private asset cases |
| `restore_drill` | Database, vault and key recovery plus publish/export verification |
| `chatgpt_published` | Approved and published listing, not just submission |
| `billing_verified` | Real sandbox lifecycle plus separately reviewed live configuration |
| `policies` | Final identified publisher, support and policies matching behavior |

## Verification during this audit

- Baseline core: 45 tests passed before dependency changes. Final patched-source results are recorded below.
- ChatGPT: 4 state tests and 10 browser checks passed, including automated A/AA accessibility and no browser errors.
- Hub/ChatGPT integration: 46 baseline + 101 additional checks passed after correcting the stale packaged-resource assertion. Real WordPress/League, external services simulated.
- Customer site integration: 57 checks passed with real Astro 7.2.8 builds; public response verification used fixtures.
- Hub/theme and original local plugin package scripts completed. Generated ZIPs are build outputs, not source backups or deployed changes.
- Production dependency audits: runtime, template and ChatGPT zero reported vulnerabilities; Composer no advisories or abandoned packages.

Local tests do not certify real customer billing, provider provisioning, production backups, actual ChatGPT behavior or capacity. Historical local evidence is in `hosted/docs/verification.md` and component handoffs.

## Recommended launch order

1. Deploy patched build dependencies and pin the exact release candidate.
2. Complete one real sandbox purchase and fresh-site provision, then the second-account privacy journey in ChatGPT.
3. Complete capacity/failure/restore drills, mail and billing edge cases; resolve storage cleanup and operational alerts.
4. Finalize policies, reviewer credentials and OpenAI submission; publish after approval.
5. Run a limited canary, verify every Hub gate with linked evidence, then approve paid launch.

Final patched-source verification: `npm run check` passed 45/45; `npm run test:frontend` passed publication and browser gates (27 presets; 390/768/1280/320 widths); Railway/shared runtime passed 6/6; hosted syntax gate passed 84 checks and 23 shared schemas. GitHub-hosted workflow results are separate from these local results.

## Account simplification considered

WordPress.com Connect is a public identity-focused OAuth2 flow: `/oauth2/authenticate`, server-side `/oauth2/token` exchange, then `/rest/v1.1/me`. Register an application with client ID/secret and an exact callback. See [WordPress.com Connect](https://developer.wordpress.com/docs/api/wpcc/), checked September 16.

It can replace Dashless magic-link login, while retaining Hub account/subscription/site ownership and the Hub's OAuth provider for ChatGPT. Link identities using the stable WordPress.com user ID; do not silently merge existing accounts based only on matching email. Verify provider email semantics before marking an address verified or implementing OIDC email claims. State/replay protection, secure Hub sessions, sign-out and existing-account migration still need implementation and tests. Requiring WordPress.com accounts is a product decision; offering both providers keeps the email delivery dependency. No login behavior was changed by this audit.

## Subsequent account and demo deployment

WordPress.com-only customer sign-in and the live Small Problems example are now deployed. The homepage includes real screenshots and a live-demo link. See [deployment record](../hosted/docs/wpcom-demo-deployment-2026-09-16.md) for validation and remaining launch scope.

## Subsequent theme release and live submission audit

The named theme catalog, Hub/ChatGPT adapter, immutable customer packages and patched Railway builder are deployed. See the [theme deployment record](../hosted/docs/themes-deployment-2026-09-16.md) and [current submission blockers](../hosted/chatgpt/submission/readiness.md). Public discovery now exposes 28 tools. Live testing confirmed that ChatGPT OAuth configuration and a support contact are absent, policies remain drafts, and the domain challenge is missing. Neither public submission nor paid launch is approved by these deployment checks.

## Submission draft follow-up

Production Hub package `a1afdce658948e0a42c5b626907a0ecbdf6e2b0dbb6cea2d33783d1e1b03e3e1` installed successfully (WP Cloud task 759299). Public policies now identify Izzi’s Gym LLC DBA Regionally Famous, South Dakota, USA. Support forwarding is active. OAuth signing keys and a public PKCE client are configured using the actual portal callback. Anonymous GET and empty POST return metadata challenges. Final validation: 92 syntax checks, 19 local HTTP checks and live public readiness checks pass; 105 integration checks and eight OAuth entry checks passed earlier. Temporary deployment and discovery MU plugins were removed.

The real OpenAI draft contains listing material, scenarios and Allow all availability. Business verification now shows Approved in Organization Settings; the matching identity still needs selection in the draft. The portal still cannot discover OAuth metadata (`oauth_config: null`; “OAuth configuration not found”). Domain token/scan, one dedicated reviewer account, provisioned review fixture, real ChatGPT journeys and demo recording remain incomplete. A second identity is for internal isolation testing, not a requirement for two reviewer logins. No final attestation, submission or publication occurred. These improvements do not resolve the remaining billing/capacity/restore and operational launch gates above. See hosted/chatgpt/submission/readiness.md for current submission status.
