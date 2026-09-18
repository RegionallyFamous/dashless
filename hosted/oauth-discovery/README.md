# OAuth discovery hosting

WP Cloud's edge returned 429 for OpenAI's aiohttp GET discovery requests before WordPress ran. Its access logs confirmed all attempted well-known paths were blocked. Public discovery runs on the Cloudflare Worker route attached to `dashless.blog`; consent, token exchange, revocation and authenticated MCP calls remain on the Hub. The authorization server is Auth0 at `https://auth.dashless.blog/`.

Deploy with `wrangler deploy -c hosted/oauth-discovery/wrangler.jsonc`. Do not install the historical `hub-issuer.php` shim; the Hub now reads its Auth0 issuer from protected `auth0_issuer` configuration. Keep Worker metadata in agreement with `OAuth::metadata()` and `OAuth::protectedMetadata()`. The issuer must match metadata, authorization responses and signed access tokens. The optional developer callback is an exact additional allowlisted URL, not a wildcard.

On September 16, 2026, ChatGPT returned HTTP 200 from its OAuth configuration discovery with `pkce_required: true`, `pkce_methods: ["S256"]`, issuer `https://auth.dashless.blog/`, and resource `https://dashless.blog/mcp`. Developer plugin creation proceeded to “Connect Dashless.” A live ChatGPT test chat reached the installed Dashless app, but the model did not invoke the connector; authenticated MCP testing remains incomplete. Temporary static discovery files and the diagnostic MU plugin were removed.

Validation: all 105 ChatGPT integration checks passed using the separate discovery issuer, including real local PKCE token issuance and refresh. Public discovery was verified over valid HTTPS with OpenAI's observed user agent. No final submission occurred.
