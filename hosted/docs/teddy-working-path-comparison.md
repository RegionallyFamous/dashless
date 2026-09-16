# Teddy working build versus hosted build

Compared September 16, 2026 using the actual Teddy project, installed dependency versions, hosted source, and retained native task evidence. This comparison did not modify Teddy or dispatch another production task.

## Confirmed differences

| Component | Existing Teddy | Hosted candidate |
|---|---|---|
| Build execution | Local Node/npm from `server/lib/frontend.mjs`, followed by SFTP and REST activation | Pinned Linux Node/loader inside a WP Cloud native task; PHP launcher and bounded supervisor |
| Reader hosting | WP Cloud serves completed release | Same intended separation between build and serving |
| Content input | Authenticated WordPress REST requests | Sealed local snapshot and local media files |
| Frontend | Customized Teddy project, including responsive images and visual system | Shared curated template, snapshot adapter and publication checker |
| Astro | 7.2.0 | 7.2.0 |
| Sharp | 0.35.3 | 0.35.4 |
| Vite | 8.2.1 | 8.3.0 |
| Rolldown | 1.2.3 | 1.2.8 |
| Processing | Concurrent post normalization; persistent local media/variant cache | Sequential normalization; fresh source/workspace; private media snapshot |
| Native environment | Workstation libraries/fonts and ordinary process visibility | Packaged Linux libraries/fonts, one-CPU affinity, 384 MB Node heap setting, unavailable proc/cgroup observations |

The installed versions match those lockfile versions. The shared lockfile packages contain 94 version differences, recorded in `../evidence/teddy-hosted-dependency-diff.json`. Matching Astro alone does not make these equivalent runtimes.

## What the traces actually establish

Native empty build 759069 finished. Full builds 759071 and 759073 completed compilation and stopped after “generating static routes”, before logging the first completed route. The latter constrained Sharp concurrency and disabled its cache. There is no captured terminal process result establishing a cause.

Both Teddy and the shared template generate social cards using substantially the same Sharp/SVG/composite code; the social-card source diff is only accent customization. Therefore custom cards are not a newly introduced product feature that by itself explains the regression. Native Linux image/font execution remains a candidate, not a diagnosis. Hosted post normalization is already sequential, so adding another post serialization fix would repeat work.

## Bounded next experiment

Use the full unchanged snapshot and shared template in an isolated diagnostic with Teddy-matched dependency versions, including transitive native dependencies. Keep the existing hosted candidate as the control and record stages before/after media copy, featured-image decode, SVG rendering, and card write. If the matched runtime still fails, its last completed stage determines one minimal native reproduction. Do not run another broad matrix or remove the share-card feature based on the current evidence.

No claim that a two-worker plan is insufficient follows from these results: page requests stayed responsive, while a separate build process terminated for an unknown reason. This comparison explains why the existing public Teddy site can work while native builds fail; it does not establish the precise native termination cause or a tested fix.
