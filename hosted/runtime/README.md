# Site packages — maintenance guide

These files are for the people running Dashless. Writers should never need these commands. Their workflow is simply: save a draft, preview it, approve it, and publish.

This is an integration candidate, not an approved production release. Read `../handoffs/wordpress-result.md` before installing it. Live checkout must remain disabled until the hosting tests and public account journey pass.

## What is packaged

`node hosted/runtime/package.mjs` creates two immutable ZIPs in `hosted/dist/`:

- Site plugin: `wordpress/dashless-hosted.php`, the unchanged local bridge, hosted PHP modules, frozen tool schemas, license, and a file integrity manifest.
- Linux runtime: Node 22.23.2, Astro 7.2.8, dependencies from `package-lock.json`, shared `templates/astro/` source, Debian loader/libraries, the checksum-pinned `taskset` CPU limiter, DejaVu fonts and licenses, and a complete file integrity manifest.

`site-packages.json` links the exact pair. `SITE-SHA256SUMS` verifies both archives. Upstream archive hashes are in `sources.lock.json`; npm integrity values are in `package-lock.json`. The packager excludes local environment files, caches, test fixtures, and credentials. There is no dependency installation during a customer build.

For PHP-only changes, `node hosted/runtime/package.mjs --site-only` reuses the existing verified runtime ZIP. Do not use that option after runtime or shared frontend changes.

## Immutable hosting and bootstrap

Host both archives on the configured Hub at `/dashless-packages/<sha256>.zip`. Do not replace bytes at an existing hash URL. The site archive is small; the runtime archive is approximately 137 MB. The Hub's small-plugin archive limit must not be applied to the separate runtime download.

Activate `dashless-site/dashless-hosted.php` on the new WordPress site through the Hub's locked software configuration. Then dispatch the contract command, with values supplied by the Hub:

```sh
wp dashless bootstrap --operation=OPERATION_UUID --site-id=ATOMIC_SITE_ID --hub=https://dashless.blog --credential-hash=SECRET_SHA256 --package-sha256=SITE_ZIP_SHA256
```

The plaintext per-site secret must never appear in the command. The Hub keeps it encrypted and sends it only in authenticated HTTPS requests. Never install the fleet API key on a subscriber site. The atomic ID must be explicit; it is not the WordPress blog number.

Bootstrap verifies both ZIPs and installed source, rejects identity changes, removes only recognized fresh-install sample pages/posts, and stores entitlement and identity. The Hub then queues an `initial_release` job. Readiness requires the resulting public page to be verified; a responding PHP endpoint is insufficient.

For isolated operator fixtures, `--archive=/trusted/site.zip` and `--runtime-archive=/trusted/runtime.zip` bypass downloading but not checksum checks. `--preserve-content` is explicit operator adoption, never the default for a new subscriber. It was used only to protect Teddy during tests.

Every WP Cloud task must send `site_run_list[0]=THE_SINGLE_AUTHORIZED_ATOMIC_ID` and `site_count_limit=1`. Do not run fleet-wide tests.

## Recovery

The Hub queues expensive work; `wp dashless build-job JOB_UUID` claims a 260-second site lease and has an internal 235-second deadline. Node heap/concurrency are bounded. The supervisor terminates children on deadline or parent loss. Full process-tree RSS is reported only when `/proc` exposes the necessary processes; an unavailable measurement is not a successful capacity check.

On Linux, the launcher reads its existing allowed CPU list and restricts Node and its descendants to the first allowed CPU. It never broadens the host's allocation. If the list or limiter cannot be verified, the build fails closed. Environment-variable thread limits alone were insufficient in native WP Cloud tests: fresh minimal builds repeatedly stopped with five CPUs available and completed with one. This is an execution compatibility finding, not a claim that a specific host memory or CPU quota caused termination. Whole-task memory evidence remains a separate acceptance requirement.

Completed jobs replay without side effects. Failed jobs require an operator to inspect the native task before using `wp dashless build-job JOB_UUID --retry`. If the native process disappeared, first confirm its WP Cloud task is terminal and wait for the lease to expire. Never clear a live lease to force a second build. Expired running jobs tell the Hub that reconciliation is required.

Approved WordPress publication and its saved flag are committed together. A retry can finish publication without saving the post twice. Public activation uses a verification-only candidate first; ordinary readers keep the previous version until verification. Failed verification restores the previous pointer.

Exports are encrypted, checkpointed POSIX tar archives, not ZIPs. A clean bounded checkpoint returns `queued` with `continuation_required: true`; the coordinated Hub code re-dispatches the same UUID only after the previous native task is known complete. `export_id` stays stable. The browser honors the attachment's content type/name. Individual portable tar entries are limited to 8 GiB. A changed source invalidates preparation rather than silently mixing versions. Full 25 GB export stress testing is still required.

Back up the database and encrypted vault together. The vault key lives in WordPress options; losing it makes private files unreadable. `wp dashless rotate-credential --credential-hash=NEW_SHA256` permits the prior credential for 120 seconds, then rejects it. Encryption-key backup/recovery needs a separate operator drill; this command rotates service authentication, not the vault key.

## Tests

```sh
npm run check
node --test hosted/runtime/test.mjs
node hosted/site-tests/processes.mjs
php hosted/site-tests/affinity.php
node hosted/site-tests/refresh-runtime.mjs
php hosted/site-tests/integration.php /tmp/dashless-site-test-EXISTING_FIXTURE
```

The WordPress scripts require disposable local installations created by `site-tests/setup.mjs`; they reset their own fixture data. `refresh-runtime.mjs` creates a macOS/local test manifest only, not a production manifest. `prepare-bootstrap.mjs` and `bootstrap.php` validate the actual release ZIP in a separate disposable installation. See the handoff for exact results and limitations.

`site-tests/native.py` is a temporary, operator-controlled Teddy acceptance helper. It is not packaged. It installs only its own test files, never publishes, and requires explicit `--apply`. Use the required `hosted/scripts/native-acceptance.py` harness through that helper. Cleanup checks the exact operation/site identity, terminal tasks, and expired leases before removing only its own fixture data. Never use it against customer sites.
