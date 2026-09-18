# Submission gate — September 16, 2026

**Not ready to submit. Not submitted or published.** The theme release is deployed; the submission prerequisites below remain incomplete.

Latest update, 23:56 UTC: the submission draft now has the production MCP URL, OAuth authentication, release notes, and verified domain challenge. Cloudflare proxying is enabled for both WP Cloud origin records so the narrowly scoped challenge route can run at the MCP hostname; WP Cloud remains the origin and Railway remains the build executor. The portal still reports the MCP tools scan and demo recording as incomplete; no final attestation or submission was made. See [discovery deployment](../../oauth-discovery/README.md).

## Auth0 migration — September 17

Current code replaces the old customer sign-in and local OAuth issuer with Auth0. The live website application, production email delivery, existing-owner migration, disconnect permissions and fresh reviewer/ChatGPT journeys must be verified together before deployment is considered complete. The September 16 observations below are historical and do not certify the replacement. See [Auth0 setup](../../docs/auth0.md).

## Verified on the live service — historical September 16 observations

- Production HTTPS MCP endpoint: `https://dashless.blog/mcp`; initialization, public discovery of 28 tools, complete annotation fields, OAuth challenges, and UI resource/CSP delivery pass.
- Server instructions explicitly direct theme discovery during setup/redesign. `list_themes` and `get_theme` return the three editions for authenticated accounts, including an account without a provisioned site. Public HTTPS theme images return 200.
- Theme-aware `update_design` is advertised. Selection is versioned and staged; publication still requires the exact approved preview. Local PHP tests verify defaults, overrides, identity preservation and rejection of stale/unsupported selections.
- The patched Railway image builds each of the three themes successfully. A separate 115-post/three-page/one-image live fixture also passed. This is not the outstanding real media-heavy workload.
- The updated Hub, public theme and immutable customer plugin/runtime packages are installed or published. The Hub points to the verified new customer package. The Small Problems reviewer fixture is now hosted, linked to the dedicated reviewer identity with complimentary access, and published after a clean private-preview check.
- 105 local Hub/ChatGPT integration checks passed, along with theme tests and hosted syntax checks. These are not actual ChatGPT account journeys.

Evidence: [deployment record](../../docs/themes-deployment-2026-09-16.md) and [live protocol audit](../../evidence/themes-release-2026-09-16/public-mcp.json).

## Confirmed blockers

1. **Portal OAuth discovery and domain scan:** production now has signing keys, a predefined public PKCE client and the exact callback displayed in the submission draft. Eight live OAuth entry checks pass, including rejection cases; this does not exercise consent or issue a token. Anonymous GET and empty octet-stream POST return 401 with protected-resource metadata challenges. The OpenAI portal still returns `oauth_config: null` and rejects MCP configuration with “OAuth configuration not found.” No follow-up metadata fetch was observed during the diagnostic probe. The draft has no domain token yet and Scan Tools has not completed. Do not fabricate the token or claim the displayed MCP settings are saved.
2. **Reviewer access and complete product journeys:** the Small Problems fixture and complimentary access remain, but the dedicated reviewer must be verified through Auth0 and explicitly linked to the existing owner. Old provider credentials are obsolete. Actual ChatGPT linking, attachments, preview, publication, rollback, export and reconnect require fresh acceptance evidence.
3. **Final identity and attestation:** Organization Settings shows both Individual and Business **Approved**, and Business — Regionally Famous is saved in the draft. No final attestation has been selected.
4. **Actual ChatGPT UI and recording:** real host CSP/widget/file-transfer checks, Safari/mobile tests, and the required demo recording remain incomplete. Local fixture screenshots are not real ChatGPT captures.
5. **Service completeness:** paid launch remains gated on provisioning, billing, capacity, cleanup/recovery and restore evidence in the [launch audit](../../../docs/launch-audit-2026-09-16.md). Public privacy and terms were updated and deployed with the confirmed legal operator, data practices and applicable user controls. This is not a certification of all international legal requirements or unverified operational practices.

## Portal draft prepared

[Open the draft](https://platform.openai.com/plugins/edit/asdk_app_6aaac82d7898819193821ea1af57d44f/asdk_app_v_6aaac82f251881918d31abb4c4520c64).

Name, description, Productivity category, publisher display name, public links, icons, three starter prompts, five positive and three non-trigger cases were entered. Availability is **Allow all** portal-supported countries. The Business — Regionally Famous developer identity is selected. Reviewer credentials and demo URL are empty. No submission, publication or final attestation occurred. Support forwarding from howdy@regionallyfamous.com to the owner mailbox is active.

Production evidence: [public checks](../../evidence/submission-2026-09-16/public-mcp.json), [OAuth entry checks](../../evidence/submission-2026-09-16/oauth-entry.json), and [final package installation](../../evidence/submission-2026-09-16/hub-installed-final.json).

Auth0 provides OIDC and UserInfo. Configure `openid profile email` alongside blog scopes and verify the actual ChatGPT authorization before claiming workspace domain restrictions work. Do not infer acceptance from discovery metadata alone.

## Rules reviewed

- [Plugin guidelines](https://developers.openai.com/plugins/app-guidelines): purpose, reliability, truthful metadata, tool annotations, minimal inputs/responses, authentication, commerce, privacy, safety and support.
- [Submission requirements](https://developers.openai.com/plugins/deploy/submission): publisher identity, permissions, stable HTTPS endpoint, reviewer credentials, policies, country availability, five positive/three negative scenarios, domain verification and tool scan.
- [Remote MCP review](https://developers.openai.com/plugins/deploy/app-review): account access, real supported-surface tests, metadata review snapshots and updates.

The app serves existing included account features; it exposes no subscription, checkout or upgrade tool. This matches the existing-paid-account exception, but does not permit digital subscription sales inside the app. Tools are scoped to requested editorial actions, and model-visible results filter secrets, tenant identifiers and private URLs. The current audit verifies specific checks; it is not a blanket certification that every policy or real-world behavior has passed.

Prepared materials include listing copy (shortened to the current 30-character limit), production icon files, starter prompts and positive/negative scenarios including themes. Submit only after the blockers above are resolved and attestations are factually supportable. Submission and publication are separate actions; neither was performed.
