# Auth0 sign-in

Dashless customers use their email to sign in. They do not need a WordPress.com account. Auth0 handles sign-in and the ChatGPT connection; WordPress keeps the existing blogs, subscriptions and account records.

This replaces customer authentication only. WP Cloud hosting and its infrastructure API are unchanged. Restricted WordPress administrator recovery remains available to the operator; customers cannot sign in with local WordPress passwords or reset them.

## Configure Auth0

Use one tenant and one stable issuer for both the website and ChatGPT. Do not switch between its canonical and custom domains after linking users: ownership is bound to the exact issuer and subject, not just email. A custom domain must already be verified and covered by valid TLS.

1. **Website application:** create or configure a Regular Web Application. Use RS256 ID tokens and `client_secret_post` token authentication. Allow exactly `https://dashless.blog/auth/callback`; allowed logout URL `https://dashless.blog/`; application login URI `https://dashless.blog/auth/login`. No wildcard callbacks. The Hub sends code flow with S256 PKCE, browser-bound single-use state and an ID-token nonce. It requests only `openid profile email` and never stores provider tokens.
2. **Email sign-in:** configure Universal Login with the passwordless email connection enabled for the website and ChatGPT applications. Use email one-time codes with verified production delivery. The owner does not want another email service: the [host-backed email connection](../auth0/README.md) now has a tested local implementation using Auth0's Custom Email Provider Action and the Hub's existing `wp_mail` transport. It is not deployed or inbox-tested yet. Auth0's built-in sender is testing-only. Verify actual receipt and fresh-browser login before promising passwordless access. New Universal Login uses codes; literal magic links require Classic Login. Do not enable unverified-email access or silently merge identities from different connections. Auth0 requires domain-level connection promotion for third-party apps such as the existing ChatGPT client; this exposes the login method to all third-party apps in that tenant, so get explicit approval before promoting it.
3. **Blog API:** identifier exactly `https://dashless.blog/mcp`, signing algorithm RS256, permissions `blog:read`, `blog:write`, `blog:publish`. Suggested access-token lifetime: 900 seconds. Enable offline access when ChatGPT requests it; configure refresh-token rotation and bounded lifetime in Auth0, not the Hub. If the ChatGPT client sends `resource` without Auth0's `audience`, configure and verify this API as the tenant's default audience. Check the actual issued token; discovery alone is insufficient.
4. **ChatGPT application:** use the real registered/CIMD application and exact callbacks supplied by OpenAI. Require code flow with S256 PKCE and the supported client authentication method. Request `openid profile email` as well as the blog permissions so first-time connections can retrieve a verified email from UserInfo. Auth0 owns consent, code exchange and refresh. The Hub is not a token proxy. Preserve a working portal registration instead of creating duplicate clients.
5. **Disconnect service:** use a separate machine-to-machine application authorized for the Auth0 Management API with only `read:grants` and `delete:grants`. These are tenant-level privileges: keep its secret on the Hub only. Runtime requests are narrowed and checked against the exact signed-in user and blog API. Do not grant user deletion, account updates, or billing access. Management API audience uses the canonical tenant domain even if login uses a custom domain.

Store the six Auth0 settings from `config.example.php` in protected server configuration, outside Git and public web roots. Never place secrets in screenshots, the ChatGPT app, a customer plugin or frontend JavaScript. Keep the existing encryption key; deleting the old OAuth signing keys does not authorize changing the encryption key.

## Existing accounts

Existing blog, subscription, payment and site records stay in place. An old sign-in does not automatically become a new one just because email addresses match.

1. Independently verify the owner of the existing account.
2. Have that person sign in through Auth0 with a verified email. A collision records a one-hour pending connection and shows a safe support message.
3. On the Hub only, inspect `wp dashless-hub pending-auth0`, then run `wp dashless-hub link-auth0 --user=EXACT_EXISTING_LOGIN --confirm-owner` for that confirmed owner. The command requires exactly one fresh pending identity and refuses conflicting bindings. A matching pre-release subject without an issuer can be repaired using the same verified process.
4. Ask the person to sign in again. Old browser sessions and preview handoffs are invalidated; roles, blogs and billing are preserved. Reconnect ChatGPT through Auth0. Never fabricate provider IDs or set email verification manually in production.

## Disconnect and sign out

Sign out clears the Hub session and redirects through Auth0 logout. It requires the account's sign-out nonce and does not revoke ChatGPT.

Disconnect blocks ChatGPT locally before contacting Auth0, invalidates private review handoffs, and deletes this owner's grants for the blog API. This removes associated refresh-token authorization. Previously issued JWTs remain blocked by an issuance-time cutoff. If Auth0 cannot confirm revocation, access stays blocked and the account shows a retry message. Only a successfully completed disconnect permits a new authorization. Never clear the blocked flag manually to hide an outage.

## Deployment checklist

- Coordinate the website, Auth0 tenant and public discovery changes as one cutover. This source change is **not** evidence of a live deployment.
- Back up the current Hub files/configuration and preserve the stable encryption key. Stage the new Hub with its Composer dependencies; do not upload just the changed PHP files against the old vendor directory.
- Publish the actual Auth0 discovery document. If the Cloudflare edge serves discovery, its issuer/endpoints must match Auth0 exactly, including the trailing slash. Do not keep a fabricated local issuer or relay authorization codes. The Hub still serves protected-resource metadata for `https://dashless.blog/mcp`.
- Remove obsolete `DASHLESS_WPCOM_*`, local `DASHLESS_OAUTH_*` issuer/client/signing-key settings and the old discovery-issuer MU plugin after verifying the replacement. Retired `/auth/wordpress/*` and `/oauth/*` routes return 410; existing connections must reconnect. Keep historical backups protected until the migration is verified.
- Update the existing privacy/terms pages from the reviewed `Policies` content; installation intentionally preserves existing pages. Remove old sign-in instructions from saved page content. Clear only affected public caches; private auth/account/MCP responses must stay uncached.
- Verify a fresh email login, existing-owner migration, sign-out, ChatGPT consent, actual audience/scopes, refresh and disconnect/reconnect, private review return, and cross-account denial. Check the reviewer account separately; don't claim old reviewer credentials still work.
- Leave paid launch gates closed until their existing requirements are met. No subscriptions, hosting settings or customer content are changed by the identity migration.

## References

- [Auth0 passwordless Universal Login](https://auth0.com/docs/authenticate/passwordless/implement-login/universal-login)
- [Auth0 access-token validation](https://auth0.com/docs/secure/tokens/access-tokens/validate-access-tokens)
- [Auth0 grant and refresh-token revocation](https://support.auth0.com/center/s/article/Refresh-token-revocation)
- [Management API audience with a custom domain](https://support.auth0.com/center/s/article/Service-not-enabled-within-domain)
- [Auth0 custom email delivery](https://auth0.com/docs/customize/email/smtp-email-providers/custom)
- [OpenAI authentication and identity providers](https://developers.openai.com/plugins/build/auth#choosing-an-identity-provider)
