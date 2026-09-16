# ChatGPT app result — 2026-09-16

Implemented and tested the customer-facing PHP Hub adapter and MCP Apps component. **Not submitted, not published, and not yet production-ready.** No infrastructure, payment, customer site or public listing was changed. Live checkout gates remain closed.

## Supported behavior

- Stateless Universal `/mcp`, protocol negotiation for 2025-11-25/2025-06-18/2025-03-26, JSON responses, 202 notifications, explicit unsupported GET, request/Accept/origin validation, resources/list/read/templates, OAuth discovery and HTTP + tool-level challenges.
- Existing League OAuth is preserved; authorization error redirects also receive issuer identification. Account/site routing is always derived from token identity. Predefined public OAuth client with exact configured redirect; no claim of CIMD/DCR/OIDC support.
- Original 23 schemas unchanged. Metadata overlays accurate scopes/annotations and component bindings. Three additive Hub-only tools: `show_workflow`, `request_rollback_approval`, `import_chatgpt_file`.
- Accessible branded component with real The Rip/Hot Type artwork, phase-specific queued/running/failed/completed status, capped polling/backoff, refresh, browser review with media supplied through ChatGPT attachments. It never treats an unverified deployment as live.
- Browser approval fallback is deliberate: no documented widget message is used as proof of a human event. Review metadata is private `_meta`, owner/site/disconnect-epoch bound and expiring. Verified WordPress cookie + REST nonce + allowed origin are required for approval. The site creates/consumes immutable approvals. Rollback review additionally binds target manifest and current active release. Model flags/arbitrary IDs do not grant approval.
- FileParams uses the current four-property schema. Exact configured OpenAI download origin, bounded safe fetch/no redirects, MIME/image/size checks, owner session and immutable retry binding. Raw image bytes relay to the site's service-authenticated content endpoint. PNG/JPEG/WebP ≤8 MiB, ≤40MP. Default download-origin list is empty until a real ChatGPT transfer is observed and pinned.
- Preview credentials never enter tool text/structuredContent; arbitrary upstream `_meta` is discarded. Browser preview framing is restricted to the signed-in owner's site. Export route recognizes the actual `export_id` from completed jobs, preserving an old job-ID fallback.

## Files and artifacts

Owned source: `hosted/chatgpt/` (component, PHP adapter, tests, build/package scripts, contract/connection/security docs, listing and review materials).

Integration edits: `hosted/hub/src/Mcp.php`, `OAuth.php`, `Routes.php`, `Previews.php`, plus the coordinated narrow `Jobs.php` checkpoint continuation fix. Packaging/check support: `hosted/scripts/package.mjs` includes the required adapter in the standard Hub package; `check.mjs` skips developer node_modules. No site-runtime, billing/provisioning, marketing/theme, original branding or local MCP implementation edits were made by this workstream. Concurrent site-task changes are preserved.

Artifacts:

- `hosted/chatgpt/dist/workflow.html` — self-contained WP Cloud-hostable resource.
- `hosted/chatgpt/dist/dashless-hub-chatgpt-0.1.0.zip` — full Hub update with Composer dependencies and ChatGPT adapter.
- `hosted/chatgpt/dist/release-manifest.json`, `manifest.json`, `dependencies.json` — SHA-256, byte counts, dependency/license inventory. ZIP additionally includes per-file hashes and runtime license notices.
- `hosted/dist/dashless-hub-0.1.0.zip` — standard package now includes the same adapter.
- `hosted/chatgpt/submission/` — listing copy, support/privacy/terms targets, isolated reviewer setup, seven positive/seven negative scenarios, selected mark icons, desktop/mobile screenshots of actual implementation and fixture provenance.

Hot Type is raster concept artwork, proportionally optimized for display. It is not a completed vector master. No substitute font or invented mark, and no forced branding on customers' blogs.

## Results

- Existing local workflow: **44 tests passed**.
- Existing Hub integration: **46 checks passed**.
- Added ChatGPT/Hub security and two-account service-fixture tests: **101 checks passed** (plus baseline 46).
- Actual PHP HTTP endpoint: **17 checks passed**.
- UI logic: **4 tests passed**.
- Actual component browser: **10 checks passed**, zero automated axe A/AA violations at desktop/mobile sizes.
- Hub → two actual local WordPress/SQLite sites → REST HTTP → real Astro → actual public HTTP release verification → rollback/export: **29 checks passed** (plus baseline 46). OpenAI download source and Cloud dispatch remain simulated; native jobs run explicitly outside HTTP. Browser-cookie enforcement is tested separately, not claimed as a full real-browser journey.
- Hosted syntax/schema checks and both packaging paths passed.

The final two-site rerun passed all 29 checks after the shared runtime stabilized and its local verification manifest was refreshed. No integrity validation was bypassed. Per the user’s correction, the component contains no media-upload or image-picker UI; media comes through ChatGPT attachments and fileParams. Status copy uses plain language, and internal job IDs are hidden.

Reproduction commands and detailed limitations: `hosted/chatgpt/docs/verification.md`.

## Coordination and compatibility

Coordinated directly with the site-plugin task. Adopted its existing `/uploads/<uuid>/content` binary relay and `/approvals/rollback` routes. The site task also requested a backward-compatible Hub job continuation: only a known, terminal native task plus an explicit `queued`/`continuation_required:true` site checkpoint can requeue the same UUID; a later tick dispatches it. Six added positive/negative checks cover uncertain/running task states. Export downloads honor the site archive format (ZIP or tar). No competing endpoints or renamed base schemas. Site task added the tested `chatgpt_upload_v1` and `rollback_approval_v1` capabilities; the Hub refuses those extensions when absent. ISO expiry and the site's larger upload limit are handled by the stricter Hub limit. Exact envelopes and compatibility rationale: `hosted/chatgpt/docs/site-contract-extension.md`.

Real HTTP integration caught local setup warning/permalink issues and the export artifact-ID distinction. The site task received those findings. The two disposable installs are separate from its test database.

## Submission status and blockers

No real ChatGPT connection, public submission or publication was performed. No final reviewer credential or verified publisher identity was supplied. The app still requires real WP Cloud deployment/HTTPS/egress and two-worker native acceptance, real ChatGPT OAuth/component/file transfer plus model-transcript checks, real two-subscriber browser journeys, tested reviewer credentials, final public policy/support pages and availability decisions, domain verification/Scan Tools and review. Current OAuth does not implement enterprise OIDC email/UserInfo domain restriction support; verify this with the current submission requirements before submitting.

The canonical listing URLs are prepared, not certified as final public policies. Developer mode and fixture screenshots do not satisfy the published-app gate. The completed local build is reviewable; all remaining launch requirements are explicit in `hosted/chatgpt/submission/readiness.md`.
