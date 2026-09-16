# WP Cloud build isolation: documentation and API review

Reviewed 2026-09-16 against the live [OpenAPI specification](https://wp.cloud/docs/api/openapi.json), [pricing overview](https://wp.cloud/pricing-2/), and [vertical scaling documentation](https://wp.cloud/vertical-scaling-bursting/). Read-only investigation; no site settings changed.

## Recommended next implementation candidate

Replace the long-lived HTTP worker with a site-scoped WP Cloud task invoking a custom WP-CLI command. Keep two web PHP workers for the initial validation; do not assume that buying more workers solves the observed blocking.

The documented endpoint is `POST /task-create/{client}/run-wp-cli-command`. The client identifier is the configured client name, not an assumed numeric account ID. Always supply `site_run_list` with exactly the intended site ID: without a restriction this API operates across client sites. Suggested request fields:

```json
{
  "args": ["dashless", "build-job", "JOB_UUID"],
  "site_run_list": [152056190],
  "cli_mode": "full",
  "send_webhook_for": "all"
}
```

This is a proposed command that must be implemented, not an existing command. The API expects form-encoded fields; the JSON above illustrates their structure. `GET /task-get/{atomic_task_id}` retrieves task details. The API documents a five-minute runtime cap and 1,200 MB maximum memory for the WP-CLI task. The observed 95-second build is within the time cap; peak aggregate memory was not measured, so compliance with the memory cap is not established. Dispatch latency and custom subprocess support must also be tested.

### What each customer site needs

- A Dashless companion plugin registering the WP-CLI command, short authenticated submission/status endpoints, and private preview/release handling.
- Durable owner-scoped job records, idempotency keys, one active build per site, leases and bounded recovery. A second submission persists promptly while an earlier task runs.
- A versioned Node/native-library build package, or another verified runtime appropriate to the task environment. The SSH and HTTP environments differed in the prior tests; task execution is another environment to verify.
- Consistent content snapshots and local media reads, bounded image processing, and immutable artifacts. Preserve the previous published release until a complete validated build is explicitly activated.
- Runtime, memory and queue limits, plus monitoring of build duration, uncached latency, PHP worker usage, limiting and HTTP errors.

The central account/control application can itself be WordPress on WP Cloud. It stores the platform credential server-side and dispatches only authorized site-scoped tasks. Do not distribute a client-wide WP Cloud API key to each tenant plugin or ChatGPT. Its network egress must satisfy the API allowlist; that setup is not proven by the existing local API access.

This removes the intentional long-running web request from the proposed design. It does not prove independent CPU/memory allocation: the public specification does not promise that tasks or their Node children use resources isolated from web traffic. Repeat the responsiveness test with this execution path before rollout. Test three-user requests during a full build, prompt second-job acceptance, sequential execution, timeout/failure recovery, and task memory use.

## Other documented options

`default_php_conns` controls permitted concurrent PHP connections; client-settable range is 2–10. The pricing page associates workers with CPU cores. A four-worker site is a reasonable comparison experiment, not a proven minimum or guarantee. Saved user-provided account pricing would make four workers at default memory $17/site/month ($5 base + two additional workers at $6); verify the partner contract before quoting retail economics.

`php_memory_limit` supports 512, 1024, 1536 and 2048 MB per PHP request. Increasing it does not establish more available connections or a larger separate Node allocation. The successful build supplies no evidence that this setting needs increasing.

`burst_php_conns`: 0 explicitly disables bursting, 1 is legacy enabled, 2 permits up to twice the default, and negative integers specify an additive bonus; server ceilings still apply. An unset site value alone does not establish an effective inherited burst policy. Cost and account eligibility for these modes need verification. Do not interpret 2 as two extra workers, or toggle bursting merely to conceal a blocking implementation.

`POST /crontab/{site}/add` also supports shell commands in an SSH-like environment, including shell scripts and WP-CLI. Standard schedules and shorthand frequencies are supported; full cron expressions require advanced cron. Maximum runtime is eight hours; overlapping runs of the same entry are skipped, with at most three entries executing per site. This corrects the earlier PHP-only cron claim. Scheduled execution is a fallback/recovery mechanism; an every-minute cron is not required by the proposed on-demand task path.

If same-site tasks still disrupt interactive traffic, test a separate WP Cloud build site with its own site allocation, authenticated snapshot/artifact transfer, durable dispatch and bounded concurrency. This preserves WP Cloud-only hosting but introduces shared build-service cost and tenant isolation work. It is not yet tested, and should not be billed as a free internal/staging site without eligibility confirmation.

## Additional evidence from the failed HTTP run

A later metrics query included a worker maximum of two at build startup. Edge logs confirmed timed-out requests as HTTP 499 at about 15 seconds, plus an HTTP 429; rate-limit reason fields were empty. This corroborates the failure but does not identify its exact cause. The retrieved time series still does not provide continuous samples throughout the build, so it cannot establish sustained CPU or worker saturation.

## Decision

Implement and test the native task/WP-CLI path before recommending extra resources on every customer site. There is no documented setting that guarantees the current build implementation cannot make the site unresponsive. The intended initial configuration remains two web workers plus a properly queued platform task, conditional on passing the concurrency test and runtime checks.
