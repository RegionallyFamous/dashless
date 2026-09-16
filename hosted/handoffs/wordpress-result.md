# WordPress site plugin result — 2026-09-16

## Bottom line

The customer-site implementation is present and its local publishing checks pass. It is **not ready for a paid launch**: four WP Cloud native acceptance trials ended before their first full build completed. One-CPU empty builds now work, but full builds still fail, including direct builds with image-library concurrency limited. A fast public page during a failed build does not meet the acceptance criteria. Live checkout gates remain closed. The repeated diagnostic matrix has stopped; see `hosting-diagnosis-2026-09-16.md` for the bounded next decision.

Writers see plain-language messages such as “Your preview is ready,” “Your download is ready,” and “Your blog is live.” Failure messages explain what happened to their saved work and what to do next. Machine contract keys and internal diagnostics are not UI labels. The ChatGPT task independently reviewed its visible wording and removed its upload picker in favor of ChatGPT attachments.

## Owned changes and compatibility

- Additive `wordpress/dashless-hosted.php` and `wordpress/hosted/`; the existing `dashless-wpcloud.php` and local Codex interfaces are preserved.
- `hosted/runtime/` packages the shared Astro frontend. The frontend task owns `templates/astro/`; its rendered snapshot contract, design controls, publication checks, and protected-path handling were coordinated rather than creating a second long-term theme.
- `hosted/site-tests/` contains isolated WordPress, bootstrap, concurrency, process-death, and native acceptance tooling. Native probes are excluded from release ZIPs.
- The original 23 tool schemas and contract-v1 endpoint names are unchanged. Additive upload and rollback approval routes match `hosted/chatgpt/docs/site-contract-extension.md`.
- Coordinated Hub `Jobs::tick` handles explicit export continuation only after the prior known native task is terminal. Unknown/running native state is never automatically re-dispatched.

## Implemented

| Area | Behavior |
|---|---|
| Site setup | Explicit atomic identity, pinned source/runtime verification, idempotent setup, sample-content removal, honest empty publication |
| Writing | Native posts/pages/drafts, categories/tags, private uploads, metadata, revision listing and staged restoration; published originals remain unchanged while edits are reviewed |
| Design | Versioned palette, typography, layout, navigation, title, description, and logo; schema validation; no generated executable customer code |
| Work queue | Durable records/attempts/results, one active site lease, bounded child supervision, terminal replay, operator recovery, saved-versus-live outcome flags |
| Privacy | Authenticated private HTML/assets, encrypted stored chunks, private temporary workspaces, one-use 60-second browser tickets and host-only secure preview sessions |
| Approval | Account/site/content/design/media/candidate binding, human-event-only route, atomic single consumption, same-key retry, exact approved rollback |
| Publishing | Full sealed local-content/media snapshot, shared frontend quality checks, verified manifests, previous-version fallback, real public-response verification |
| Downloads | Checkpointed encrypted tar with content, revisions, media, design, settings/manifest, and frontend source; owner-bound one-use link; no service credentials |
| Account pause | Public serving/editing/builds blocked; private recovery/downloads retained for 30 days; cache invalidation; no platform-wide suspension that blocks downloads |
| Connection safety | SHA-256 per-site bearer storage, constant-time verification, 120-second old-key overlap, strict ownership, private errors without paths/stacks/logs |

This implementation uses WordPress tables for durable state, not process memory or local desktop storage. Production snapshots and artifacts are created on the site. The operator-only diagnostic serves the complete current homepage through uncached WordPress, not a synthetic health string.

## Verification

