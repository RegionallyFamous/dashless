# Hosted contract v1 — frozen integration boundary

This is the shared contract for the **separate site-plugin / hosted editorial-tool task**. `wordpress/dashless-wpcloud.php` and `server/dashless-mcp.mjs` remain the local product. Do not change global local connection storage into a hosted shared tenant store. Add hosted mode beside it.

## Transport and identity

Hub → site: HTTPS `/wp-json/dashless-hosted/v1`, `Authorization: Bearer <per-site secret>`, `X-Dashless-Contract: 1`, JSON. Site stores only SHA-256 of a random 256-bit secret; compare in constant time. Hub encrypts the secret with a server-held key. Never send the fleet key to a site. Disable caching for every private route, including failures. Reject unauthorized requests before looking up any artifact.

Every JSON response includes `contract_version: 1` (integer) and `site_id` (the atomic ID). Hub verifies both. Errors have a stable code, safe human message and HTTP status; never return paths, SQL, stack traces, credentials, process environment or raw subprocess logs. IDs are UUIDs, not local paths. Hub resolves the site from the authenticated OAuth account; tool arguments cannot select another tenant.

OAuth/MCP live on the Hub: `/mcp`, `/oauth/authorize`, `/oauth/token`, `/oauth/revoke`, standard protected-resource/authorization-server discovery. Pre-register the exact public ChatGPT client/redirect. S256 is mandatory; resource is exactly `https://dashless.blog/mcp`; access tokens last 15 minutes, codes 5 minutes, refresh 30 days. Scopes: `blog:read`, `blog:write`, `blog:publish`. Disconnect revokes an account's token chains. No infrastructure commands or commerce tools are advertised.

## Required endpoints

| Method/path | Contract |
|---|---|
| GET `/capabilities` | `plugin_version`, booleans in `capabilities`: `jobs`, `private_previews`, `approvals`, `exports`, `design`, `runtime`; versioned runtime digest |
| GET `/health` | `ready`, current release ID, sanitized runtime/install status; never infer readiness just from PHP responding |
| GET `/diagnostics/uncached-page` | Operator/shared-site credential only; render the actual uncached public front page, return `X-Dashless-Cache: bypass`. Used by the three-user acceptance harness, never advertised as a customer tool. |
| POST `/entitlement` | `{active, retain_until?}`; atomically block public rendering, editing and builds when false, invalidate public caches; retain authenticated export/recovery. Re-enable only on true. |
| POST `/jobs` | `{job_id, kind:"initial_release", idempotency_key}`; return existing equivalent job on replay, conflict if changed |
| GET `/jobs/<uuid>` | Job envelope below; short request, no build execution |
| POST `/tools/<name>` | `{arguments, actor:{account_id}, idempotency_key?}`; names and JSON schemas exactly `hub/contracts/tools.v1.json` |
| POST `/previews/<uuid>/browser` | Hub-only browser handoff, `{account_id,ttl:60}`; one-use URL on the site `/wp-json/dashless-hosted/v1/previews/…` path establishes an authorized private preview session, including assets. No scripts required in browser fallback. |
| POST `/approvals` | Hub-only human event `{preview_id, actor:{account_id,source:"authenticated_browser"},idempotency_key}`; validate immutable preview, latest snapshot and ownership, record/reuse approval, return `approval_id`. Do not expose as a customer MCP tool. |
| POST `/exports/<uuid>/download` | Hub-authenticated, owner-bound completed export; return `url` on this site's `/wp-json/dashless-hosted/v1/exports/…` path, one use, 60-second maximum TTL. Authorized browser only, never model-visible. |

All tools that start expensive work **only persist jobs**. Initial build, preview, deploy, rollback and export are executed by the same bounded runner. A queued operation is not a successful publication. Return job IDs immediately; include a safe next action such as `get_job`.

```json
{"contract_version":1,"site_id":152056190,"job_id":"8ade6ad0-7b9e-4ba5-98cf-9919ed81e401","status":"queued","next_action":"get_job"}
```

Job states: `queued`, `running`, `succeeded`, `failed`, `canceled`. Terminal records include outcome, attempts, start/end timestamps, measured memory peak, runtime digest and any release/preview IDs. Internal records additionally retain lease owner/expiry, task correlation and sanitized errors. Never put secret preview URLs in public job results. Successful initial release includes `release_id`.

Publishing outcomes must report separately `saved`, `preview_ready`, `published_in_wordpress`, `deployed`, `publicly_verified`. A saved post with a failed deploy is not reported as live. Idempotent retry resumes deployment without repeating the WordPress publication.

## WP-CLI contract and resource limits

