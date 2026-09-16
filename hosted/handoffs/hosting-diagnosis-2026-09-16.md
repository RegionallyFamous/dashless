# WP Cloud build diagnosis — 2026-09-16

## Finding

A CPU-affinity limit of one already-allowed CPU consistently completed isolated empty builds that otherwise terminated the entire native task. **It did not make the full Teddy build work.** Thread-count environment variables alone did not produce the same isolated improvement. This is a partial mitigation, not a hosting solution; the platform's exact termination rule or signal is still unknown. Do not label this an established out-of-memory failure.

All checks targeted Teddy, atomic **152056190**, with two PHP workers, the default 512 MB PHP limit, and no bursting. Each task creation explicitly supplied only that site's ID and `site_count_limit=1`. No content, active release, security setting, worker allocation, or billing setting changed. The fixture created private disposable files only.

## Controlled checks

| Check | Native task | Result |
|---|---|---|
| Node 22.23.2 baseline | 758998 | Passed |
| 12-second idle timer | 758999 | Passed; not a general eight-second wall-clock cutoff |
| Six seconds of CPU work | 759000 | Passed |
| Import Astro 7.2.0 | 759001 | Passed |
| Import Rolldown | 759003 | Passed |
| Actual Rolldown transform | 759007 | Passed |
| Astro configuration/settings setup | 759008 | Passed |
| Initial minimal Astro build | 759005 | Native task stopped before completion |
| Subsequent shared-cache minimal builds | 759009, 759011 | Passed |
| Minimal build, fresh explicit caches, five CPUs | 759012, 759018, 759024 | All stopped before completion |
| Same fresh-cache build, one allowed CPU | 759019, 759023 | Both passed; child durations 1.80 s and 2.31 s |
| Real shared empty-blog template, five CPUs | 759013 | Stopped during compilation |
| Real shared empty-blog template, one allowed CPU | 759021, 759025 | Both passed publication checks; child durations 2.60 s and 3.01 s |

The one-CPU and five-CPU comparisons used the same pinned runtime, Node, dependencies and frontend. Node reported `availableParallelism()` of one versus five. The control failure was repeated between successful constrained runs, so a simple chronological warm-up does not explain the result. The successful empty templates produced six HTML pages plus feeds, search, sitemap and the publication manifest. These checks used no Teddy posts or images.

## Memory and limits

The task environment does not expose `/proc/self/status`, `/proc/self/limits`, or the queried cgroup memory path. Node's ordinary `process.memoryUsage()` throws `ENOENT`; the diagnostic catches that and records memory as unavailable. POSIX `getrusage` works, but its per-process high-water value is not complete-task peak memory.

The sampled failing minimal process had reached 175,040 KiB. Successful constrained processes reached 198,412–243,508 KiB. These samples cannot rule out an unobserved spike, but they do not establish memory exhaustion. POSIX CPU, process-count, RSS and address-space limits reported unlimited; a host-level or task-controller limit may still apply.

Two preliminary diagnostic mistakes are retained transparently in the evidence: task 758996 encountered the unavailable Node memory API before the probe was hardened; task 759004 called the transform through the wrong package export. Neither is evidence of an Astro hosting failure. Corrected checks are listed above.

## Implementation and evidence

`wordpress/hosted/Runtime.php` now selects the first CPU from the existing affinity list and starts the supervisor and build there. It fails closed if the list or pinned limiter cannot be verified. `hosted/runtime/sources.lock.json` pins Debian util-linux 2.38.1-5+deb12u3; packaging includes only its `taskset` executable and license, not a system installation. Eight parser checks cover nonzero CPU allocations and invalid output.

Detailed task records and stage traces: `hosted/evidence/host-diagnostic-adf338ca-bf72-47cf-a683-37e9e5a3224f.json`. The diagnostic is excluded from release packages. Its cleanup task **759026** succeeded; the loader and private runtime were removed, the diagnostic route returned 404, and the public homepage still returned 200 with release `20260912T190537187Z-7caf64` and content generation **1012**.

## Full-blog follow-up — still blocked

The pinned one-CPU runtime was installed and exercised through the required two-build harness. Native task **759064** failed after 9.968 seconds; the first job remained unfinished and the second stayed queued. The snapshot contained **115 posts, 1 page, and 150 media records**. All 81 concurrent uncached page requests succeeded, with p95 **0.412 seconds**, but the build did not finish. Responsiveness passed for that short window only; overall acceptance and memory verification failed.

Three additional fixed checks narrowed the result:

| Check | Native task | Result |
|---|---|---|
| Empty shared template, direct build, one CPU | 759069 | Passed all publication checks; six HTML pages |
| Full Teddy snapshot, direct build, one CPU | 759071 | Failed after compilation, entering static route generation |
| Same full build, explicit Sharp concurrency 1 and cache disabled | 759073 | Failed at the same stage |

Direct-build failure means the extra supervisor process is not a sufficient explanation. Limiting image-library concurrency also did not resolve it. Last sampled Node high-water values were 278,452 and 283,624 KiB for the full direct checks; these remain per-process observations, not complete-task memory proof.

The diagnostic matrix is now stopped. A proposed reduced first version would use existing WordPress images for shared links instead of generating custom share cards. That change needs an explicit frontend contract and one full acceptance run; it is **not yet implemented, approved, or proven to fix this failure**. No external hosting, additional paid resources, or same-site HTTP build workaround has been substituted.

Current fixture cleanup task **759077** succeeded. Final filesystem and public-response verification is recorded in `hosted/evidence/site-native-cleanup.json`. Detailed reports for this run are in `hosted/evidence/site-native-1c8b0ac7-f6b0-42c5-841b-55c800316e94-details.json`; aggregate acceptance remains in `hosted/evidence/site-native-acceptance.json`.
