#!/usr/bin/env node

import { readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const contract = JSON.parse(await readFile(path.join(repo, "config/deployment-contract.json"), "utf8"));
const hubWorkflow = await readFile(path.join(repo, ".github/workflows/publish-hub-frontend.yml"), "utf8");
const packageWorkflow = await readFile(path.join(repo, ".github/workflows/publish-site-release.yml"), "utf8");
const railwayWorker = await readFile(path.join(repo, "hosted/railway/server.mjs"), "utf8");
const packageJson = JSON.parse(await readFile(path.join(repo, "package.json"), "utf8"));

const failures = [];
if (packageJson.scripts?.["publish:hub"] !== "node hosted/scripts/publish-hub-release.mjs --apply" || contract.canonical_hub.publisher !== "npm run publish:hub") {
  failures.push("publish:hub is not the canonical Hub publisher");
}
if (!hubWorkflow.includes("run: npm run publish:hub")) failures.push("Hub production workflow does not invoke publish:hub");
const workflowCommands = hubWorkflow.split("\n").filter(line => /^\s*(?:run:|\|)/.test(line)).join("\n");
for (const forbidden of ["sftp", "scp", "rsync", "make-hub-release", "cleanup-hub-releases", "current.json"]) {
  if (new RegExp(`\\b${forbidden.replace(".", "\\.")}\\b`).test(workflowCommands)) {
    failures.push(`Hub production workflow contains a second deployment implementation: ${forbidden}`);
  }
}
if (!packageWorkflow.includes("wp-content/dashless-packages")) failures.push("package workflow lost its package-channel target");
for (const forbidden of ["wp-content/uploads/dashless/releases", "npm run publish:hub"]) {
  if (packageWorkflow.includes(forbidden)) failures.push(`package workflow overlaps the public Hub channel: ${forbidden}`);
}
for (const forbidden of ["sftp", "rsync", "scp", "current.json", "release/activate", "DASHLESS_RELEASE_ENABLED"]) {
  if (railwayWorker.includes(forbidden)) failures.push(`Railway worker contains a publishing capability: ${forbidden}`);
}

if (failures.length) {
  console.error(`Deployment path contract failed:\n- ${failures.join("\n- ")}`);
  process.exit(1);
}

console.log("Deployment path contract passed: the public Hub frontend has one production publisher (publish:hub). Package and customer-site channels remain separate.");
