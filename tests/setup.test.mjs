import assert from "node:assert/strict";
import { mkdir, mkdtemp, stat } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";
import { createMockWordPress } from "./helpers/mock-wordpress.mjs";

const dataDirectory = await mkdtemp(path.join(tmpdir(), "dashless-setup-data-"));
process.env.DASHLESS_DATA_DIR = dataDirectory;
process.env.DASHLESS_DISABLE_KEYCHAIN = "1";

const { handleRequest } = await import("../server/dashless-mcp.mjs");
const { startSetup, stopSetup } = await import("../server/lib/setup.mjs");
const { getActiveConnection, loadConnections, rememberIdempotentPost, writeDataJson } = await import("../server/lib/storage.mjs");

function formBody(fields) {
  return new URLSearchParams(fields).toString();
}

test("the loopback setup protects credentials and disconnect revokes and erases", async (t) => {
  const mock = await createMockWordPress();
  t.after(async () => {
    await stopSetup();
    await mock.close();
  });

  const setup = await startSetup({ siteUrl: mock.url });
  assert.match(setup.setup_url, /^http:\/\/127\.0\.0\.1:\d+\/$/);
  const opened = await fetch(setup.setup_url);
  const html = await opened.text();
  assert.equal(opened.headers.get("cache-control"), "no-store");
  assert.match(opened.headers.get("content-security-policy"), /frame-ancestors 'none'/);
  assert.doesNotMatch(html, /app-password/);
  const csrf = html.match(/name="csrf" value="([^"]+)"/)?.[1];
  assert.ok(csrf);

  const rejectedCsrf = await fetch(new URL("/connect", setup.setup_url), {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: formBody({ csrf: "wrong", site_url: mock.url, username: "editor", password: "app-password" }),
  });
  assert.equal(rejectedCsrf.status, 400);

  const rejectedCredential = await fetch(new URL("/connect", setup.setup_url), {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: formBody({ csrf, site_url: mock.url, username: "editor", password: "wrong-password" }),
  });
  assert.equal(rejectedCredential.status, 400);
  assert.doesNotMatch(await rejectedCredential.text(), /wrong-password|app-password/);

  const connected = await fetch(new URL("/connect", setup.setup_url), {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: formBody({ csrf, site_url: mock.url, username: "editor", password: "app-password" }),
  });
  assert.equal(connected.status, 200);
  assert.match(await connected.text(), /Connection verified/);

  const active = await getActiveConnection();
  assert.equal(active.password, "app-password");
  await rememberIdempotentPost(active.site.id, "disconnect-test", { id: 99, post_type: "post" });
  for (const directory of ["changes", "previews", "preview-payloads"]) {
    await writeDataJson(path.join(directory, active.site.id, "fixture.json"), { site_id: active.site.id });
  }

  const disconnected = await handleRequest({
    jsonrpc: "2.0",
    id: 1,
    method: "tools/call",
    params: {
      name: "disconnect_site",
      arguments: { confirm: true, revoke_application_password: true, remove_local_data: true },
    },
  });
  assert.equal(disconnected.result.isError, false, disconnected.result.content[0].text);
  assert.equal(disconnected.result.structuredContent.application_password.revoked, true);
  assert.equal(disconnected.result.structuredContent.local_site_data_removed, true);
  assert.equal(mock.state.applicationPasswordRevoked, true);
  const state = await loadConnections();
  assert.equal(state.active_site_id, null);
  assert.equal(state.idempotency[active.site.id], undefined);
  for (const directory of ["changes", "previews", "preview-payloads"]) {
    assert.equal(await stat(path.join(dataDirectory, directory, active.site.id)).catch(() => null), null);
  }
});

test("the setup URL expires", async () => {
  await stopSetup();
  const setup = await startSetup({ expiresInMs: 25 });
  await new Promise((resolve) => setTimeout(resolve, 75));
  await assert.rejects(() => fetch(setup.setup_url));
  assert.deepEqual(await stopSetup(), { stopped: false });
});
