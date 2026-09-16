import { config } from "../lib/dashless.mjs";

export function GET() {
  return new Response(`User-agent: *\nAllow: /\nSitemap: ${new URL("/sitemap.xml", config.publicUrl)}\n`, {
    headers: { "Content-Type": "text/plain; charset=utf-8" },
  });
}
