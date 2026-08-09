import { copyFile, mkdir, readFile } from "node:fs/promises";
import path from "node:path";
import sharp from "sharp";

const WIDTH = 1200;
const HEIGHT = 630;
const generationCache = new Map();

function xml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&apos;");
}

function truncate(value, maximum) {
  const text = String(value ?? "").trim();
  return text.length > maximum ? `${text.slice(0, maximum - 1).trimEnd()}…` : text;
}

function wrap(value, maximum, limit = 4) {
  const words = String(value ?? "").trim().split(/\s+/).filter(Boolean);
  const lines = [];
  let current = "";
  for (const word of words) {
    const chunks = word.length > maximum ? word.match(new RegExp(`.{1,${maximum}}`, "g")) : [word];
    for (const chunk of chunks) {
      const candidate = current ? `${current} ${chunk}` : chunk;
      if (candidate.length <= maximum) current = candidate;
      else {
        if (current) lines.push(current);
        current = chunk;
      }
      if (lines.length === limit) break;
    }
    if (lines.length === limit) break;
  }
  if (current && lines.length < limit) lines.push(current);
  const consumed = lines.join(" ").replace(/…$/, "");
  if (consumed.length < words.join(" ").length && lines.length) {
    lines[lines.length - 1] = truncate(lines[lines.length - 1], Math.max(maximum - 1, 2));
    if (!lines[lines.length - 1].endsWith("…")) lines[lines.length - 1] += "…";
  }
  return lines.length ? lines : ["Untitled"];
}

function titleMarkup(title) {
  const length = String(title ?? "").length;
  const fontSize = length <= 46 ? 68 : length <= 78 ? 56 : 47;
  const maximum = fontSize >= 68 ? 19 : fontSize >= 56 ? 23 : 28;
  const lineHeight = Math.round(fontSize * 1.03);
  return wrap(title, maximum, 4)
    .map((line, index) => `<tspan x="68" dy="${index === 0 ? 0 : lineHeight}">${xml(line)}</tspan>`)
    .join("");
}

function formattedDate(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return new Intl.DateTimeFormat("en-US", { month: "short", day: "2-digit", year: "numeric" }).format(date).toUpperCase();
}

