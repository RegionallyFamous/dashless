// Assemble a minimal deployment context; never send the repository or local credentials.
import fs from 'node:fs/promises';
import path from 'node:path';
const out = path.resolve(process.argv[2] || 'hosted/.local/railway-context');
await fs.mkdir(path.join(out,'runtime'), {recursive:true});
for (const name of ['Dockerfile','railway.toml','server.mjs']) await fs.copyFile(`hosted/railway/${name}`,path.join(out,name));
for (const name of ['package.json','package-lock.json','build.mjs']) await fs.copyFile(`hosted/runtime/${name}`,path.join(out,'runtime',name));
await fs.rm(path.join(out,'template'),{recursive:true,force:true});
await fs.cp('templates/astro',path.join(out,'template'),{recursive:true,filter:p=>!['node_modules','dist','.astro','.dashless-cache'].includes(path.basename(p))&&!path.basename(p).startsWith('.env')});
console.log(out);
