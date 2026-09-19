# Auth0 migration verification — September 17, 2026

The replacement is implemented locally. This record does **not** claim a production cutover, delivered passwordless email, migrated reviewer account, or successful live ChatGPT authorization.

## Passed locally

| Check | Result |
|---|---|
| `npm run check` | 48 tests passed, including the existing local Codex/Astro workflow |
| `npm run check:hosted` | 100 PHP/JS syntax checks; 25 scoped hosted tools validated |
| `php hosted/tests/integration.php /tmp/dashless-wp-test` | 81 checks passed (historical run) |
| `php hosted/chatgpt/tests/integration.php /tmp/dashless-wp-test` | 105 additional checks passed |

## September 18 rerun

After the source checkout was repaired to resolve the ChatGPT adapter from
`hosted/chatgpt` when the packaged Hub path is absent, the disposable fixture
was rerun. The Hub integration passed **84 checks** and the ChatGPT integration
passed **105 checks**. The rerun also covered empty MCP input schemas and the
full two-account draft, upload, preview, approval, rollback, export, and
disconnect cases. These remain fixture results, not real Auth0, ChatGPT, mail,
payment, or WP Cloud acceptance.
| `node hosted/chatgpt/tests/http.mjs` | 30 actual local PHP HTTP checks passed |
| `node hosted/tests/browser.cjs` | 21 local browser checks passed |
| `php hosted/tests/auth0-mail.php /tmp/dashless-wp-test` | 35 mail-connection checks passed, including the real REST route; all mail intercepted |
| `node --test hosted/tests/auth0-mail-action.test.cjs` | 18 Action checks passed; all network requests intercepted |

Auth0 responses are simulated through WordPress HTTP hooks, but signature verification uses real ephemeral RSA keys and the packaged JWT library. Tests cover fixed callback, S256 code verifier, browser-bound expiring single-use state, ID-token nonce, strictly verified email, explicit owner migration preserving billing/site/role, invalid signatures/issuer/audience/time/scopes, machine-token denial, local-password rejection, grant revocation filtered to owner/API, fail-closed disconnect, and invalidation of existing access tokens and private-review handoffs. The ChatGPT suite retains two-account isolation and cookie/nonce/origin checks for human publication approval.

These tests create only disposable local fixture records. They do not send email, charge cards, provision WP Cloud sites, or bypass production authentication. The real-sites harness now uses the Auth0 fixture; it was not rerun in this migration and old real-sites results must not be relabeled as new Auth0 acceptance.

HTTP/browser tests used the disposable Hub at `http://localhost:8874` with fixture Auth0 environment settings. Provider metadata was seeded by the integration fixture. No browser test signs into a real Auth0 account.

## Live setup and remaining acceptance

The signed-in tenant is `dev-wvp1p7454zhwvgn8` (US). The existing unused Default App was configured, with user approval, as the **Dashless** Regular Web Application (`W4HbbqcnWHhWDgE8SQ9lNXNRlLsQaNai`): exact callback `https://dashless.blog/auth/callback`, login URI `/auth/login`, logout return `https://dashless.blog/`, RS256/OIDC, and authorization-code flow only. Embedded cross-origin authentication is disabled. No passwords were changed.

The existing M2M application `jkX02IQaQ0fUHZoSh2om5hUmptF42N6a` was authorized, with user approval, for only `read:grants` and `delete:grants` on the Auth0 Management API. The dashboard confirms 2 of 273 client permissions granted; no user-update or deletion scopes were granted. This app still has no client permissions on the Dashless MCP API. Secrets have not been copied into Hub configuration.

An email passwordless connection was created with its defaults but left disabled for applications and not promoted to domain level. No emails were sent. Auth0 explicitly labels its current email sender testing-only. The owner declined another email vendor. WP Cloud's read-only site response confirms an SMTP credential exists for Hub 152161516, but this does not prove deliverability or external SMTP access. A mail-transport inspection through the Tasks API was rejected because `eval` is disallowed; no diagnostic email was sent, and that restriction was not bypassed.

The [host-backed email connection](../auth0/README.md) is now implemented locally and staged on the Hub: a dependency-free Auth0 Action signs short-lived requests to a Hub endpoint that validates tenant/application, limits recipients/rates/size, suppresses accepted retries and uses the existing `wp_mail` transport. Authentication codes remain entirely issued and checked by Auth0. The Custom Provider and its dedicated key are configured in Auth0, and Auth0 recorded the one approved test-send request to `me@nickhamze.com`. The message was not visible in the local inbox during verification, so delivery is **not** certified; check spam/quarantine and Auth0 action logs before enabling the passwordless connection. No browser login cutover or domain-level promotion was performed.

Follow-up diagnosis and repair: Auth0 had no `Failed Sending Notification` entry for either test request, and the public Hub route responded normally. The domain was missing WP Cloud's published sender-authentication records. Cloudflare DNS is now aligned with WP Cloud's current guidance:

- the existing registrar-forwarding SPF is consolidated with `include:_spf.wpcloud.com`;
- `wpcloud1._domainkey` and `wpcloud2._domainkey` CNAME records point to WP Cloud's DKIM keys;
- `_dmarc` publishes `v=DMARC1; p=none;` while delivery is monitored.

The authoritative Cloudflare zone shows all three changes. Public resolvers may take up to 48 hours to converge; 8.8.8.8 already returns the new SPF value while 1.1.1.1 is still cached. Once propagation completes, send one fresh Auth0 test and inspect spam/quarantine before enabling passwordless sign-in.

Complete the [Auth0 deployment checklist](auth0.md) before calling this live: working email delivery, server-held credentials, discovery alignment, fresh configuration backup, existing-owner linking, policy-page update, and actual login/logout/ChatGPT refresh/disconnect/reconnect. Another task is configuring the same tenant and portal; coordinate the cutover rather than overwriting its settings. Paid launch gates stay closed. This turn did not deploy the replacement Hub, publish a release, commit, or push.
