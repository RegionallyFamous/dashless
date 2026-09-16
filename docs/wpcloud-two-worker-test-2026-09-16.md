# Two-worker responsiveness test

Date: 2026-09-16 UTC. Site: teddy.blog (152056190).

## Result

**Failed the responsiveness requirement.** The complete blog build succeeded, but concurrent authenticated requests timed out. The previous successful standalone build is not sufficient evidence that a customer can comfortably use the site during a build on the two-worker plan.

The site retained `default_php_conns=2` and an unset `burst_php_conns`. No capacity settings were changed. Builds ran on WP Cloud; the local computer only installed the disposable probe and generated/measured HTTP traffic.

| Stage | Observed result |
| --- | --- |
| Baseline, one simulated user | 4 requests, all successful; median 0.251 s, maximum 0.268 s |
| Baseline, three simulated users | 15 requests, all successful; median 0.258 s, maximum 0.337 s |
| Full build submission | HTTP 202 in 1.323 s |
| During build, one simulated user | Both read requests timed out at approximately 15 s |
| Second-job submission during full build | Timed out at 15 s; no corresponding job record found |
| Full build | Succeeded; 93.293 s Node duration, 95.172 s worker duration |
| Recovery | Status 0.289 s, private preview 0.353 s, environment 0.277 s; all HTTP 200 |

The build used 115 published posts, one page, 150 media records, 14 categories and 87 tags. Output: 240 HTML pages and 1,111 files. Homepage SHA-256 matched the earlier successful full build. No release was activated.

## Method

Reconstructed the earlier temporary authenticated plugin and private Node/runtime bundle. Reused internal WordPress snapshots, local media reads, serial post normalization and bounded Node/image-processing concurrency. Removed copied `.dashless-cache` before the full build. Installed a disposable site-wide build lock and sequential drain loop so a successfully accepted second job would wait.

First ran a small build to produce an authenticated preview. Simulated users made an action every five seconds, waiting for each response: two WordPress post-list reads for every private-preview read. Post reads used authenticated `context=edit`, ten records, reduced fields and a unique query value. Responses were marked private/no-store. All simulated users shared the existing administrator application-password identity; this is not a tenant isolation test.

Baseline ran for 20 seconds with one user and 25 seconds with three. The full build started with one simulated user. A second short job was submitted ten seconds later. Its timeout aborted the staged test before three-user build traffic. Outstanding traffic was allowed to finish, then stopped. A separate status diagnostic returned HTTP 429 during the build. No retries of the uncertain second-job submission were made; later database inspection found only the seed and full-build jobs.

## Interpretation and limits

The full build can execute on this configuration. This particular same-site HTTP worker implementation does **not** pass the interactive responsiveness requirement, even at the first traffic level. Queue ordering was not demonstrated because the second submission did not reach persisted state. Three-user traffic during the build was intentionally not attempted after failure.

A seed-build status read also took 7.734 seconds while that short build ran. That supports investigating request serialization/resource contention, but does not identify its cause.

WP Cloud metrics reported worker maxima up to two in baseline periods, with limited-percentage values in two baseline buckets. The retrieved series had no samples for the full-build interval at retrieval time; missing samples are not zero. These metrics do not establish whether the timeouts were caused by worker saturation, request serialization, CPU contention or another platform constraint. The HTTP 429 is recorded separately from the client timeouts.

This is a small controlled diagnostic, not a statistical capacity benchmark. It does not show that every two-worker architecture fails. Next engineering work should isolate the blocking mechanism or move build execution to separate WP Cloud capacity, then repeat this test before claiming responsive concurrent use. Separate capacity remains an untested proposal, not a measured fix.

## Cleanup

See accompanying cleanup and health evidence for final verification. Temporary plugin, jobs/options, runtime, dependencies and artifacts were removed after all persisted jobs were terminal. SFTP-only access restored. The live release and content generation were checked against their original values; no posts were edited.

Final verification: zero probe options remained; the removed endpoint returned HTTP 404. Access was restored to SFTP-only and base workers rechecked as two. Active release stayed `20260912T190537187Z-7caf64`, content generation 1012. An unauthenticated request from the test machine received an HTTP 403 with an `_hcc` challenge cookie; the web retrieval service could retrieve the public homepage. This does not prove universal public availability or that the test-machine challenge has cleared. No edge/security settings were changed.

## Evidence

[Requests and job records](evidence/wpcloud-two-worker-2026-09-16/requests.json), [recovery checks](evidence/wpcloud-two-worker-2026-09-16/recovery.json), [platform metrics](evidence/wpcloud-two-worker-2026-09-16/worker-metrics.json). Harness saved as an inert text fixture; it is not a production load-testing tool.
