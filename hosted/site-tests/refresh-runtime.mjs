// Disposable macOS test manifest. Production uses package.mjs's complete Linux file manifest.
import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
const root=path.resolve('hosted/runtime');const files=[];
for(const rel of ['build.mjs','supervise.mjs','package-lock.json','package.json']){const bytes=await fs.readFile(path.join(root,rel));files.push({path:rel,bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')});}
await fs.writeFile(path.join(root,'runtime-manifest.json'),JSON.stringify({version:'0.1.0',local_fixture:true,files}));
console.log('Local test runtime manifest refreshed; production artifacts unchanged.');
