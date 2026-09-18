# Ecosystem audit — September 18, 2026

This is a read-only release audit plus one source-documentation correction. It covers the root plugin, hosted Hub, WordPress companion, theme package, Auth0 discovery, live Dashless transport, and ChatGPT submission materials.

## Checks that passed

- `npm run check`: 50 root tests passed.
- `npm run check:hosted`: 100 PHP/JS syntax checks and 25 scoped hosted-tool checks passed.
- `npm run test:template`, `npm run test:frontend`, and the Auth0 mail-action suite passed.
- Hosted packages build successfully. The current package hashes are recorded in `hosted/dist/manifest.json`.
- Live `/mcp` returns the expected OAuth challenge; protected-resource and authorization-server metadata return 200; PKCE S256, all 28 advertised tools, theme discovery, all three public theme previews, and the domain-challenge route pass the read-only live check.
- `/auth/login` returns a private 303 redirect to the Auth0 custom domain and sets a secure, HttpOnly, SameSite=Lax login-state cookie.
- `/sign-in/`, `/account/`, and `/support/` redirect safely to the homepage sections; `/privacy/`, `/terms/`, and `/preview/` return 200.

## Confirmed issues and fixes

1. **High — live policy copy was stale; fixed.** The existing Privacy and Terms pages preserved retired WordPress.com language. Both pages were updated through a site-scoped WP-CLI task using the reviewed `Policies::content()` copy, and live checks now report zero retired-provider references.
2. **High — real ChatGPT authentication remains unverified.** Transport, discovery, metadata, and JWT validation checks pass, but there is still no captured real consent, token exchange, refresh, disconnect, reviewer journey, or portal tool scan. The submission readiness file correctly keeps these gates closed.
3. **Medium — browser acceptance is not self-contained.** `hosted/tests/browser.cjs` still requires a disposable Hub at `http://localhost:8874`, but it now fails with a direct fixture setup message instead of an opaque browser connection error.
4. **Medium — discovery metadata was narrower than the Auth0 OIDC metadata used by the Hub; fixed.** The Cloudflare proxy now advertises Auth0 UserInfo and `openid`, `profile`, and `email` alongside the three blog scopes. Protected-resource metadata remains limited to blog scopes. The live check asserts this contract.
5. **Low — release image payload was oversized; reduced.** Twenty-six unreferenced historical image variants were removed from the theme package and from the deployed theme asset directory. The rebuilt theme ZIP is 16.7 MB, down from 24.7 MB. Stale local package archives were also removed, reducing `hosted/dist` by about 1.2 GB while retaining the pinned site/runtime artifacts.

The follow-up OAuth probe also found and fixed a missing `jwks_uri` in the public authorization metadata. The stricter live OAuth check now passes alongside the general readiness check.

## Source drift corrected during this audit

The OAuth discovery README and historical MU-plugin shim named the retired `dashless-oauth-discovery.nickhamze.workers.dev` issuer. They now identify the Cloudflare discovery route and the live Auth0 issuer `https://auth.dashless.blog/`; the shim is explicitly inert and must not be installed.

## Release decision

The core code, live policies, public transport, metadata, and theme package are healthy enough for continued portal work. The app is still not ready to submit until the real ChatGPT/reviewer journeys and portal scan are captured. No submission or publication was performed by this audit.