| Check | Result and scope |
|---|---|
| Existing `npm run check` | 45/45 passed after shared frontend integration |
| Site WordPress integration | 57 checks passed; real SQLite-backed WordPress and real shared Astro builds; mocked network responses only for public success/failure assertions |
| Bootstrap/empty blog | 6 checks passed against the actual installable ZIP; verified Linux runtime files, sample deletion, identity conflict and retry, real empty Astro publication; local Node and public-fetch fixture used |
| Process death/deadline | 2 independent-process tests passed, including child and grandchild termination after deadline and parent SIGKILL; macOS execution, not native WP Cloud proof |
| Shared hosted frontend | 3/3 real empty/single/archive build scenarios passed, protected asset/link prefixes and strict publication checks |
| Hosted syntax/contract checks | 59 PHP/JS syntax checks passed; all 23 scoped tools validated; later diagnostic-only tracing is excluded from release packages |
| CPU-affinity parsing | 8 checks passed, including nonzero allowed CPU allocations and malformed responses |
| Final package integrity | 14 site files and 13,199 runtime files verified against archive manifests and matching owned/shared source at packaging time |
| Real Hub → two local sites | ChatGPT task reports 29 additional checks plus 46 Hub baseline: real HTTP, actual Astro, real public verification, second version, rollback, checkpointed tar and one-use download; OpenAI transfer source and WP Cloud dispatch mocked |
| Native WP Cloud | **Failed** in all four full acceptance trials; details below |

Dedicated tests exercise separate-process lease exclusion, stale content/design/media, tenant boundaries, upload MIME/ownership, private asset denial and traversal, approval replay, failed activation, publication retry without duplicate saves, suspended export and retention expiry, exact media bytes after resumed tar preparation, and credential rotation.

Local PHP 8.5 emitted notices from the minimal WordPress fixture's missing theme and deprecations from the installed WP-CLI vendor libraries. These are not represented as clean production logs. An initial local 128 MB bootstrap attempt exposed a large archive-entry allocation; extraction was changed to stream entries, and the packaged bootstrap rerun passed at that limit.

## Native evidence — not a pass

Target: Teddy, atomic site **152056190**, two PHP workers, 512 MB PHP memory and no bursting. Trials 1–3 explicitly set the latter two values; defaults were restored before trial 4. Every task request selected only that site with `site_count_limit=1`. No post was created/edited/published and no public version was activated.

| Trial | Native build task | Outcome | Three-reader uncached p95 |
|---|---|---|---|
| 1 | 758951 | Task failed after about 8 seconds; first preview remained unfinished; second stayed queued; 74 HTTP samples, 0 errors | 0.490 s |
| 2 | 758962 | Same failure after removing an extra Node launch; reached Astro startup; 52 HTTP samples, 0 errors | 0.436 s |
| 3 | 758973 | Same failure with full shared frontend, pinned fonts and conservative compatibility settings; native task duration 7.963 s; 74 HTTP samples, 0 errors | 0.487 s |
| 4 | 759064 | Pinned one-CPU launcher; full first build still failed; native task duration 9.968 s; 81 HTTP samples, 0 errors | 0.412 s |

All first jobs recorded the complete **115 posts, 1 page, 150 media** input, but none finished a public-quality output. The second job did not run in any trial. Therefore sustained two-build responsiveness and completion are unproven.

Isolated one-CPU empty builds passed, including task 759069 using the shared frontend and publication checks. Full direct task 759071 and full direct/serial-image task 759073 still failed during static route generation. Neither removing the supervisor nor limiting Sharp concurrency resolved the full workload. Optional custom share-card generation is a proposed feature to defer, not an established root cause. User approval and a coordinated frontend contract are pending; the repeated diagnostic matrix has stopped.

The supervisor could not read meaningful process-tree RSS in the native task environment: its interim sample was 0 with only the explicitly known IDs, and no final process result was written. Zero is **unavailable**, not a measured zero-memory build. The final supervisor labels missing coverage `unavailable`. The observed evidence does not establish OOM, a specific blocked syscall, or any other precise root cause. Site PHP error logs returned no matching entries for the first failure window.

Evidence is in `hosted/evidence/site-native-*.json`, including the original harness's failed result, task IDs, individual HTTP samples, job records, and temporary operator reports. Reports contain no bearer/API secrets. The fixture's plaintext credentials stay in ignored mode-0600 `hosted/.local/`, not in source packages or handoff documents.

