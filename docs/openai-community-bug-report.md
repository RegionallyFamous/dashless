# OAuth callback relay rejects valid MCP authorization code during Scan Tools

**Category:** ChatGPT Apps SDK / MCP / OAuth

## Summary

The OpenAI plugin submission portal cannot complete the OAuth flow for a pre-defined PKCE MCP client. The authorization server successfully authenticates the user, records the consent grant, and redirects with a valid authorization code, state, and issuer. ChatGPT then rejects the callback before making any token request, and the portal never imports tools.

## Reproduction

1. Open the plugin draft for app `asdk_app_6aaac82d7898819193821ea1af57d44f`.
2. On **MCP Server**, use OAuth and click **Scan Tools**.
3. In the **Authorize MCP** dialog, click **Continue**.
4. Complete Auth0 Universal Login and approve the requested scopes.
5. Observe the redirect to `https://chatgpt.com/connector_platform_oauth_redirect`.

## Expected result

ChatGPT exchanges the authorization code with the token endpoint using PKCE, then scans and displays the MCP tools.

## Actual result

The callback is rejected before token exchange. The browser ends at `https://chatgpt.com/skills` and shows “Missing OAuth callback data”. The console reports:

- `App connect OAuth callback rejected Object`
- Minified React error `#418`

The portal returns to the MCP page without a tool list, so the scan remains incomplete.

## Server-side evidence

Auth0 logs show a successful login and consent grant with:

- audience: `https://dashless.blog/mcp`
- scopes: `blog:publish blog:read blog:write offline_access`
- redirect URI: `https://chatgpt.com/connector_platform_oauth_redirect`

There is no subsequent token exchange event in Auth0. An isolated authorization-code/PKCE flow against the same endpoints succeeds. The same failure reproduces after a full portal reload and with a fresh scan.

## Environment

- App ID: `asdk_app_6aaac82d7898819193821ea1af57d44f`
- MCP URL: `https://dashless.blog/mcp`
- Authorization server: Auth0 custom domain at `https://auth.dashless.blog/`
- Date observed: 2026-09-17 UTC
- Browser: Chrome on macOS

Please check the `chatgpt.com/connector_platform_oauth_redirect` relay and advise whether there is a current workaround or a known incident affecting pre-defined PKCE MCP clients.
