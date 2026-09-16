# Additive adapter agreement — 2026-09-16

The 23 tools in `hosted/hub/contracts/tools.v1.json` are unchanged. The Hub exposes three additional tools from `hosted/chatgpt/tools.json`: `show_workflow`, `request_rollback_approval`, and `import_chatgpt_file`. They are Hub-owned; the site never receives those tool names. Tool metadata overlays scopes, component resources, output schemas and truthful annotations without rewriting the shared schemas. `get_job` is marked a mutation because existing Jobs::status reconciles and dispatches queued work.

## Media transfer

Matches the site-plugin task's `/uploads/<uuid>/content` implementation, inspected on September 16. No competing `/complete` endpoint is needed.

1. Require `/capabilities.capabilities.chatgpt_upload_v1 === true`.
2. Accept OpenAI's documented four-field fileParams schema: required `download_url`, `file_id`; optional `file_name`, `mime_type`. Never accept a local path. Both optional properties remain optional in the descriptor, as required for Scan Tools.
3. Hub validates an operator-configured exact HTTPS `oaiusercontent.com` origin, rejects credentials/ports/fragments, disables redirects, applies safe HTTP network validation and bounded download. No site or OAuth credentials go to OpenAI's file host. Allowed origins are empty by default pending a real ChatGPT transfer observation; the official file API docs do not guarantee a stable download hostname. A file_id is not an authentication credential.
4. Hub sniffs PNG/JPEG/WebP, validates declared MIME, dimensions (40 million pixels) and size (8 MiB). It calls existing `create_media_upload` with the authenticated actor, sanitized filename, alt text and stable key. Missing filenames derive from the sniffed type.
5. Expected session: `{upload_id:<uuid>, expires_at:<ISO-8601 or Unix seconds>, max_bytes:<positive integer>, transfer:"hub_binary_relay"}`. Expiry must be future and at most 15 minutes. Hub enforces the lesser of site limit and 8 MiB.
6. Relay **raw bytes** using PUT `/uploads/<uuid>/content`, Content-Type sniffed MIME, site bearer credential, X-Dashless-Contract: 1, X-Dashless-Account-Id derived exclusively from OAuth. The model cannot choose destination, account, headers or upload UUID.
7. Site verifies owner/session/expiry/MIME, stores private bytes, and returns the usual `{contract_version:1,site_id,media_id,saved:true}`. Repeated identical completion returns the same media; changed bytes conflict. Hub persists only binding hashes/session/result, never temporary download URLs or raw bytes. A lock serializes completion; bounded retries preserve the original hash and key. Disconnect epoch invalidates pending Hub sessions.

Site supports additional formats and 20 MiB; ChatGPT adapter deliberately supports only the documented image subset above in v1. No paid transfer service or off-platform production host is introduced.

## Rollback

Require `rollback_approval_v1 === true`. The cookie+nonce Hub browser calls POST `/approvals/rollback` with `{release_id,kind:"rollback",actor:{account_id,source:"authenticated_browser"},idempotency_key}`. The site binds the exact previous release, manifest and active release, then returns an approval ID. Hub immediately submits existing `rollback_release`. A model tool cannot call the approval endpoint. The existing `/approvals` publication body is unchanged.

Rollback review tickets additionally bind the previous release manifest hash and the active release ID. The Hub rechecks both before emitting the browser approval. The export download route uses the completed job’s `export_id` when present; it retains the original job-ID fallback for older contract-v1 implementations.

These are explicit capability-gated additions to contract v1, coordinated with the site task. Older plugins fail closed for these two extensions and continue using existing tools. Capabilities are evidence of availability, not proof of production verification; run the real site tests before submission.

## Resumable export checkpoints

Coordinated site-task addition: after a bounded clean checkpoint, a site job may report `{status:"queued", continuation_required:true, progress:...}`. Only when the Hub has a known prior `task_id` and WP Cloud reports that task complete may Jobs::tick clear old task/reconciliation state and requeue the same job UUID. It returns immediately; the next tick dispatches the continuation. A missing task ID, unfinished native task, remote running state, or queued state without the strict boolean never permits automatic retry. Older site plugins report no continuation flag and retain original behavior. Tests cover all those negative branches. Portable exports may be ZIP or tar; the browser download uses the site's Content-Type and Content-Disposition. Terminal result retains `export_id`.
