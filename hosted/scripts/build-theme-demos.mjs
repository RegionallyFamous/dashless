import { cp, mkdir, rm } from "node:fs/promises";
import path from "node:path";
import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const demoRoot = path.join(repo, "examples/small-problems");
const canonicalDist = path.join(demoRoot, ".canonical/dist");
const outputRoot = path.join(repo, "hosted/hub-frontend/dist/demos");
const themes = ["hypertext-diary", "field-notes", "after-hours", "bulletin", "sunroom", "mono-press"];
const baseUrl = process.env.DASHLESS_DEMO_ORIGIN || "https://dashless.blog";

const run = (theme) => new Promise((resolve, reject) => {
  const child = spawn(process.execPath, [path.join(demoRoot, "demo/run.mjs"), "build"], {
    cwd: repo,
    env: {
      ...process.env,
      DASHLESS_DEMO_THEME: theme,
      DASHLESS_DEMO_URL: `${baseUrl}/demos/${theme}/`,
      DASHLESS_BASE_PATH: `/demos/${theme}`,
    },
    stdio: "inherit",
  });
  child.on("error", reject);
  child.on("exit", (code) => code ? reject(new Error(`Theme demo build failed for ${theme}`)) : resolve());
});

await rm(outputRoot, { recursive: true, force: true });
await mkdir(outputRoot, { recursive: true });
for (const theme of themes) {
  console.log(`Building ${theme} demo from templates/astro...`);
  await run(theme);
  await cp(canonicalDist, path.join(outputRoot, theme), { recursive: true });
}
console.log(`Built ${themes.length} static theme demos in ${outputRoot}.`);
