# Implement the hosted WordPress site plugin

## Objective

Implement the customer-site half of Dashless: a separate WordPress installation per subscriber on WP Cloud, with durable editorial changes, previews, human approval, native queued builds, publication, rollback, private media and exports. This is execution work, not another plan. Preserve the existing local Codex workflow alongside hosted mode.

Working directory: `/Users/nick/Documents/ChatGPT/Dashless`.

## Read first

1. `hosted/README.md`, `hosted/docs/contract-v1.md`, `hosted/docs/operations.md`, `hosted/docs/verification.md`.
2. `hosted/hub/contracts/tools.v1.json` and `fixtures.v1.json` — authoritative tools, scopes and envelopes.
3. `wordpress/dashless-wpcloud.php`, existing WordPress tests and `server/lib/editorial.mjs`, `server/lib/frontend.mjs`, `templates/astro/` for reusable behavior.
4. `hosted/hub/src/Agent.php`, `Provisioner.php`, `Jobs.php`, `Previews.php`, `Cloud.php` — actual caller expectations, read-only unless coordinating an integration fix.
5. `docs/wpcloud-build-isolation-review-2026-09-16.md`, `docs/wpcloud-on-demand-probe-2026-09-16.md`, `docs/wpcloud-two-worker-test-2026-09-16.md` and their evidence. They contain limitations, not a passed native responsiveness test.

## Ownership and coordination

You own customer WordPress plugin implementation under `wordpress/`, its hosted modules, runtime packaging under a new `hosted/runtime/`, and dedicated site-plugin tests/scripts. Preserve existing public/local interfaces and package behavior. Inspect current code before choosing an additive module layout.

The other task owns ChatGPT component code under `hosted/chatgpt/` and Hub MCP/OAuth/widget integration. Do not edit its files or replace Hub billing/provisioning, marketing/theme or branding. Shared contract/schema changes require a documented compatibility decision; propose them in your handoff report instead of independently renaming endpoints. You are not alone in the repository: do not revert others' changes. Prefer separate test files and dedicated scripts; coordinate shared `package.json` edits.

## Required implementation

**Mandatory provisioning requirement:** Every newly provisioned customer site must automatically install and activate the Dashless **site plugin** from the pinned, checksum-verified WP Cloud-hosted ZIP. This is part of signup provisioning, not a manual customer step and not installation of the Hub plugin. The existing Hub `Cloud::create()` already requests `software[package] = activate-locked`. Ship a package that works with that fresh-site installation path. Verify installation/activation before bootstrap and require successful capability discovery before the first build or ready status. Installation failures must preserve the existing provisioning operation for diagnosis/retry, never create another site or report setup complete.

- Implement all customer-site endpoints and WP-CLI commands in contract v1, including truthful capability discovery and bootstrap. Site ID is the atomic ID, not a guessed WordPress blog ID.
- Bootstrap fresh sites idempotently, initialize per-site credential hash and entitlement, remove default sample posts/pages, and build an honest empty blog. Never copy Teddy's content into customer sites.
- Preserve posts/pages, drafts, terms, media and revisions; make staged edits, generation tracking and preview records durable/site-scoped. Reuse current release manifests/activation/rollback checks.
- Queue every expensive operation. Implement `wp dashless build-job <uuid>` with one active lease per site, persisted attempts and terminal results, crash recovery and idempotency. Return IDs immediately from HTTP. Internal deadline ≤240s, terminate/reap children, measure process-tree peak RSS. No dependency installation during each build.
- Package pinned checksum-verified runtime/dependencies for WP Cloud. All production builds, data and artifacts remain there. Existing experimental Node/runtime probes are evidence to inspect, not automatically production-safe bundles.
- Snapshot content/design/media consistently and read local media. Preserve previous public release through build/process/deploy failure; verify public release before reporting success. Distinguish saved, preview-ready, WordPress-published, deployed and publicly verified states.
- Implement curated versioned design controls from the tool schema. No arbitrary customer PHP, shell, plugin installation, generated executable design code or infrastructure tools.
- Protect preview HTML and every private asset URL. Implement the browser handoff endpoint expected by Hub `Previews.php`, including host-scoped preview session, short one-use ticket and no-referrer/cache controls. Never expose credentials in model-visible results.
- Implement authoritative immutable preview binding and atomic approval consumption. Hub `/approvals` is a service-authenticated human-event route, not a model tool. Bind account, site, content generation/hash, design version/hash, assets, preview and candidate. Reject stale, cross-account, changed and replayed inputs. Same-key retry may return the original result without repeating publication. Rollback requires equivalent human approval.
- Implement authenticated upload sessions compatible with the ChatGPT task's transfer path; enforce MIME/size/expiry/ownership and reject executable uploads/SSRF/local path access.
- Implement bounded resumable export and private one-use download. Suspended accounts retain export/recovery for 30 days while public serving, editing and builds are disabled. Entitlement changes must purge caches; do not block private export endpoints with platform-wide suspension.
- Implement credential rotation and the operator-only real uncached page diagnostic for the native test harness.

## Constraints and known state

One owner, one blog, $9.99/month; customer supplies ChatGPT access. Customer sites target two PHP workers, 512 MB PHP memory, 25 GB storage, bursting disabled. Do not increase worker counts/cost to hide a failed test. No domains/teams/newsletters/arbitrary plugins in v1. No mandatory Dashless branding on publisher sites.

Hub code exists and has local fixture tests; the site-agent endpoints do not yet exist. WP Cloud accepted task 758910 for the proposed custom command on Teddy with an intentionally nonexistent job, then reported failure. This is not a passed build or concurrency test. `wp eval` was rejected by Tasks API; use a real registered CLI command.

Use the `wpcloud-api` skill for platform calls and respect secret redaction. Every task must explicitly target exactly one authorized site in `site_run_list`, with `site_count_limit=1`. Avoid destructive production testing; preserve Teddy's existing public release/content and clean up only your own probes. Do not send support messages or enable live checkout.

## Acceptance and deliverables

- Run existing `npm run check` and add meaningful site-plugin tests for tenant/credential boundaries, concurrent jobs, stale approvals/design/assets, replay, killed processes, failed activation, retained exports and direct private asset access.
- Run the full Teddy-sized native build test using `hosted/scripts/native-acceptance.py` after implementing its diagnostic and creating two distinct persisted jobs. Three users: uncached p95 <2s, zero timeouts/429/5xx; second build queued then runs. Verify the diagnostic renders real page work, both jobs are full builds, and process-tree memory is measured. Report failed evidence honestly.
- Produce installable pinned site-plugin/runtime packages, SHA-256 manifests and bootstrap instructions. Runtime/package artifacts must be suitable for immutable hosting on the Hub.
- Add `hosted/handoffs/wordpress-result.md` listing implemented capabilities, versions, exact test results, known limits, package locations and integration notes for the ChatGPT task.
- Keep all live checkout gates closed until real end-to-end and public integration requirements pass. Do not mark tests passed from fixtures alone.
