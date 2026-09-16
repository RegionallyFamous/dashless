# Developer connection and WP Cloud deployment

Verified against official OpenAI documentation on 2026-09-16; UI labels can change. The current documentation calls the directory entry a plugin and recommends a Universal MCP URL.

## Install the exact release

1. Build and package from README commands; inspect `hosted/chatgpt/dist/release-manifest.json`. Install `dashless-hub-chatgpt-0.1.0.zip` on the dedicated Hub at `https://dashless.blog`. The ZIP contains the original Hub, Composer dependencies, ChatGPT PHP adapter and bundled resource. It excludes fixture data, test credentials and developer node_modules.
2. Keep all production data, static artifacts and builds on WP Cloud. No external Node/Cloudflare runtime is needed. Preserve existing Hub encryption and OAuth signing keys; never regenerate them during a routine update.
3. Configure the existing OAuth public client ID and **exact** redirect URI shown by the connection management screen. The Hub supports a predefined public client (`none` authentication), S256, audience/resource checks and issuer identification. It does not claim CIMD or DCR. For issuers satisfying RFC 9207, current ChatGPT uses a stable callback; always copy the actual value rather than relying on an example.
4. Set `DASHLESS_CHATGPT_WIDGET_ORIGIN=https://dashless.blog` as the dedicated app origin, unique to this app. This is the component resource domain, not a separate production host. Resources are self-contained: CSP connect/resource domain arrays are empty; only the Hub is an allowed external redirect. The browser preview adds an exact authenticated customer origin to its frame-src. UI does not embed third-party frames.
5. After a real supported transfer, set `DASHLESS_CHATGPT_FILE_ORIGINS` to the exact observed official HTTPS `oaiusercontent.com` download origin(s), comma-separated. Default is empty and denies all remote downloads. Never use wildcards, arbitrary customer URLs or a broad OpenAI domain allowlist. Do not log the signed URL. Confirm WP Cloud egress permits that host. Both source expiry and the owner-bound upload session must be valid.
6. Deploy the site runtime with the base capabilities plus `chatgpt_upload_v1` and `rollback_approval_v1`. See the additive contract document. Installing a Hub update alone does not implement those capabilities. `runtime: true` must mean the pinned runtime has actually verified.
7. Exclude `/mcp`, OAuth, account/preview pages and private REST routes from all CDN/page caches. Preserve Authorization, Accept, Content-Type, MCP-Protocol-Version and WWW-Authenticate. The stateless POST endpoint returns JSON, 202 for notifications, and 405 for an unsupported GET stream. It does not issue MCP session IDs or hold an SSE connection open.
8. Verify HTTPS discovery at `/.well-known/oauth-protected-resource/mcp` and `/.well-known/oauth-authorization-server`. Send POST `/mcp` with Accept `application/json, text/event-stream`, Content-Type `application/json`. Supported MCP revisions: 2025-11-25, 2025-06-18, 2025-03-26; unknown requested initialization versions negotiate to 2025-11-25, unknown subsequent protocol headers fail.

## Connect from ChatGPT

1. In ChatGPT Settings → Security and login, enable Developer mode if account/workspace policy permits it.
2. Open ChatGPT Plugins, select plus, choose a public connection, and enter `https://dashless.blog/mcp`.
3. Configure the predefined OAuth client, then sign in to an isolated existing Dashless account and grant only the required scopes.
4. Verify Scan Tools finds 26 tools, including fileParams metadata and UI resource `ui://dashless/workflow-v1.html`. Start a new conversation with the connection enabled.
5. Run all review scenarios. Inspect the model transcript separately from component `_meta`: no review ticket, preview session, approval credential or temporary file URL may occur in tool output text/structuredContent. File inputs use OpenAI's supported fileParams envelope; the adapter never echoes the URL into output.
6. Verify Publish opens Dashless. Complete the real browser preview, check its private asset requests, approve, then refresh the component or request the returned job. A completed preview job can follow its browser-created publication job through the owner-bound Hub record. Test account switching and expired/disconnected review links.
7. Disconnect through Dashless, confirm token and outstanding handoff rejection, then reconnect. Repeat with a second isolated subscriber. Test backgrounding, resume, mobile, attachment transfer failures, stale previews and build failure.
8. After metadata changes, refresh the connection, start a new conversation and rerun affected scenarios.

Developer mode and mocked screenshots do not establish public availability. Do not flip checkout gates based on these results.

## Sources

- [Authentication, callbacks, and OAuth linking](https://developers.openai.com/plugins/build/auth)
- [MCP server and streamable HTTP](https://developers.openai.com/plugins/build/mcp-server)
- [MCP Apps UI bridge](https://developers.openai.com/plugins/build/chatgpt-ui)
- [File APIs, fileParams, resources and private metadata](https://developers.openai.com/plugins/reference)
- [Developer connection](https://developers.openai.com/plugins/deploy/connect-chatgpt)
- [Submission and public publishing](https://developers.openai.com/plugins/deploy/submission)
