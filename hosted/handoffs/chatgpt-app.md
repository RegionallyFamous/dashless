# Implement the Dashless ChatGPT app

## Objective

Build the customer-facing ChatGPT app (the “ChatGPT plugin”): remote MCP tools, authenticated preview/build-status/Publish component, supported media transfer and review/submission materials. Extend the existing PHP Hub on WP Cloud. Customers connect existing Dashless accounts; they do not configure infrastructure or buy subscriptions inside ChatGPT.

Working directory: `/Users/nick/Documents/ChatGPT/Dashless`. Implement and test the work, not just a plan.

## Read first

1. `hosted/README.md`, `hosted/docs/contract-v1.md`, `hosted/docs/operations.md`, `hosted/docs/verification.md`.
2. `hosted/hub/contracts/tools.v1.json` and `fixtures.v1.json` — current 23-tool interface, scopes and result envelopes.
3. `hosted/hub/src/Mcp.php`, `OAuth.php`, `Routes.php`, `Previews.php`, `Agent.php`, `Jobs.php`, `Identity.php` — actual PHP Hub implementation.
4. `server/dashless-mcp.mjs`, `server/lib/editorial.mjs`, `server/lib/storage.mjs` — existing local workflow, not a multi-tenant hosted session store.
5. `hosted/handoffs/wordpress-plugin.md` and, if present, `wordpress-result.md` for the other task's deliverables.
6. `brand/README.md`, `brand/post-punk-01/rip.svg`, `brand/logo-study-03/hot-type-v3.png`, `brand/direction-01/brand-guide.md` and `hosted/theme/assets/README.md`.

Use the OpenAI documentation skill and verify current official Apps/MCP authentication, component, file transfer and submission documentation. The implementation must follow current documented APIs; do not invent component APIs or assume a tool argument proves a human clicked Publish.

## Ownership and coordination

You own a new `hosted/chatgpt/` directory for component source, packaged static assets, test fixtures and submission materials; Hub MCP/widget integration in `Mcp.php`, `OAuth.php`, `Routes.php` and narrowly needed `Previews.php` changes; dedicated ChatGPT/MCP tests and packaging scripts.

The other task owns `wordpress/`, the site runtime/build runner and customer endpoints. Do not edit its implementation, Hub billing/provisioning, marketing/theme or original branding assets. You are not alone in the repository; preserve others' work. Shared contract changes require explicit documented compatibility treatment, not silent renaming or competing endpoints. Avoid shared-file edits when dedicated files suffice.

## Existing foundation and remaining work

Customer-site provisioning must install and activate the Dashless site plugin automatically before the site becomes ready. The Hub already requests its pinned ZIP with `activate-locked`. ChatGPT connects to that provisioned installation; customers must never be asked to install the WordPress plugin or supply infrastructure credentials themselves. Missing plugin/capabilities means setup is incomplete, not a successful connection.

The Hub already implements League OAuth authorization code + S256 PKCE, resource/audience checks, code/access/refresh persistence, rotation/revocation, exact configured redirect, scopes and PHP JSON-RPC tool routing. This is locally tested, **not verified with real ChatGPT**. MCP currently handles initialize, ping, tools/list and tools/call; complete current resource/component protocol support and metadata as needed. Do not replace this with an external Node/Cloudflare service.

All 23 schemas are already exposed; that does not mean the site plugin implements the corresponding operations. Use fixtures for independent development, then require real capability checks and integration before claiming readiness.

## Required implementation

- Complete a universal stateless remote MCP endpoint on `https://dashless.blog/mcp`, with correct current protocol negotiation, tool annotations/scopes, structured errors, resources/components and OAuth discovery/challenge behavior.
- Derive account and site exclusively from authenticated Hub identity. No model-supplied account ID, active global site, customer infrastructure secrets or cross-tenant caches. Preserve the local Codex mode and its existing tools.
- Implement the ChatGPT component for private preview, queued/running/failed/complete build status and explicit Publish. Show accurate phase-specific results. Poll short status endpoints with backoff; never run builds in an HTTP response. Explain recoverable errors without leaking internals.
- Build an authenticated user-event path for approval. Bind exact immutable content/design/assets/preview/candidate; consume once server-side. A model tool, `approved:true`, model-created event or arbitrary ID cannot mint approval. Ensure the widget route has a verifiable browser/user authentication boundary distinct from general model tools. If the platform cannot support that securely, use the existing cookie+nonce browser fallback and document the limitation.
- Reuse `Previews.php` browser fallback and the site's `/approvals` contract. Do not weaken approval for convenience. Rollback also requires explicit approval of the exact target release.
- Keep preview credentials in protected widget-only data or authorized browser sessions, out of text and `structuredContent`. Protect direct draft asset URLs, enforce CSP/origin restrictions and ensure refreshed sessions cannot change tenant. Test what the model can actually see.
- Add supported ChatGPT file/image transfer through authenticated upload sessions. Coordinate the transfer envelope with the site-plugin task; validate origin, size, MIME, expiry and owner. No arbitrary URL fetch or local filesystem path accepted from tools.
- Expose curated design, drafts, previews, approved publishing, job/release status, rollback and export using contract schemas. Do not expose WP Cloud, shell, fleet, Stripe, checkout, subscriptions, plan prices or upgrades.
- Apply selected **The Rip + Hot Type** identity to the app and listing. Reuse actual art; do not replace Hot Type with Georgia or invent another mark. Source Hot Type is raster concept art; report that limitation. Keep functional controls clear and accessible, especially Publish. No forced Dashless branding on customers' blogs.
- Prepare integration listing copy, icons/screenshots from the actual implementation, privacy/support links, isolated reviewer account setup, at least five positive and three negative review scenarios, and a reproducible developer connection guide. Do not claim public publication or submit/send messages without the relevant user authorization and configured account.

## Constraints

Application hosting, PHP endpoint, static component bundles, databases and artifacts all stay on WP Cloud. Local bundling/testing is fine; do not require an external production build host or paid service. Stripe and ChatGPT are the agreed external services. $9.99/month billing lives only on the website/Stripe. Customer supplies ChatGPT access.

One owner/site, curated design, no teams/newsletters/arbitrary executable code in v1. Custom domains are now in launch scope; see ../docs/custom-domains.md. Live checkout remains disabled until the site plugin, two-worker full-build responsiveness, tenant isolation, real two-account journey and published app pass. Neither developer-mode connection nor a mocked demo counts as public availability.

## Tests and deliverables

- Keep `npm run check`, `npm run check:hosted` and the local Hub integration tests passing; add protocol/OAuth/component tests and browser accessibility checks for your changes.
- Negative tests: wrong resource/redirect/scope/account, code and refresh replay, disconnect, spoofed callback, forged human approval, changed preview/design/assets, replayed publish, inaccessible private assets, unsupported/oversized uploads and failure recovery.
- Integrate two isolated subscribers through connect → draft → upload → preview → user approval → publish → verified release → rollback/export. Tests must distinguish fixtures from real WordPress/ChatGPT results.
- Produce WP Cloud-hostable component artifacts and a packaged Hub update with dependency/license manifests. No secrets in artifacts, screenshots, logs or reviewer instructions.
- Add `hosted/handoffs/chatgpt-result.md` with exact supported behavior, files/artifacts, test results, real connection status, submission readiness and remaining blockers. Include any proposed shared-contract changes for coordination.