function baseSvg({ siteName, title, category, date, displayHost, hasImage }) {
  const cleanSiteName = truncate(siteName || "WordPress", 32);
  const cleanHost = truncate(displayHost || "wordpress.local", 64);
  const label = truncate(category || "Story", 24).toUpperCase();
  const dateLabel = formattedDate(date);
  return `
    <svg xmlns="http://www.w3.org/2000/svg" width="${WIDTH}" height="${HEIGHT}" viewBox="0 0 ${WIDTH} ${HEIGHT}">
      <rect width="1200" height="630" fill="#f2ead6"/>
      <path d="M0 0h1200v58H0z" fill="#4757ff"/>
      <circle cx="30" cy="29" r="10" fill="#ff4fa3" stroke="#17151f" stroke-width="4"/>
      <circle cx="61" cy="29" r="10" fill="#ffd84a" stroke="#17151f" stroke-width="4"/>
      <circle cx="92" cy="29" r="10" fill="#b8f33d" stroke="#17151f" stroke-width="4"/>
      <rect x="123" y="14" width="1015" height="30" rx="2" fill="#fffaf0" stroke="#17151f" stroke-width="4"/>
      <text x="143" y="35" fill="#17151f" font-family="Menlo, Consolas, monospace" font-size="14" font-weight="700">https://${xml(cleanHost)}/story</text>
      <path d="M0 58h1200v572H0z" fill="#fffaf0"/>
      <path d="M0 58h1200v572H0z" fill="none" stroke="#17151f" stroke-width="8"/>
      <path d="M34 94h716" stroke="#17151f" stroke-width="3" stroke-dasharray="8 9" opacity=".3"/>
      <rect x="68" y="112" width="${Math.max(126, label.length * 12 + 34)}" height="37" fill="#b8f33d" stroke="#17151f" stroke-width="3"/>
      <text x="84" y="137" fill="#17151f" font-family="Menlo, Consolas, monospace" font-size="15" font-weight="900" letter-spacing="1">${xml(label)}</text>
      ${dateLabel ? `<text x="68" y="181" fill="#665f70" font-family="Menlo, Consolas, monospace" font-size="15" font-weight="800" letter-spacing="1.2">${xml(dateLabel)}</text>` : ""}
      <text x="68" y="245" fill="#17151f" font-family="Arial, Helvetica, sans-serif" font-size="${String(title ?? "").length <= 46 ? 68 : String(title ?? "").length <= 78 ? 56 : 47}" font-weight="900" letter-spacing="-2.2">${titleMarkup(title)}</text>
      <path d="M68 548h682" stroke="#17151f" stroke-width="5"/>
      <text x="68" y="586" fill="#4757ff" font-family="Georgia, serif" font-size="30" font-weight="900">${xml(cleanSiteName)}</text>
      <text x="744" y="586" text-anchor="end" fill="#17151f" font-family="Menlo, Consolas, monospace" font-size="13" font-weight="800">WORDPRESS → ASTRO</text>
      <rect x="812" y="103" width="342" height="424" fill="#2ddbd1" stroke="#17151f" stroke-width="6"/>
      ${hasImage ? "" : `
        <path d="M983 157l31 96 101 1-82 59 31 96-81-60-82 60 32-96-82-59 101-1z" fill="#ffd84a" stroke="#17151f" stroke-width="6"/>
        <rect x="849" y="431" width="268" height="55" fill="#ff4fa3" stroke="#17151f" stroke-width="4" transform="rotate(-3 983 458)"/>
        <text x="983" y="465" text-anchor="middle" fill="#17151f" font-family="Arial, Helvetica, sans-serif" font-size="22" font-weight="900">OPEN WEB ORIGINAL</text>
      `}
      <rect x="1114" y="82" width="58" height="58" fill="#ff4fa3" stroke="#17151f" stroke-width="5" transform="rotate(8 1143 111)"/>
      <text x="1143" y="123" text-anchor="middle" fill="#17151f" font-family="Arial, Helvetica, sans-serif" font-size="34" font-weight="900">✦</text>
    </svg>`;
}

function frameOverlay() {
  return Buffer.from(`
    <svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
      <rect x="812" y="103" width="342" height="424" fill="none" stroke="#17151f" stroke-width="6"/>
      <rect x="832" y="474" width="302" height="37" fill="#17151f"/>
      <text x="983" y="499" text-anchor="middle" fill="#fffaf0" font-family="Menlo, Consolas, monospace" font-size="13" font-weight="800">FEATURED_IMAGE.WP</text>
    </svg>`);
}

async function featuredComposite({ featuredImagePath, featuredImageUrl }) {
  try {
    let source;
    if (featuredImagePath) source = await readFile(featuredImagePath);
    else if (featuredImageUrl) {
      const response = await fetch(featuredImageUrl);
      if (!response.ok) return null;
      source = Buffer.from(await response.arrayBuffer());
    }
    if (!source) return null;
    return await sharp(source).rotate().resize(322, 404, { fit: "cover", position: "attention" }).png().toBuffer();
  } catch {
    return null;
  }
}

async function renderCard(options, destination) {
  const featured = await featuredComposite(options);
  const layers = featured ? [
    { input: featured, left: 822, top: 113 },
    { input: frameOverlay(), left: 0, top: 0 },
  ] : [];
  await mkdir(path.dirname(destination), { recursive: true });
  await sharp(Buffer.from(baseSvg({ ...options, hasImage: Boolean(featured) })))
    .composite(layers)
    .png({ compressionLevel: 9 })
    .toFile(destination);
}

export async function generateSocialCard({ publicDir, distDir, fileName, releasePrefix = "", ...content }) {
  const safeName = String(fileName).replace(/[^A-Za-z0-9._-]/g, "-");
  const relative = `/_dashless/social/${safeName}`;
  const destination = path.join(publicDir, relative);
  if (!generationCache.has(destination)) generationCache.set(destination, renderCard(content, destination));
  await generationCache.get(destination);
  const buildDestination = path.join(distDir, relative);
  await mkdir(path.dirname(buildDestination), { recursive: true });
  await copyFile(destination, buildDestination);
  return releasePrefix ? `${releasePrefix}${relative}` : relative;
}
