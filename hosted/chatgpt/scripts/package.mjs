import {cp,mkdir,mkdtemp,readFile,readdir,rm,writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {chromium} from '@playwright/test';
const root=fileURLToPath(new URL('../',import.meta.url)),hub=path.resolve(root,'../hub');
const run=(cmd,args,opts={})=>{const r=spawnSync(cmd,args,{encoding:'utf8',...opts});if(r.status!==0)throw Error(r.stderr||r.stdout);return r.stdout;};
run(process.execPath,[path.join(root,'scripts/build.mjs')]);
const browser=await chromium.launch({headless:true});
try{const page=await browser.newPage();const svg=await readFile(path.join(root,'assets/rip.svg'),'utf8');for(const size of [512,128]){await page.setViewportSize({width:size,height:size});await page.setContent(`<html><body style="margin:0;background:#dfff00;display:grid;place-items:center;width:100vw;height:100vh"><div style="width:76%;height:76%">${svg}</div></body></html>`);await page.screenshot({path:path.join(root,`submission/icon-${size}.png`)});}}finally{await browser.close();}
const stage=await mkdtemp(path.join(tmpdir(),'dashless-chatgpt-release-')),target=path.join(stage,'dashless-hub');
const out=path.join(root,'dist');await mkdir(target,{recursive:true});
const digest=bytes=>createHash('sha256').update(bytes).digest('hex');
async function walk(dir){const all=[];for(const d of await readdir(dir,{withFileTypes:true})){const p=path.join(dir,d.name);if(d.isSymbolicLink())throw Error('Symlinks are not packaged: '+p);if(d.isDirectory())all.push(...await walk(p));else all.push(p);}return all;}
try{
 for(const name of ['src','assets','contracts','vendor','composer.json','composer.lock','dashless-hub.php','readme.txt'])await cp(path.join(hub,name),path.join(target,name),{recursive:true,filter:file=>!/(^|\/)(?:\.env[^/]*|auth\.json|\.git)$/.test(file)});
 await readFile(path.join(target,'vendor/autoload.php'));
 await cp(path.resolve(root,'../../templates/astro/src/lib/themes.json'),path.join(target,'contracts/themes.v1.json'));
 await cp(path.resolve(root,'../../wordpress/hosted/theme-previews'),path.join(target,'assets/theme-previews'),{recursive:true});
 const component=path.join(target,'chatgpt');await mkdir(component,{recursive:true});
 for(const name of ['php','tools.json','README.md','LICENSE'])await cp(path.join(root,name),path.join(component,name),{recursive:true});
 await readFile(path.join(component,'php/Integration.php'));
 await mkdir(path.join(component,'dist'),{recursive:true});
 for(const name of ['workflow.html','manifest.json'])await cp(path.join(root,'dist',name),path.join(component,'dist',name));
 const composer=JSON.parse(await readFile(path.join(hub,'composer.lock'),'utf8'));
 const lock=JSON.parse(await readFile(path.join(root,'package-lock.json'),'utf8'));
 const npm=[];const licenses=path.join(component,'licenses');await mkdir(licenses,{recursive:true});
 for(const [location,entry] of Object.entries(lock.packages)){
  if(!location || entry.dev)continue;
  const folder=path.join(root,location);const pkg=JSON.parse(await readFile(path.join(folder,'package.json'),'utf8'));
  npm.push({name:pkg.name,version:pkg.version,license:pkg.license,integrity:entry.integrity});
  for(const name of await readdir(folder))if(/^(?:LICENSE|COPYING|NOTICE)(?:\..*)?$/i.test(name)){await cp(path.join(folder,name),path.join(licenses,pkg.name.replaceAll('/','_')+'-'+name));}
 }
 const dependencies={component:npm,php:composer.packages.map(p=>({name:p.name,version:p.version,license:p.license,reference:p.dist?.reference})),buildTools:{esbuild:'0.28.2',playwright:'1.58.2',axe:'4.11.1'},art:{rip:digest(await readFile(path.join(root,'assets/rip.svg'))),hotTypeSource:digest(await readFile(path.join(root,'assets/hot-type-v3.png'))),hotTypeDisplay:digest(await readFile(path.join(root,'assets/hot-type-display.png'))),limitation:'Hot Type remains raster concept art; display file is a proportional optimization.'}};
 await writeFile(path.join(component,'dependencies.json'),JSON.stringify(dependencies,null,2)+'\n');
 const files={};for(const file of await walk(target)){const rel=path.relative(target,file);if(/(?:\.pem|\.env|auth\.json|wp-config\.php)$/.test(rel))throw Error('Disallowed release path');files[rel]={sha256:digest(await readFile(file))};}
 await writeFile(path.join(component,'files.json'),JSON.stringify(files,null,2)+'\n');
 const filename='dashless-hub-chatgpt-0.1.0.zip';await rm(path.join(out,filename),{force:true});run('/usr/bin/zip',['-qr',path.join(out,filename),'dashless-hub'],{cwd:stage});
 const bytes=await readFile(path.join(out,filename));
 const manifest={version:'0.1.0',contract:1,source:'Hub plus ChatGPT adapter; no customer runtime bundled',checkout:'unchanged, gated',file:filename,sha256:digest(bytes),bytes:bytes.length,files:Object.keys(files).length,component:JSON.parse(await readFile(path.join(out,'manifest.json'),'utf8'))};
 await writeFile(path.join(out,'release-manifest.json'),JSON.stringify(manifest,null,2)+'\n');await writeFile(path.join(out,'dependencies.json'),JSON.stringify(dependencies,null,2)+'\n');
 console.log(JSON.stringify(manifest,null,2));
}finally{await rm(stage,{recursive:true,force:true});}
