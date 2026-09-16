# Verification — 2026-09-16

## Executed

| Check | Result | Boundary |
|---|---|---|
| `npm run check` | 44 tests passed | Existing local Codex workflow, original WordPress bridge and Astro tests |
| `npm run check:hosted` | Passed | PHP/JS syntax plus unchanged 23 scoped contract schemas; excludes developer node_modules |
| `php hosted/tests/integration.php /tmp/dashless-wp-test` | 46 checks passed | Actual local WordPress/League OAuth; external services are fixtures |
| `php hosted/chatgpt/tests/integration.php /tmp/dashless-wp-test` | 101 additional checks passed, plus the 46 baseline | Real OAuth for two test subscribers; site behavior is simulated |
| `node hosted/chatgpt/tests/http.mjs` | 17 checks passed | Actual PHP HTTP transport/discovery, unauthenticated tool scan, OAuth challenge, resources, status codes, origin rejection and forbidden approval |
| `npm --prefix hosted/chatgpt test` | 4 tests passed | Publication claims, failure wording, trusted review URLs and polling backoff |
| `npm --prefix hosted/chatgpt run test:browser` | 10 checks passed; zero axe A/AA violations on desktop and mobile | Actual bundled component under a local MCP Apps fixture host; no actual ChatGPT session |
| `php hosted/chatgpt/tests/real-sites.php /tmp/dashless-wp-test` | 29 additional checks passed, plus the 46 baseline | Two separate WordPress/SQLite sites, actual REST over HTTP, real Astro builds, actual public HTTP verification, rollback and exports |
| `npm run package:hosted` | Passed | Standard Hub package includes the ChatGPT adapter; dedicated Hub update and component hashes/manifests generated |

The real-sites suite connects both owners through actual League authorization-code/S256 PKCE, routes Hub Agent requests to their separate local WordPress installations, imports a private image, creates a draft/preview, calls the browser approval handler, executes the queued native CLI job, checks the actual public response/release header, publishes a staged second version, restores the prior release via bound browser approval, and downloads an export using a one-use ticket. Cross-account preview and unsigned preview/media requests are denied. Same-key browser approval reuses the original publication job. Six additional unit/integration checks verify safe resumable export dispatch: a known completed native task plus explicit queued checkpoint requeues its UUID; unknown/running/unflagged states cannot restart. Cookie/nonce/origin enforcement is separately exercised by the dedicated authentication tests; this is not a claim that a real browser or ChatGPT completed the full journey.

Only the OpenAI file source (a one-pixel fixture) and WP Cloud task dispatcher are simulated in real-sites.php. Builds run explicitly via local `wp dashless build-job`, outside HTTP. No fake HTTP success substitutes for public release verification in that suite. The two local public origins are localhost:8892 and localhost:8893, not production HTTPS. The test-only host rewrite preserves the production account-derived destination logic while mapping each test domain to its separate local HTTP server.

## Reproduce real sites

Install the site workstream's local runtime prerequisites first (`hosted/runtime/node_modules`, its verified local runtime manifest). Then:

```sh
node hosted/site-tests/setup.mjs /tmp/dashless-site-test-chatgpt-a
node hosted/site-tests/setup.mjs /tmp/dashless-site-test-chatgpt-b
# Two separate terminal processes, rooted in their matching installation:
cd /tmp/dashless-site-test-chatgpt-a
php -S localhost:8892 router.php
cd /tmp/dashless-site-test-chatgpt-b
php -S localhost:8893 router.php
# In the repository:
php hosted/chatgpt/tests/real-sites.php /tmp/dashless-wp-test
```

The harness configures only these dedicated local fixtures with fresh secrets through private temporary files, resets only their customer-site records/posts, and enables pretty REST routes. It also runs the existing Hub test, which resets the local Hub table. Do not run against any production installation. Early HTTP testing identified an unconditional ABSPATH definition in the setup template that emitted PHP warning HTML before JSON; the site task was notified and the two local configs were corrected with a defined guard. Empty permalinks were also corrected. These were local setup defects, not bypassed protocol failures.

Media input is exclusively ChatGPT’s attachment workflow. The component contains no Add an image section, upload input, file picker or media editor. File transfer remains covered by the tool/schema, session, MIME and real-site relay tests.

## Security evidence

- Wrong OAuth resource, redirect and scope; code/refresh replay; revoked token chains; disconnected and wrong-owner browser handoffs.
- No model-supplied account routing. Two identities use independent databases/sites; supplied foreign preview/job/upload references are denied.
- Model flag/forged approval cannot mint authorization. Approval route requires a verified owner cookie and REST nonce; application-password/model identity without the cookie fails. Cross-origin and forged-nonce requests fail.
- Changed content generation/design/assets and consumed approval fail in fixture tests; real site stale/replay behavior is also covered by the site workstream's separate suite. Rollback handoff binds target manifest and active release and is rechecked before the human event.
- Tool text and structuredContent omit nested credential fields, service tenant IDs, upstream `_meta`, preview URLs and approval IDs. Strings accidentally embedding signed private links fail closed. Only locally generated review metadata is admitted to `_meta`. No persistent component storage contains credentials.
- File source URLs reject local paths/private IPs/arbitrary domains, credentials, ports and redirects. Unsupported MIME, declared MIME mismatch, excessive size, expired session and changed idempotency binding fail. Real binary relay carries the authenticated owner and verifies returned site ID.
- Actual HTTP private preview/media access is denied without authorization; real export download token is single use. The site task owns encrypted storage and session-protected draft asset implementation.

## Current cross-task rerun status

The final 29-check two-site real HTTP rerun passed after the site owner stabilized the shared runtime and refreshed the local verification manifest. Both sites were reconfigured by the test harness. No integrity validation was bypassed. The app-specific 101 integration, 17 HTTP, 4 UI logic and 10 browser checks also pass. The browser suite explicitly verifies that media input remains in ChatGPT, and screenshots use the updated plain-language copy.

## Remaining evidence

Actual ChatGPT OAuth/UI/file API behavior, a configured observed file-download origin, model-visible host transcript inspection, HTTPS browser preview/session behavior, real WP Cloud native task dispatch and two-worker capacity, production provisioning, reviewer login, publisher/policy verification, and public submission/publication remain unverified here. Keep the existing launch gates closed. Tests do not establish app-store approval or production readiness.

Screenshots show actual packaged UI with synthetic data; their provenance is recorded in `submission/screenshots/evidence.json`. Hot Type remains raster concept art. Manual assistive-technology review is still required alongside automated axe results.
