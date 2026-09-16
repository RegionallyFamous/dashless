#!/usr/bin/env node
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { runAuditCLI } from '../../../templates/astro/scripts/audit-dist.mjs';
export { auditSite } from '../../../templates/astro/scripts/audit-dist.mjs';
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) runAuditCLI();
