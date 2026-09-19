# Dashless repair plan

Handoff and execution record, September 18, 2026. The plan began as a
read-only handoff; the local release-safety and catalog work listed below has
since been implemented and verified. This document does not authorize a live
deployment or submission by itself.

## Progress

The first implementation pass has now completed the local stabilization and
release-safety work: root, hosted, and six-theme browser gates pass; Hub dry-run
builds produce and verify an immutable six-demo artifact; release cleanup keeps
the newest previous release for rollback; theme builders read the shared theme
catalog; current Auth0/submission status is separated from historical notes;
and the disposable Hub/ChatGPT journey passes 84 and 105 checks respectively.
Operator acceptance scripts now validate their arguments and release verifiers
accept both common CLI forms. The public Hub source now also includes static
support, privacy, and terms routes with a real contact link, and the readiness
check covers all six theme preview assets. The remaining phases below still
require implementation or external acceptance evidence.

The latest strict read-only production check still reports deployment drift:
the live site serves an older release, its preview responses do not expose the
release header needed to bind assets to the active pointer, and the earlier
probe observed only three preview assets plus 404 responses for the three
public policy routes. The local release is ready, but promoting it is a
separate authorized deployment action.

## Working diagnosis

Dashless does not need an immediate rewrite. It needs a stable release baseline, consistent themes, and proof that the complete hosted publishing journey works. The checkout contains substantial unfinished changes across deployment, theme assets, shared templates, packaging, and tests. Preserve them; do not reset or overwrite them.

Concrete observations from this review:

- The current catalog/release script lists six themes, while older readiness records describe three. Documentation mixes successive authentication architectures and historical blockers.
- Theme previews are copied into three locations; the release builder now derives IDs, preview names, and demo slugs from the shared registry and the remaining copies are checked together.
- The new, untracked `hosted/scripts/publish-hub-release.mjs` consolidates release work, but its cleanup loop targets every nonactive release. Resolve retention before relying on previous releases for recovery.
- The recorded September 18 theme verification proves one Field Notes page and release header. It does not prove every theme, route, or deployed artifact.
- Existing audits report many passing local checks, but actual Auth0/ChatGPT journeys, full media workloads, billing, provisioning, and recovery remain unproven or need current evidence.

## Ordered work

### 1. Stabilize the work already in progress

Inspect `git diff` and untracked files first. Group existing work into theme changes, deployment changes, and generated assets. Record the starting commit and preserve a recoverable checkpoint without committing secrets or generated packages. Run `npm run check`, `npm run check:hosted`, and `npm run test:frontend` once; capture failures and fix the first concrete failure in each affected component. Do not add another audit framework.

Done: the existing changes are understood, checks have a current recorded result, and unrelated edits remain intact.

### 2. Make releases predictable and recoverable

Finish reviewing `config/deployment-contract.json`, `hosted/scripts/publish-hub-release.mjs`, its workflow, and the deployment/theme verifiers. Keep the Hub frontend and customer publication targets explicit. Build once, identify the artifact by hashes, and promote that exact artifact. Preserve the active release plus at least one verified rollback release; protect both from cleanup. Verify pointer restoration after failed activation, cleanup retry safety, and failures after upload. Prefer targeted behavioral tests over source-string assertions.

Done: on a disposable target, release A → B → failed C leaves B healthy, rollback to A succeeds, and cleanup preserves the required recovery artifacts. Live deployment requires the user's execution authorization.

### 3. Finish themes against actual rendered output

Use `templates/astro/src/lib/themes.json` as the authoritative catalog. Derive IDs, demo paths, and copied catalog assets from it where practical. Keep `templates/astro` as the customer publication source; the Hub marketing frontend has a separate role. Avoid new parallel template trees.

Start with one representative theme and fix shared layout problems before propagating changes. Then review all six themes: home, archive, article, search, taxonomy, RSS, and 404, using populated and empty fixtures, long titles, missing images, and mobile/desktop widths. Compare rendered screenshots with existing approved boards. Fix layout, typography, navigation, and reading usability before cosmetic polish. Generate preview thumbnails from the actual final demos.

Done: all six complete route sets work, theme selection persists correctly, previews match builds, and approved deployed checks identify the expected artifact and theme. A screenshot or HTTP 200 alone is insufficient.

### 4. Prove one complete hosted customer journey

Use the existing Auth0 architecture. Verify sign-in → account ownership → fresh site → draft with image → private preview → approval → build → public release → edit → rollback → export → disconnect/reconnect. Run the journey through the intended client, including actual ChatGPT when testing its integration. Add a second account for negative ownership/private-media checks. Fix the first failing boundary before expanding scope.

Make `hosted/tests/browser.cjs` reproducible with a documented disposable fixture or fixture setup command. Automate repeatable local portions; retain sanitized evidence for real external-service steps.

Done: an actual user completes the journey, and stale approval, replay, and cross-account access fail correctly.

### 5. Close operational launch blockers

Follow the existing launch audit: real 115-post/~150-media workload, consecutive and multi-customer jobs, bounded memory/storage, interrupted jobs, expired preview/export cleanup, observable failures, and database/vault/key restore. Verify fresh pinned-package installation and upgrades. Exercise the sandbox billing lifecycle and set supported quotas from measurements. Keep paid launch and submission gates closed until their evidence exists.

Done: supported limits and recovery are demonstrated; billing and provisioning work together. Submission, publication, and enabling paid checkout are separately authorized actions.

### 6. Replace contradictory current guidance

Update README, release checklist, and submission readiness to describe the verified current architecture and status. Keep older audits as dated history and clearly identify superseded findings. Link evidence rather than repeatedly appending conflicting status paragraphs. Remove obsolete scripts only after checking callers.

## Instructions for the next model

Execute one numbered phase at a time after the user authorizes implementation. Begin with the existing dirty checkout, not another ecosystem audit. Prefer small fixes and existing tools. Do not redesign branding, replace authentication, move hosting, add themes, or undertake a broad rewrite. Run focused checks during edits and the appropriate full gate at phase completion. Report what changed, what passed, and the next concrete blocker. Stop for missing credentials or required user actions only when independent authorized work is exhausted.

Primary references: `docs/ecosystem-audit-2026-09-18.md`, `docs/launch-audit-2026-09-16.md`, `docs/theme-system.md`, `docs/release-checklist.md`, and `hosted/chatgpt/submission/readiness.md`. Treat conflicting historical statements as items to verify, not instructions to repeat old migrations.
