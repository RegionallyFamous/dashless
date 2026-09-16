# WP Cloud on-demand build test

> Follow-up: the [two-worker concurrency test](wpcloud-two-worker-test-2026-09-16.md) completed the full build but failed interactive responsiveness: concurrent reads and a second-job submission timed out. Do not treat standalone build success as proof of usable same-site concurrency.

Date: 2026-09-16 UTC. Target: teddy.blog, site 152056190. This supersedes the earlier inconclusive full-build result in `wpcloud-runtime-probe-2026-09-16.md`.

## Result

A real HTTP request to a temporary authenticated WordPress endpoint started a separate background HTTP request on WP Cloud. That request launched Node and built Teddy's complete Astro frontend on WP Cloud. The initiating client received a job identifier and could later retrieve its status and private preview. No cron job or external build worker was used.

The successful full build used all 115 published posts, one published page, 150 media records, 14 categories, and 87 tags at WordPress content generation 1012. It generated 240 HTML pages, 115 social images, and 1,111 files. The Node job took 86.526 seconds; the server recorded approximately 88 seconds from worker start through snapshot/build/artifact completion. The initiating endpoint returned HTTP 202. Separate measured submissions through the same endpoint returned in approximately 1.3 seconds.

This establishes the technical execution path. It is not a production service, a platform support guarantee, or a completed ChatGPT OAuth integration.

## Actual request lifecycle

1. A WordPress Application Password authenticated an HTTP POST requesting a fixed test operation. No arbitrary command or filesystem path was accepted.
2. The endpoint recorded the owner, operation, idempotency key, and queued job in WordPress options. Unique option insertion prevented duplicate submissions from starting another job.
3. `wp_remote_post(..., blocking=false)` invoked a separate, token-authenticated worker endpoint on the same WP Cloud site. The caller received a 202 response and job ID.
4. The worker recorded running state, prepared a consistent content snapshot, and launched the finite Node build through PHP `proc_open`.
5. The worker captured the exit code, result manifest, timings, and completed or failed state. A private artifact copy was associated with that job.
6. Authenticated status and preview endpoints retrieved the result in later HTTP requests after the initiating request had ended.

The worker is asynchronous relative to the customer request, but it occupies a PHP worker while the build runs. It is not a detached daemon and was not proven to survive termination of that worker request. A production implementation needs leases, bounded execution, crash reconciliation, retries, and capacity isolation.

## Why the first attempts failed, and what changed

### The web and SSH environments differ

PHP could access the shared private test directory and create subprocesses, but the official Node binary initially failed in the web context because required libraries were missing and the system glibc was older than Node requires. The successful test used a private bundle of Node 22.14.0 plus a compatible dynamic loader and supporting libraries copied from the site's SSH environment. Additional native dependencies required `librt` and `libresolv` in that bundle.

Nothing was installed into platform system directories. The bundle was temporary and was removed after testing. A production build must package/version/verify this runtime reproducibly rather than depend on ad hoc copies of host libraries. Platform support for that workload remains a separate question.

### Live HTTP reads were unsuitable for an internal build

The first HTTP-triggered full build progressed through native image generation but failed on a WordPress REST HTTP 429 response. The successful build took its snapshot through internal WordPress REST dispatch, checked the content generation before and after extraction, and supplied those responses directly to the build. Media reads used the existing local uploads files.

This avoids repeated HTTP calls back into the same site, edge-cache inconsistencies, and that REST rate-limit failure. It also establishes which content generation the build used.

### Resource use was bounded

The test copy processed posts serially instead of starting all normalization/image work with `Promise.all`. The worker used one CPU, a 384 MB V8 heap limit, reduced V8/libuv/Go/Rayon concurrency, Sharp concurrency of one, and disabled Sharp's memory cache. Font files and a Fontconfig configuration were provided for social-card rendering.

These settings are the configuration that passed, not a claim that each setting is independently necessary. The specific cause of the earlier SSH exit 131 was not established. The supported conclusion is that the tested PHP-launched workflow successfully performed the complete workload; the SSH invocation had terminated during social-image processing.

