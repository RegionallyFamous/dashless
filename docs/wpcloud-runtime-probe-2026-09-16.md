# WP Cloud runtime feasibility probe

Date: 2026-09-16 UTC. Site: teddy.blog, Atomic ID 152056190. User authorized running tests using existing API access. No deployment or editorial publication was performed.

Follow-up: the [on-demand build test](wpcloud-on-demand-probe-2026-09-16.md) subsequently completed the full Teddy build through a PHP-launched HTTP worker on WP Cloud. That newer result supersedes this report's inconclusive full-build outcome; this file preserves the initial observations.

## Findings

| Test | Observed result |
| --- | --- |
| Working WP Cloud API access | Confirmed |
| Switch site access from SFTP to SSH through documented API | Succeeded |
| Execute commands over SSH | Succeeded |
| Verify SSH endpoint identity | Its ED25519 public key matched the previously trusted sftp.wp.com key; strict host verification remained enabled using that known host alias |
| PHP / WP-CLI | Available; PHP 8.5.10 |
| Preinstalled Node/npm | Not found on PATH |
| Official Node 22.14.0 Linux x64 distribution in private SSH HOME | Downloaded over HTTPS; archive SHA-256 matched official SHASUMS256.txt; executable ran |
| npm | Version 10.9.2 ran |
| Teddy locked dependencies | npm ci installed 294 packages in 13 seconds |
| Sharp 0.35.3 native processing | Generated a 16x16 PNG buffer successfully |
| Astro 7.2.0 CLI | Reported version successfully |
| Minimal Astro static site | Successfully produced index.html; Astro reported 866 ms build time |
| Actual Teddy frontend | Compiled with constrained concurrency, then SSH command terminated with exit 131 during static route generation |
| Disable social-card generation in temporary copy | Same termination during route generation; social-card generation alone is not an established cause |
| One-minute platform cron | API rejected schedule with advanced-cron-required; no job created or executed |

## Build conditions and limits

Teddy source, locked dependencies, frontend configuration, and existing nongenerated public assets were copied to a unique private directory under /home/152056190. Generated media/social directories were excluded and left for the real build to regenerate. WordPress reads used public content without copying WordPress credentials. No project secrets or .env files were uploaded.

An initial npm run build attempt terminated with exit 131. Subsequent direct Astro builds used a 384 MB V8 heap, reduced V8/libuv/Go/Rayon thread settings, and eventually one allowed CPU via taskset. This let the real template complete Vite compilation and begin static routes. A minimal one-page Astro project built with these constraints using the same installed Astro dependency.

The initial full route-generation attempt also emitted a missing Fontconfig configuration warning. Later attempts still terminated, including one with Sharp concurrency set to one/cache disabled and another bypassing social-card generation in the temporary copy. These observations do not identify the termination cause. Exit 131 is the observed SSH process status; no platform diagnostic established memory exhaustion, a process-limit violation, or a particular failing syscall.

The environment did not expose /proc/self/status or the queried cgroup limit paths. CPU affinity was instead obtained through taskset, which reported CPUs 0–4. The build was restricted to CPU 0; limits were reduced, not bypassed.

## Cleanup and live-site verification

- Removed the entire temporary probe directory, including Node, dependencies, npm cache, copied sources, and generated artifacts; verified absence while SSH was still enabled.
- Restored site access type to sftp; API confirmed the setting.
- Cron list remains empty.
- Base PHP workers remain 2.
- Public homepage returns HTTP 200.
- Public release observed before and after cleanup: 20260912T190537187Z-7caf64.
- Public content generation observed before and after cleanup: 1012.
- No release was activated and no post or page was edited.

## Conclusion and next experiment

The blanket claims that shell execution is unavailable and Astro cannot execute on WP Cloud are contradicted by these tests. Node/npm are not preinstalled, but a private user-space runtime ran and a real Astro static build succeeded.

This is technical feasibility evidence, not confirmation of a supported production workload. Full Teddy builds, reliable scheduled execution, private previews across HTTP/SSH contexts, and hosted OAuth/MCP remain unproven.

Next: reproduce the full build termination with progressively smaller real content/media sets in an isolated test installation, then correlate it with platform resource/termination diagnostics. Request advanced-cron access if minute-level scheduled execution is needed. Keep all further build work on WP Cloud under the user's infrastructure constraint.

References: https://wp.cloud/docs/api/openapi.json and https://wp.cloud/wp-cloud-technical-details/wpcloud-site-ssh-and-sftp/
