# Railway build service

Owner-approved replacement for native WP Cloud Node execution. WordPress and published sites stay on WP Cloud; only sealed build inputs and temporary output are processed on Railway. Customer sites remain two PHP workers/no bursting.

## Live deployment

- Project `dashless-builder`: `c75d1e18-e075-4fbb-9724-8b81d45d9d6d`.
- Service `builder`: `bd3d975b-da99-4d7c-bd34-bc0fe9ad9459`.
- HTTPS: `https://builder-production-e92c.up.railway.app`.
- One replica, one build at a time, durable `/data` volume. PORT must explicitly equal 3000 for the configured Railway domain.
- `BUILD_MASTER_KEY` lives in Railway variables and the Hub's server-held configuration. Backup lives outside this repository with owner-only permissions. Never print it, add it to customer configuration, or pass it to the build child.

## Flow

1. Hub creates a job on the site, dispatching one WP Cloud CLI operation to that site only.
2. The site seals content/design/media and submits a site-scoped, UUID-bound immutable snapshot. Uploaded media are size/hash verified. A repeat request with changed input is rejected.
3. Railway persists and serializes the queue; its pinned Node/Astro image builds with no infrastructure credentials. There is no per-job dependency installation or arbitrary command field.
4. WP Cloud returns promptly with an explicit continuation. Subsequent dispatch collects the ZIP and verifies archive SHA-256, every manifest file, snapshot generation and design before saving the encrypted private candidate.
5. Human approval and release activation use the existing WordPress code. Railway cannot publish a site.

On worker restart, interrupted running builds fail explicitly; queued builds resume. A failed job requires an explicit retry, and the previous public release remains selected. Inputs/outputs expire after 24 hours with a minute cleanup interval while the service runs; after downtime cleanup resumes on startup/timer. This is temporary build storage, not a backup. Queue credentials are derived for each site; never return them to a customer tool.

Initial implementation limits: one global worker, two pending submissions per site, 100 retained jobs, 16 MiB snapshot JSON, 64 MiB per file, 1 GiB total input/output per job, 240-second build deadline. Those are guardrails, not demonstrated capacity. Large media/library transfer and total volume-capacity handling still need acceptance before advertising those maxima. Output archives are streamed to PHP and extracted only through manifest-listed paths. Keep one replica; multiple workers sharing the same volume are unsupported.

## Verification

- `node --test hosted/railway/test.mjs`: full 115-post fixture build; authorization, immutable retries, checksums, private artifacts, sequential jobs and interrupted recovery.
- `node hosted/railway/smoke.mjs HTTPS_URL /protected/master-key-file`: actual Railway execution; deletes its fixture output afterwards.
- `php hosted/railway/site-integration.php /tmp/dashless-site-test-railway-N HTTPS_URL /protected/master-key-file`: real WordPress, real HTTPS to Railway, returned candidate, draft privacy, model-approval rejection and approved publication. The final public-fetch check is explicitly a local fixture, not a WP Cloud deployment claim.
- Evidence: `../evidence/railway-smoke.json`, `../evidence/railway-site-integration.json`.
- Railway ZIP bootstrap: installed package integrity, no Node installation, portable export source, idempotent replay and sample-content removal passed (`../evidence/railway-bootstrap.json`).
- Existing site suite: 57 checks passed. Hub suite: 46 checks passed.

Measured live Railway fixture: 115 posts, 3 pages, 1 media asset, 134 HTML pages, 258 files, about 5.2 seconds including upload/poll/download. This is not Teddy's actual media-heavy snapshot. WP Cloud Hub native task 759107 also passed: 115-post fixture submitted from WP Cloud to Railway, completed result and archive checksum verified, remote fixture deleted; task duration 6.164 seconds. Evidence: `../evidence/railway-wpcloud-task.json`. Temporary Hub probe files were removed. Complete customer provisioning, two real accounts, actual Teddy media workload and public customer-site activation remain acceptance work. Live checkout is not enabled by this deployment.

## Deploy/recover

`node hosted/railway/stage.mjs` creates a minimal ignored context at `hosted/.local/railway-context`; deploy only that context with Railway CLI. It contains the fixed worker, pinned dependency lockfile, shared template, Dockerfile and configuration. Never deploy the entire repository. The current Railway TOML format is supported until December 1, 2026 per CLI; migrate it to Railway's IaC format before that date.

Use Railway deployment rollback for a bad worker release. Preserve the volume and master key; never discard accepted queued jobs to redeploy. The Hub uses `DASHLESS_BUILDER_URL` and `DASHLESS_BUILDER_MASTER_KEY`. Fresh sites get `--build-driver=railway` at bootstrap and receive only their site token through the authenticated `/builder` endpoint. Existing customer sites require an explicit plugin rollout and builder configuration. Changing the master key requires rotating each site's credential before builds resume.

The new site ZIP includes portable frontend source for exports, without installing Node on WP Cloud. Restore the previous Hub configuration/package pointer to stop new Railway provisioning; do not silently switch in-flight jobs back to the known-failing native Node path.