## Verification

| Test | Result |
| --- | --- |
| Start a build through authenticated HTTP | HTTP 202 and job ID; measured submissions approximately 1.3 seconds |
| Complete after the original request returns | Passed; separate worker request completed the build |
| Small Astro build with a deliberate five-second pause | Passed; job duration 7.763 seconds |
| Full Teddy build with all published content | Passed; 86.526 seconds in the Node job |
| Re-submit the same completed request | Returned the same job, with attempt count still one |
| Intentional child-process failure | Recorded failed state and exit code 7 |
| Anonymous worker invocation | HTTP 401 |
| Anonymous job status | HTTP 401 before the later edge challenge |
| Anonymous preview HTML and image | HTTP 401 before the later edge challenge |
| Authenticated preview HTML and image | HTTP 200, `Cache-Control: private, no-store` |
| Full preview matches built homepage | SHA-256 matched the build result |
| Check all expected story routes | All 115 present |
| Check generated social images | All 115 present; representative image visually inspected |
| Check local asset references | 4,883 references checked; no missing files |
| Persistent encrypted-artifact canary | AES-256-GCM round trip succeeded; plaintext absent from blob; tampering rejected |
| Decrypt persistent canary from authenticated PHP endpoint | Passed |
| One-minute cron | Not used or required for this workflow |

The traversal-negative test was rejected at the edge with HTTP 406. That proves the request was blocked, not that the application's own traversal guard was exercised. A subsequent source-client HTTP challenge produced unauthenticated 403 responses and some 429 responses. Its precise trigger was not established; no WAF or Defensive Mode settings were changed. Authenticated endpoint tests continued to work. After cleanup, an ordinary unauthenticated homepage request again returned HTTP 200.

The direct ciphertext URL test was obscured by that edge challenge, so direct anonymous download behavior is not claimed as verified. Encryption round trip, tamper rejection, and cross-context authenticated decryption were verified. The production design still needs a durable encryption-key strategy and a full private-asset gateway. The general generated-site preview UI, including every rewritten asset URL in a browser, was not end-to-end tested; the artifact audit and HTTP access checks are narrower evidence.

## Cleanup and final site state

- Removed the temporary must-use plugin; its authenticated environment endpoint now returns 404.
- Removed all ten temporary job/idempotency options; verified zero matching records remain.
- Removed the runtime, dependencies, copied fonts, source copy, build outputs, logs, keys, and preview artifacts from the remote temporary directory.
- Removed the encrypted canary and its uploads directory.
- Restored SFTP-only access through the API.
- Confirmed no platform cron entries exist.
- Confirmed Teddy remains at two base PHP workers.
- No content was edited and no release was activated.
- Active release remains `20260912T190537187Z-7caf64`; WordPress content generation remains 1012.
- Final unauthenticated homepage request returned HTTP 200 with the same release header.

## Impact on the implementation plan

Use request-triggered PHP jobs on WP Cloud as the initial build-execution candidate. Do not require advanced cron for customer actions. Use occasional scheduled work only for housekeeping/recovery if needed.

Separate long-running build capacity from customer-facing traffic, for example with a dedicated WP Cloud build/control site. Preserve site-scoped identity and job isolation. Keep WordPress snapshots and artifact/key storage authoritative and durable; `/tmp` is suitable for working files, not a guaranteed durable artifact store.

Before a paid release, implement and test crash recovery, simultaneous users, job limits, tenant authorization, stable runtime packaging, private preview asset routing, approval-bound release deployment, and the real OAuth/MCP connection from ChatGPT. This test did not install a production queue or replace the existing local Dashless application.

## Evidence

The [evidence directory](evidence/wpcloud-on-demand-2026-09-16/) contains job records, access-control results, the artifact audit, a representative social image, and redacted test-source fixtures. Fixtures are intentionally stored as text, are tied to this disposable probe, and are not deployment-ready plugins. The final HTTP evidence file records the intermediate edge challenge; the successful final post-cleanup health check is recorded above.
