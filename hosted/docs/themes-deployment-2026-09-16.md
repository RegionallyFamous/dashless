# Theme release — September 16, 2026

Deployed to the live Hub at `https://dashless.blog/mcp` and Railway. Public ChatGPT submission remains blocked; see the [updated readiness audit](../chatgpt/submission/readiness.md).

## Installed release

| Component | Evidence |
| --- | --- |
| Hub / ChatGPT adapter | WP Cloud native task 759179 succeeded; archive SHA-256 `c4b67fd931cc9cbe1a8ca0f985b00f288c1fb5109d5f3ded375abf434332e142` |
| Dashless public theme | Native task 759173 succeeded; archive SHA-256 `21a16e06da3b1c7060ea1dec2cde611bd72d51f5b8b3f487341444687a739f9b` |
| Customer plugin package | SHA-256 `4d90fd2671f6f91caa2257233afe8df2d24905c5fd7ffb329e095f38e544cc06`; publicly fetched and hashed; live Hub provisioning pointer matches |
| Runtime package | SHA-256 `4d7a41e1ced4c9fbe12948c28162c6c4ed37acdc4c31949d5fba527a011e4b9e`; published alongside the site package; every manifest byte verified |
| Railway builder | Deployment `7dc745bb-3a66-4378-adf3-dc504b7de4bb`, SUCCESS; shared theme source and patched dependency lock deployed |

The Hub's configured site plugin version is now 0.1.0. No provisioned customer accounts existed, so no customer rollout was necessary. The existing Small Problems reader remains unchanged. Checkout is still disabled.

## Theme discovery and behavior

The public MCP scan exposes 28 tools including `list_themes`, `get_theme`, and theme-aware `update_design`. Initialization instructions tell the model to offer the catalog during site setup/redesign, read the selected edition, apply defaults plus explicit overrides, preserve content/identity, and preview before publication.

Catalog reads are served from the Hub for any authenticated account, including the existing unprovisioned account. The deployed dispatcher returned Hypertext Diary, Field Notes and After Hours with HTTPS sample screenshots, versioned defaults and customization options. Applying a theme still requires an active ready customer site with `theme_catalog_v1`. This verifies the server; it is not evidence that an actual ChatGPT connection has been configured or refreshed.

For a developer-mode connection, refresh its tool metadata and start a new conversation after OAuth is configured. For submission, Scan Tools imports the live metadata. Published tool changes are subject to OpenAI's continuous review. See [OpenAI review documentation](https://developers.openai.com/plugins/deploy/app-review).

## Verification

- Live initialization, all 28 tool schemas/annotation fields, unauthenticated OAuth challenge, and UI resource/CSP passed.
- All three public theme PNGs return 200 with PNG content type.
- The catalog editions built on the live Railway service; verified archive hashes and generated `data-design` / palette values. The current catalog has six editions; this dated record predates the later catalog expansion.
- A separate 115-post, three-page, one-media Railway fixture passed, producing 134 HTML pages. This does not prove the outstanding real media-heavy workload.
- 105 local Hub/ChatGPT integration checks, 3 focused theme tests, and 87 hosted syntax checks passed. Prior frontend QA covers six scenarios, 27 presentation combinations and 320/390/768/1280 widths.
- The immutable site package was downloaded from its live public URL and matched its pinned hash.

Sanitized evidence is in [themes-release-2026-09-16](../evidence/themes-release-2026-09-16/). No tokens, passwords, signing material or signed preview URLs are included.

## Deployment recovery notes

The first Railway upload failed before producing a new running image because the ignored staging context was not included correctly. Deploying from the minimal context with `--no-gitignore` succeeded. Do not run that flag against the repository root.

The first Hub installation stopped before replacing the live plugin because a cross-filesystem directory rename to `/tmp` was unavailable. Same-filesystem staging and backup succeeded. The final Hub patch guarantees HTTPS catalog image URLs even in native CLI execution. Temporary maintenance commands and staged configuration copies were removed after verification.

Previous plugin/theme directories are retained under `.dashless-previous-hub-20260916`, `.dashless-before-final-20260916`, and `.dashless-previous-theme-20260916` in their respective WordPress plugin/theme parents. Owner-only configuration backups are under `~/.config/dashless/hub/theme-release-20260916/` on the deployment machine. Restore matching code/config/package pointers together if rollback is required; keep the Railway volume and master key. The preceding Railway deployment was `8e91946d-6792-467d-a529-aec0f227b519` (now REMOVED after replacement); rollback availability must be checked in Railway, not assumed.

## Publisher and support follow-up

The owner selected Regionally Famous and requested availability in all countries offered by the OpenAI submission portal. These choices are recorded in the listing; portal configuration/publication has not occurred.

Created the explicit Cloudflare Email Routing rule `howdy@regionallyfamous.com` → `me@nickhamze.com` and verified its Active status in the dashboard. Existing catch-all, hey and hello rules remain active. No DNS changes were needed. This verifies configuration, not a test message delivered to the inbox.

Set production `DASHLESS_SUPPORT_EMAIL` to `howdy@regionallyfamous.com`; verified the public mailto link and reran the read-only production MCP audit successfully. Preserved the prior configuration in the owner-only local release backup.
