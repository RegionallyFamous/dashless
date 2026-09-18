import {readdir,readFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import path from 'node:path';
const root=path.resolve('hosted');
async function walk(dir){const result=[];for(const entry of await readdir(dir,{withFileTypes:true})){if(['vendor','dist','.local','node_modules'].includes(entry.name))continue;const file=path.join(dir,entry.name);if(entry.isDirectory())result.push(...await walk(file));else result.push(file);}return result;}
let count=0;
for(const file of [...await walk(root),...await walk(path.resolve('wordpress'))]) {
 if(file.endsWith('.php')){const r=spawnSync('php',['-l',file],{encoding:'utf8'});if(r.status!==0)throw new Error(r.stdout+r.stderr);count++;}
 if(/\.(mjs|cjs|js)$/.test(file)){const r=spawnSync(process.execPath,['--check',file],{encoding:'utf8'});if(r.status!==0)throw new Error(r.stdout+r.stderr);count++;}
 if(file.endsWith('.json'))JSON.parse(await readFile(file,'utf8'));
}
const tools=JSON.parse(await readFile(path.join(root,'hub/contracts/tools.v1.json'),'utf8'));
if(new Set(tools.map(t=>t.name)).size!==tools.length)throw new Error('Duplicate hosted tool');
if(tools.some(t=>/shell|exec|setup_site|checkout|subscription|billing/.test(t.name)))throw new Error('Infrastructure/commerce tool exposed');
for(const tool of tools) {
 if(!tool.scope || tool.inputSchema.additionalProperties!==false)throw new Error('Tool needs scope and closed argument schema: '+tool.name);
 if(Array.isArray(tool.inputSchema.required) && tool.inputSchema.required.length===0)throw new Error('Omit empty required array for OpenAI-compatible tool schema: '+tool.name);
}
console.log(`${count} PHP/JS syntax checks passed; ${tools.length} scoped hosted tools validated.`);
