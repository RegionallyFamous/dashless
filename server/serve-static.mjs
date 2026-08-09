import { createReadStream } from "node:fs";
import { stat } from "node:fs/promises";
import { createServer } from "node:http";
import path from "node:path";

const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, index, all) => {
  if (value.startsWith("--")) pairs.push([value.slice(2), all[index + 1]]);
  return pairs;
}, []));
const root = path.resolve(args.root || "dist");
const port = Number(args.port || 4321);
const mime = new Map([
  [".css", "text/css; charset=utf-8"], [".gif", "image/gif"], [".html", "text/html; charset=utf-8"],
  [".ico", "image/x-icon"], [".jpeg", "image/jpeg"], [".jpg", "image/jpeg"], [".js", "text/javascript; charset=utf-8"],
  [".json", "application/json; charset=utf-8"], [".png", "image/png"], [".svg", "image/svg+xml"], [".webp", "image/webp"],
  [".xml", "application/xml; charset=utf-8"], [".txt", "text/plain; charset=utf-8"],
]);

createServer(async (request, response) => {
  try {
    const parsed = new URL(request.url, "http://127.0.0.1");
    const decoded = decodeURIComponent(parsed.pathname);
    const candidates = decoded.endsWith("/")
      ? [path.join(root, decoded, "index.html")]
      : [path.join(root, decoded), path.join(root, decoded, "index.html"), path.join(root, `${decoded}.html`)];
    let file = null;
    for (const candidate of candidates) {
      const resolved = path.resolve(candidate);
      if (!resolved.startsWith(`${root}${path.sep}`) && resolved !== root) continue;
      const info = await stat(resolved).catch(() => null);
      if (info?.isFile()) { file = resolved; break; }
    }
    if (!file) {
      response.statusCode = 404;
      response.end("Not found");
      return;
    }
    response.setHeader("Content-Type", mime.get(path.extname(file).toLowerCase()) || "application/octet-stream");
    response.setHeader("Cache-Control", "no-store");
    createReadStream(file).pipe(response);
  } catch {
    response.statusCode = 400;
    response.end("Bad request");
  }
}).listen(port, "127.0.0.1");
