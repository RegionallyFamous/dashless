# Dashless ChatGPT app

Customer-facing remote MCP adapter and MCP Apps component, hosted with the PHP Hub on WP Cloud. Connect existing Dashless accounts. No infrastructure, commerce, checkout or subscription tools are exposed. This is an integration build; public submission/publication has not occurred.

## Build and test

```sh
npm ci --prefix hosted/chatgpt --ignore-scripts
npm --prefix hosted/chatgpt run build
npm --prefix hosted/chatgpt test
npm --prefix hosted/chatgpt run test:browser
# Only against an expendable WordPress marked WP_ENVIRONMENT_TYPE=local:
php hosted/chatgpt/tests/integration.php /tmp/dashless-wp-test
DASHLESS_TEST_URL=http://localhost:8874 node hosted/chatgpt/tests/http.mjs
npm run check
npm run check:hosted
npm --prefix hosted/chatgpt run package
```

The integration suite includes the existing Hub suite, which deletes only the Hub table in the supplied disposable installation. It uses actual WordPress and League OAuth but fixture site/Cloud/Stripe services. Never run it on customer or production data. Browser tests render the actual packaged component with a local MCP Apps fixture host; screenshots are not real ChatGPT screenshots.

## Implementation

- `src/`: standards-based MCP Apps component, pure phase labels, short polling with capped exponential backoff and cancellation.
- `php/Integration.php`: tool metadata, resource serving, credential projection, account/site/epoch-bound browser handoffs.
- `php/Uploads.php`: authenticated image sessions and bounded OpenAI-to-WP-Cloud binary relay.
- `tools.json`: three additive Hub tools, preserving the 23 site schemas.
- `dist/workflow.html`: generated self-contained component. Deploy through the packaged Hub, not an external static host.
- `docs/site-contract-extension.md`: exact compatible upload and rollback boundary.
- `submission/`: listing draft, scenarios, reviewer setup, screenshots and release checklist.
- Hub `Mcp.php`, `OAuth.php`, `Routes.php`, `Previews.php`: integration and browser approval boundary.

Media is supplied through ChatGPT attachments and the `import_chatgpt_file` tool. The component has no upload control, file picker, image form or media editor.

The UI deliberately uses the authenticated browser fallback. MCP Apps visibility, `_meta`, `event.isTrusted`, and model-supplied approval flags are not attestations of a human publication event. The component opens a hidden-metadata handoff; the browser verifies its owner and the site's identity. Only a verified WordPress login cookie plus REST nonce can reach Publish/rollback. Site approval binds and consumes the exact immutable snapshot. Preview HTML and its assets are served by the site's protected browser session. Nothing in this component treats a render or a click message as publication approval.

Existing `publish_previewed` and `rollback_release` schemas remain available for compatibility, but neither mints approval and normal browser approval immediately queues the action server-side. No approval credential is returned in model-visible data. Fallback links remain useful to MCP clients without UI by directing their users to sign in and reopen the matching preview; temporary credentials are not put in conversation text.

## Branding

The Rip is copied from the selected SVG. Hot Type uses the actual selected raster concept; `hot-type-display.png` is a proportional 768px optimization of the same sheet and CSS displays its selected wordmark. No replacement font or new mark. The original remains unchanged. The wordmark still needs a vector master, and the icon still needs final optical review. Branding applies only to Dashless controls, never customer publications.

Runtime dependencies are pinned in package-lock.json and Composer lock. Package generation includes dependency/license manifests and checksums. No Node service or build host runs in production.
