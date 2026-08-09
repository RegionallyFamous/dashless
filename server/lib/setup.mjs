import { createServer } from "node:http";
import { randomBytes } from "node:crypto";
import { URLSearchParams } from "node:url";
import { DashlessError } from "./errors.mjs";
import { normalizeSiteUrl, saveSiteConnection } from "./storage.mjs";
import { WordPressClient } from "./wordpress.mjs";

let activeServer = null;

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function page({ csrf, siteUrl = "", error = "", complete = false }) {
  if (complete) {
    return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<title>Dashless connected</title><style>${styles}</style></head>
<body><main><div class="mark">D</div><p class="eyebrow">DASHLESS</p><h1>Your WordPress site is connected.</h1>
<p class="lede">You can close this tab and return to Codex. Your Application Password was stored locally and was never sent through chat.</p>
<div class="success">✓ Connection verified</div></main></body></html>`;
  }
  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<title>Connect Dashless</title><style>${styles}</style></head>
<body><main><div class="mark">D</div><p class="eyebrow">DASHLESS</p><h1>Connect WordPress</h1>
<p class="lede">Use a dedicated WordPress user with an Application Password. The credential goes directly from this loopback-only page to local storage.</p>
${error ? `<div class="error">${escapeHtml(error)}</div>` : ""}
<form method="post" action="/connect" autocomplete="off">
<input type="hidden" name="csrf" value="${escapeHtml(csrf)}">
<label>WordPress site <input required type="url" name="site_url" value="${escapeHtml(siteUrl)}" placeholder="https://cms.example.com"></label>
<div class="authorize"><button type="button" class="secondary" id="authorize">Create an Application Password in WordPress ↗</button>
<small>WordPress opens in a new tab. Approve “Dashless,” then copy the generated username and password here.</small></div>
<label>WordPress username <input required name="username" autocomplete="username"></label>
<label>Application Password <input required type="password" name="password" autocomplete="new-password"></label>
<button type="submit">Connect WordPress</button>
</form>
<p class="foot">Nothing is uploaded to Dashless. This page is available only on your computer and expires automatically.</p>
</main><script nonce="dashless">document.getElementById('authorize').addEventListener('click',()=>{const input=document.querySelector('[name=site_url]');try{const url=new URL(input.value);url.pathname=url.pathname.replace(/\/$/,'')+'/wp-admin/authorize-application.php';url.search='?app_name='+encodeURIComponent('Dashless');window.open(url.toString(),'_blank','noopener,noreferrer')}catch{input.focus()}});</script></body></html>`;
}

const styles = `
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#231f1b;background:#f3eee6;font-synthesis:none}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:32px;background:radial-gradient(circle at 15% 0%,#fff8ed 0,transparent 35%),#f3eee6}main{width:min(100%,560px);background:#fffdf9;border:1px solid #dacfc1;border-radius:24px;padding:42px;box-shadow:0 22px 70px rgba(66,44,27,.13)}.mark{width:44px;height:44px;display:grid;place-items:center;color:white;background:#e14b32;border-radius:14px;font:bold 22px Georgia,serif;box-shadow:inset 0 -3px 0 rgba(0,0,0,.12)}.eyebrow{font-size:12px;letter-spacing:.18em;font-weight:800;color:#a13c2a;margin:22px 0 10px}h1{font:700 clamp(32px,7vw,48px)/1.03 Georgia,serif;letter-spacing:-.025em;margin:0 0 14px}.lede{font-size:17px;line-height:1.55;color:#675d54;margin:0 0 28px}.error,.success{padding:13px 15px;border-radius:11px;margin:0 0 20px;font-weight:650}.error{background:#fff0ed;color:#8f2818;border:1px solid #f2c4bb}.success{background:#edf8ef;color:#256b35;border:1px solid #b9ddc1}form{display:grid;gap:20px}label{display:grid;gap:8px;font-size:13px;font-weight:750;color:#51473f}input{width:100%;border:1px solid #cfc3b6;border-radius:11px;background:#fff;padding:13px 14px;font:inherit;color:#231f1b;outline:none}input:focus{border-color:#e14b32;box-shadow:0 0 0 3px rgba(225,75,50,.12)}button{border:0;border-radius:11px;background:#e14b32;color:white;padding:14px 18px;font:750 15px inherit;cursor:pointer}button:hover{filter:brightness(.96)}button.secondary{width:100%;background:#2d2925}.authorize{display:grid;gap:8px}.authorize small,.foot{color:#7b7168;line-height:1.45}.foot{font-size:12px;margin:26px 0 0;border-top:1px solid #e7dfd7;padding-top:18px}@media(max-width:560px){body{padding:0}main{border:0;border-radius:0;min-height:100vh;padding:32px 24px}}
`;

function securityHeaders(response) {
  response.setHeader("Content-Type", "text/html; charset=utf-8");
  response.setHeader("Cache-Control", "no-store");
  response.setHeader("Content-Security-Policy", "default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-dashless'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
  response.setHeader("X-Content-Type-Options", "nosniff");
  response.setHeader("Referrer-Policy", "no-referrer");
}

async function readForm(request) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > 16 * 1024) throw new DashlessError("setup_request_too_large", "Setup form exceeded 16 KB.");
    chunks.push(chunk);
  }
  return new URLSearchParams(Buffer.concat(chunks).toString("utf8"));
}