Baseline and post-trial reports show the original Teddy public version `20260912T190537187Z-7caf64` and content generation **1012** unchanged. Final cleanup and restored hosting settings are recorded in the completion note below.

## Packages and installation

Version: site plugin/runtime **0.1.0**, frontend contract **1**, Node **22.23.2**, Astro **7.2.0**. Debian loader/libraries, util-linux **2.38.1-5+deb12u3** taskset and DejaVu **2.37-6** are checksum-pinned; transitive npm packages use the lockfile. `hosted/dist/site-packages.json` and `SITE-SHA256SUMS` identify the current pair. All ZIPs are development artifacts ignored by Git; hashes/manifests are reviewable.

Use `hosted/runtime/README.md` for immutable hosting, installation, explicit site identity, CLI dispatch/recovery and credential rotation. These are maintenance instructions, not something a writer must do. Final package hashes are recorded below after the last verification pass.

## Remaining launch blockers and limits

1. Resolve the reproducible native task termination and obtain supported complete-tree memory measurement. Re-run the required two-build harness to completion at the same two-worker/512 MB/no-burst plan. Do not raise resources or disable platform security to turn this into a pass.
2. Perform real public provisioning, billing/recovery and ChatGPT attachment/authentication/approval journeys. Local stand-ins are not public integration evidence.
3. Stress-test retained exports at the intended 25 GB quota. Archive bytes resume in bounded encrypted chunks; metadata/revision enumeration is still one in-memory preparation phase, and very large editorial libraries need additional pagination. Individual tar entries are limited to 8 GiB. Do not claim unlimited-size support.
4. Validate backup/restore of encrypted vault plus database key, cleanup/retention policy at quota, key-loss recovery, and long-running operational monitoring. No automatic expired-artifact garbage collection is claimed here.
5. Operator package upgrades beyond first bootstrap need a deliberate versioned rollout. Bootstrap deliberately refuses silently replacing an existing site's pinned identity/package. Do not use the temporary acceptance helper as an upgrade mechanism.
6. Capability flags describe implemented/tested interfaces and installed-file verification; they are not WP Cloud capacity certification. Keep the independent launch/canary evidence gates closed despite successful package installation.

No support message was sent, no payment was enabled, no production Hub was provisioned, and no unrelated work was committed or reverted.

## Completion note

Final installable package pair:

- Site: `hosted/dist/1c77e8d5cb1ed87504e7cc6cf1e4c6c1c5124bb7fa9ffd457e36d4f4dfb41980.zip` — **53,440 bytes**; SHA-256 `1c77e8d5cb1ed87504e7cc6cf1e4c6c1c5124bb7fa9ffd457e36d4f4dfb41980`.
- Runtime: `hosted/dist/32df9586c630815a5bb947ade4cbf8eff0d3a9307d074e256724dadee522382e.zip` — **137,031,058 bytes**; SHA-256 `32df9586c630815a5bb947ade4cbf8eff0d3a9307d074e256724dadee522382e`.
- Verification: `hosted/evidence/site-package-verification.json`.

The final pair includes archive streaming, local environment-file exclusion, theme neutrality and the pinned one-CPU launcher. This exact pair passed local ZIP bootstrap checks and native installation task 759062, then failed full native acceptance trial 4. Trial 3 used runtime `de239a90151fb99e3e8d58390ab3aec698e12f7474dcbc4243ac08caf2195ef9`; its corresponding details are retained. These ZIPs are development candidates, not permission to enable signup.

Cleanup task **759077** succeeded. Filesystem removal and restored/default hosting metadata are recorded in `hosted/evidence/site-native-cleanup.json`; no production content or active release was removed.

## Subsequent Railway implementation

The owner approved Railway builds after this native investigation. The root task implemented and deployed the worker and added `RemoteRuntime.php`, site continuation/import handling, Railway bootstrap without local Node, and Hub configuration/continuation support. Existing native mode remains available. WP Cloud Hub task 759107 completed a real Railway fixture build and checksum verification in 6.164 seconds. See `../railway/README.md`; the earlier failed native tests remain accurate historical evidence. No Teddy production release was changed.
