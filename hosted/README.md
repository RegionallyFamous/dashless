# Dashless hosted service — implementation 0.1.0

Current cross-component launch decision and Railway acceptance gates: [September 16 launch audit](../docs/launch-audit-2026-09-16.md). Earlier native-build findings below are historical; no paid-launch gate has been waived.

The Hub and block theme are implemented in this directory and deployed at https://dashless.blog. **This is a gated integration build, not a live paid service.** The dedicated WP Cloud Hub is site 152161516. Stripe sandbox product, monthly price, portal, and webhook are configured; public checkout remains disabled. See [deployment status](docs/deployment-2026-09-16.md).

## Installable components

- `hub/`: WordPress Hub plugin, bundled Composer dependencies in release ZIP.
- `theme/`: Dashless block theme, selected The Rip + Hot Type artwork and responsive landing page.
- `hub/contracts/tools.v1.json`: versioned hosted MCP tool definitions.
- `handoffs/README.md`: copy-and-paste prompts for separate WordPress and ChatGPT implementation tasks.
- `docs/contract-v1.md`: exact Hub/site-plugin boundary and acceptance requirements for the separate implementation task.
- `docs/operations.md`: setup, native tasks, recovery, rollout, key backup and launch checks.
- `docs/verification.md`: measured evidence and unverified dependencies.

## Implemented

WordPress.com-only customer identity with browser-bound single-use OAuth state and explicit POST consumption; host-only WordPress sessions; unique address reservation; server-owned $9.99 monthly Stripe Checkout; signed webhook deduplication and current-state reconciliation; grace, cancellation, retained export/recovery, initial provisioning refund orchestration; resumable WP Cloud creation/bootstrap/build verification; durable one-job-per-site dispatch; OAuth via League with PKCE, resource audience, token rotation and revocation; stateless PHP MCP routing; minimal account, billing and export pages; private browser preview/approval fallback; restricted operator recovery, credential rotation, canary rollout and launch evidence controls.

`DASHLESS_LIVE_CHECKOUT` defaults to false. Live activation also requires every evidence gate and credential. Test checkout accepts test keys only. A pause switch blocks new signup and checkout while existing accounts retain access. Policies are visibly marked release drafts.

## Build and verify

```sh
composer install --working-dir=hosted/hub --no-dev --prefer-dist
npm run check:hosted
npm run check
# Against a disposable WordPress installation with this plugin active and WP_ENVIRONMENT_TYPE=local:
php hosted/tests/integration.php /path/to/wordpress
npm run package:hosted
```

The PHP integration test deletes **only the Hub document table** in the disposable local installation, creates fixture users and mocks external services. It never calls Stripe or WP Cloud. See the verification record for what this does and does not establish.

## Provisioning invariant

Every customer site automatically receives the pinned Dashless **site plugin**, installed and activated through WP Cloud's site-creation software configuration (`activate-locked`). Bootstrap and required capability checks must succeed before the initial build and ready state. Customers do not install it manually. The Hub plugin belongs only on dashless.blog.

## Still required before launch

1. Integrate the implemented site-plugin runtime and ChatGPT component against contract v1. Hosted routes and the native CLI now exist; see the WordPress result handoff for passing local checks and the still-failing native build acceptance test.
2. Provision the dedicated Hub; configure actual DNS provider, wildcard/per-site routing, HTTPS, sender authentication, Stripe test account and public OAuth registration.
3. Test full provisioning and real Stripe lifecycle/recovery; prove two-worker responsiveness under a Teddy-sized native build. Accepted task dispatch alone is not a capacity test.
4. Validate native runtime checksum, CPU/memory, private drafts/assets, exact human approvals and public release durability in the site-plugin workstream.
5. Complete operator backup/restore and key persistence drills, final legal/support pages, unit economics and public ChatGPT review. Enable live checkout only afterward.

The owner approved Railway for build execution on September 16, 2026. WordPress sites, accounts, billing records, private previews and final releases remain on WP Cloud. Railway temporarily receives sealed content/media snapshots and returns verified build archives. See [Railway builder](railway/README.md). Local computers are no longer required to execute hosted builds.