export async function stopSetup() {
  if (!activeServer) return { stopped: false };
  const { server, timer } = activeServer;
  clearTimeout(timer);
  await new Promise((resolve) => server.close(resolve));
  activeServer = null;
  return { stopped: true };
}

export async function startSetup({ siteUrl = "", expiresInMs = 15 * 60 * 1000 } = {}) {
  if (activeServer) {
    return { setup_url: activeServer.url, expires_at: activeServer.expiresAt, reused: true };
  }

  const csrf = randomBytes(24).toString("base64url");
  let completed = false;
  let lastError = "";
  let prefill = siteUrl;
  const server = createServer(async (request, response) => {
    securityHeaders(response);
    if (request.method === "GET" && request.url === "/") {
      response.end(page({ csrf, siteUrl: prefill, error: lastError, complete: completed }));
      return;
    }
    if (request.method === "POST" && request.url === "/connect") {
      try {
        const form = await readForm(request);
        if (form.get("csrf") !== csrf) throw new DashlessError("setup_expired", "This setup page expired. Start setup again.");
        prefill = normalizeSiteUrl(form.get("site_url") || "");
        const username = form.get("username")?.trim() || "";
        const password = form.get("password") || "";
        if (!username || !password) throw new DashlessError("missing_credentials", "Enter the WordPress username and Application Password.");
        const client = new WordPressClient({ siteUrl: prefill, username, password });
        const inspection = await client.inspectSite();
        await saveSiteConnection({
          siteUrl: prefill,
          username,
          password,
          siteName: inspection.site_name,
          userId: inspection.user.id,
          capabilities: inspection.capabilities,
        });
        completed = true;
        lastError = "";
        response.end(page({ csrf, complete: true }));
      } catch (error) {
        lastError = error?.message || "Connection failed.";
        response.statusCode = 400;
        response.end(page({ csrf, siteUrl: prefill, error: lastError }));
      }
      return;
    }
    response.statusCode = 404;
    response.end("Not found");
  });

  await new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", resolve);
  });
  const address = server.address();
  const url = `http://127.0.0.1:${address.port}/`;
  const lifetime = Math.min(Math.max(Number(expiresInMs) || 15 * 60 * 1000, 25), 15 * 60 * 1000);
  const expiresAt = new Date(Date.now() + lifetime).toISOString();
  const timer = setTimeout(() => {
    server.close();
    activeServer = null;
  }, lifetime);
  timer.unref();
  server.unref();
  activeServer = { server, url, expiresAt, timer };
  server.on("close", () => {
    clearTimeout(timer);
    activeServer = null;
  });
  return { setup_url: url, expires_at: expiresAt, reused: false };
}