- `wp dashless bootstrap --operation=<uuid> --site-id=<atomic-id> --hub=<https-origin> --credential-hash=<sha256> --package-sha256=<sha256>`: idempotent operation, initialize secret hash and hosted tables; require a matching immutable installed package/runtime manifest; remove sample content without inventing posts. Refuse identity changes after bootstrap. No account secret in process arguments.
- `wp dashless build-job <uuid>`: acquire durable per-site lease, validate entitlement (allow retained exports), claim exactly this job, run snapshot/build/deploy/export as appropriate. An already terminal job returns its recorded result without repeating work. Queue rows never disappear when a process dies.
- `wp dashless rotate-credential --credential-hash=<sha256>`: install pending hash idempotently. Keep previous credential only for a short, explicitly bounded verification overlap; rotation cannot change site identity.

Hub dispatches one atomic site per `site_run_list`, with `site_count_limit=1`; no customer controls command arguments. WP Cloud task limit is 300 seconds / 1,200 MB. **Set internal build deadline ≤240 seconds**, terminate/reap child processes at deadline and persist failure without activating the candidate. Measure peak RSS for the complete process tree, not just `memory_get_peak_usage()` of PHP. PHP defaults stay 512 MB, two workers, bursting zero; subprocess limits must be measured under that actual plan.

Pinned Node/Astro dependencies are prepackaged with SHA-256 verification. Never run `npm install` per customer build. Take a consistent content/design/asset snapshot with a generation check; read media locally. Preserve previous release until the candidate passes manifest, route, asset and public verification. Staging, locks, previews, exports and previous-release artifacts remain on WP Cloud.

## Approval and privacy requirements

`request_publication_approval` requests a component/fallback control; it **does not approve**. The server records a human-authenticated event, bound to account, site, preview ID, content generation/hash, design version/hash, asset manifest/hash and release candidate. Approval has expiry and atomic one-time consumption. A model-supplied flag, tool call, arbitrary approval ID or stale preview cannot mint approval. Changed content/design/media, replay and wrong owner all fail. Rollback requires equivalent explicit approval for the exact target release.

The ChatGPT component receives credentials only through widget `_meta` or a user-authenticated browser session. `structuredContent` and text never contain preview tokens. Private preview HTML **and every asset URL** require authorization; random public upload filenames do not qualify. CSP restricts framing/resources. The browser fallback must enforce the same approval and snapshot binding. Hub account has no content/design editor.

Media upload sessions enforce owner/site, MIME sniffing, size limits, expiry, one-use completion and supported ChatGPT file transfer. No arbitrary file-system paths, executable upload or model-selected remote URL fetch. Export includes content, media, design and manifest; no infrastructure credentials. Large exports must support bounded execution and resumable preparation. An export job requested twice with the same client key is the same job.

## Implement and review in the separate task

Reuse current content methods, staged/revision workflow and release checks; replace local staged storage only in hosted mode. Add durable approvals/jobs, curated design versions, native runtime, preview protection, export endpoint, component/resources metadata and approved publication tool. Connect the existing 23+ content tools using contract schemas; avoid arbitrary PHP, shell, plugins or generated executable design code.

Do not expose a partly working tool as successful. Capability flags stay false until implemented and tested. The Hub deliberately refuses provisioning when any mandatory capability is absent. Add test fixtures for two accounts, stale/design/media change, approval replay, broken build, killed process, failed activation and retry, callback spoof, direct draft asset access, retained exports and old local workflow regression.

The published ChatGPT app connects existing subscribers only. No prices, checkout, subscription creation, payment links or upgrades in its tools/components. Prepare an isolated reviewer account that works without an email challenge; do not bypass real-user authorization or expose real customer data.

Hub browser fallback is `/preview/?preview=<uuid>`. Its Publish form requires a signed-in verified owner and WordPress REST nonce; it mints approval only through this human form, then submits the approved publication. Site must make both operations idempotent under the supplied key and reject changed/replayed approval payloads. Reopening a completed identical request may return its original result without publishing again.

## Railway build driver (September 16 scope update)

The owner approved Railway as an external build service. The public MCP/site contract is unchanged. Native `build-job` invocations prepare or collect a build, then return a queued job with `continuation_required=true` and `poll_after`. Hub dispatches a continuation only after the prior WP Cloud task is terminal. The native 235-second operation deadline remains; the Node/Astro child runs on Railway with a separate 240-second deadline. Initial provisioning handles the same continuation signal.

Hub configures the site's authenticated `/builder` endpoint with the Railway URL and a site-scoped token. The master derivation key stays only on the Hub and Railway. The builder receives no fleet API, Stripe, WordPress service, or publication credential. Existing snapshot/generation/manifest checks, private previews, human approval, activation verification, rollback and previous-release preservation remain authoritative on WordPress.

Automatic package installation remains mandatory. Railway bootstrap verifies the site ZIP and portable frontend sources, but skips downloading/installing the Linux Node runtime on customer sites. PHP workers remain two with bursting zero. Existing native/local mode remains supported.
