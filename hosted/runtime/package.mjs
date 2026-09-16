import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const root=path.dirname(fileURLToPath(import.meta.url));
const repo=path.resolve(root,'../..');
const output=path.join(repo,'hosted/dist');
const pins=JSON.parse(await fs.readFile(path.join(root,'sources.lock.json'),'utf8'));
const temp=await fs.mkdtemp(path.join(os.tmpdir(),'dashless-runtime-package-'));
const runtime=path.join(temp,'runtime');
const sha=bytes=>createHash('sha256').update(bytes).digest('hex');
async function download(source,file){const r=await fetch(source.url);if(!r.ok)throw new Error(`Download failed: ${r.status}`);const bytes=Buffer.from(await r.arrayBuffer());if(sha(bytes)!==source.sha256)throw new Error('Upstream checksum mismatch');await fs.writeFile(file,bytes);}
async function files(dir,rel=''){const out=[];for(const e of await fs.readdir(path.join(dir,rel),{withFileTypes:true})){if(e.name==='.bin')continue;const r=rel?`${rel}/${e.name}`:e.name;if(e.isDirectory())out.push(...await files(dir,r));else if(e.isFile())out.push(r);else if(e.isSymbolicLink()){const resolved=await fs.realpath(path.join(dir,r));const bytes=await fs.readFile(resolved);await fs.unlink(path.join(dir,r));await fs.writeFile(path.join(dir,r),bytes);out.push(r);}else throw new Error('Unsupported package entry');}return out.sort();}
async function manifest(dir){const result=[];for(const rel of await files(dir)){const bytes=await fs.readFile(path.join(dir,rel));result.push({path:rel,bytes:bytes.length,sha256:sha(bytes)});}return result;}
async function zip(dir,name){for(const rel of await files(dir))await fs.utimes(path.join(dir,rel),new Date('2026-01-01Z'),new Date('2026-01-01Z'));const archive=path.join(temp,name);execFileSync('zip',['-X','-q',archive,'-@'],{cwd:dir,input:(await files(dir)).join('\n')+'\n'});const hash=sha(await fs.readFile(archive));await fs.copyFile(archive,path.join(output,`${hash}.zip`));return {sha256:hash,filename:`${hash}.zip`,bytes:(await fs.stat(archive)).size};}
try{
 await fs.mkdir(runtime,{recursive:true});await fs.mkdir(output,{recursive:true});
 let runtimePackage;
 if(process.argv.includes('--site-only')){runtimePackage=JSON.parse(await fs.readFile(path.join(output,'site-packages.json'),'utf8')).runtime;if(sha(await fs.readFile(path.join(output,runtimePackage.filename)))!==runtimePackage.sha256)throw new Error('Existing runtime checksum mismatch');}
 else {
 for(const rel of ['package.json','package-lock.json','build.mjs','supervise.mjs','sources.lock.json'])await fs.cp(path.join(root,rel),path.join(runtime,rel),{recursive:true});
 await fs.cp(path.join(repo,'templates/astro'),path.join(runtime,'template'),{recursive:true,filter:file=>!['node_modules','dist','.astro','.dashless-cache'].includes(path.basename(file))&&!path.basename(file).startsWith('.env')});
 execFileSync('npm',['ci','--ignore-scripts','--no-audit','--no-fund','--os=linux','--cpu=x64','--libc=glibc'],{cwd:runtime,stdio:'inherit'});
 await fs.rm(path.join(runtime,'node_modules/.bin'),{recursive:true,force:true});
 await download(pins.node,path.join(temp,'node.tar.xz'));
 execFileSync('tar',['-xJf',path.join(temp,'node.tar.xz'),'-C',temp]);
 await fs.mkdir(path.join(runtime,'bin'));await fs.copyFile(path.join(temp,`node-v${pins.node.version}-linux-x64/bin/node`),path.join(runtime,'bin/node'));await fs.chmod(path.join(runtime,'bin/node'),0o755);
 await fs.mkdir(path.join(runtime,'licenses'));await fs.copyFile(path.join(temp,`node-v${pins.node.version}-linux-x64/LICENSE`),path.join(runtime,'licenses/Node-LICENSE'));
 const affinityDir=path.join(temp,'affinity');await fs.mkdir(affinityDir);const affinityDeb=path.join(affinityDir,'affinity.deb');await download(pins.affinity,affinityDeb);const affinityData=execFileSync('ar',['t',affinityDeb],{encoding:'utf8'}).split('\n').find(n=>n.startsWith('data.tar.'));execFileSync('ar',['x',affinityDeb,affinityData],{cwd:affinityDir});execFileSync('tar',['-xf',path.join(affinityDir,affinityData),'-C',affinityDir]);
 await fs.copyFile(path.join(affinityDir,'usr/bin/taskset'),path.join(runtime,'bin/taskset'));await fs.chmod(path.join(runtime,'bin/taskset'),0o755);await fs.copyFile(path.join(affinityDir,'usr/share/doc/util-linux/copyright'),path.join(runtime,'licenses/util-linux-copyright'));
 const fontDir=path.join(temp,'fonts');await fs.mkdir(fontDir);const fontDeb=path.join(fontDir,'fonts.deb');await download(pins.fonts,fontDeb);const fontData=execFileSync('ar',['t',fontDeb],{encoding:'utf8'}).split('\n').find(n=>n.startsWith('data.tar.'));execFileSync('ar',['x',fontDeb,fontData],{cwd:fontDir});execFileSync('tar',['-xf',path.join(fontDir,fontData),'-C',fontDir]);
 await fs.cp(path.join(fontDir,'usr/share/fonts/truetype/dejavu'),path.join(runtime,'fonts'),{recursive:true});await fs.copyFile(path.join(fontDir,'usr/share/doc/fonts-dejavu-core/copyright'),path.join(runtime,'licenses/DejaVu-copyright'));
 await fs.mkdir(path.join(runtime,'lib'));
 for(const lib of pins.libraries){
  const dir=path.join(temp,lib.name);await fs.mkdir(dir);const deb=path.join(dir,'package.deb');await download(lib,deb);const parts=execFileSync('ar',['t',deb],{encoding:'utf8'}).split('\n');const data=parts.find(n=>n.startsWith('data.tar.'));execFileSync('ar',['x',deb,data],{cwd:dir});execFileSync('tar',['-xf',path.join(dir,data),'-C',dir]);
  for(const prefix of ['lib/x86_64-linux-gnu','usr/lib/x86_64-linux-gnu']){let names=[];try{names=await fs.readdir(path.join(dir,prefix));}catch{}for(const n of names){if(!/^(ld-linux|lib(c|m|dl|pthread|rt|resolv|gcc_s|stdc\+\+))[^/]*\.so/.test(n))continue;await fs.copyFile(await fs.realpath(path.join(dir,prefix,n)),path.join(runtime,'lib',n));await fs.chmod(path.join(runtime,'lib',n),0o755);}}
  const copyright=lib.name==='libgcc-s1'||lib.name==='libstdc++6'?path.join(temp,'gcc-12-base/usr/share/doc/gcc-12-base/copyright'):path.join(dir,`usr/share/doc/${lib.name}/copyright`);await fs.copyFile(copyright,path.join(runtime,'licenses',`${lib.name}-copyright`));
 }
 const runtimeManifest={version:pins.version,platform:'linux-x64',node:pins.node.version,astro:JSON.parse(await fs.readFile(path.join(root,'package.json'),'utf8')).dependencies.astro,files:await manifest(runtime)};
 await fs.writeFile(path.join(runtime,'runtime-manifest.json'),JSON.stringify(runtimeManifest,null,2)+'\n');
 runtimePackage=await zip(runtime,'runtime.zip');
 }
 const pluginRoot=path.join(temp,'site'),plugin=path.join(pluginRoot,'dashless-site');await fs.mkdir(plugin,{recursive:true});
 for(const rel of ['dashless-hosted.php','dashless-wpcloud.php','LICENSE','hosted'])await fs.cp(path.join(repo,'wordpress',rel),path.join(plugin,rel),{recursive:true});
 const portable=path.join(plugin,'build-source');await fs.mkdir(portable,{recursive:true});
 for(const rel of ['package.json','package-lock.json','build.mjs','sources.lock.json'])await fs.copyFile(path.join(root,rel),path.join(portable,rel));
 await fs.cp(path.join(repo,'templates/astro'),path.join(portable,'template'),{recursive:true,filter:file=>!['node_modules','dist','.astro','.dashless-cache'].includes(path.basename(file))&&!path.basename(file).startsWith('.env')});
 await fs.copyFile(path.join(repo,'hosted/hub/contracts/tools.v1.json'),path.join(plugin,'hosted/tools.v1.json'));
 await fs.writeFile(path.join(plugin,'site-manifest.json'),JSON.stringify({version:pins.version,contract_version:1,runtime:runtimePackage,files:await manifest(plugin)},null,2)+'\n');
 const site=await zip(pluginRoot,'site.zip');
 await fs.writeFile(path.join(output,'site-packages.json'),JSON.stringify({version:pins.version,site,runtime:runtimePackage},null,2)+'\n');
 await fs.writeFile(path.join(output,'SITE-SHA256SUMS'),`${site.sha256}  ${site.filename}\n${runtimePackage.sha256}  ${runtimePackage.filename}\n`);
 console.log(JSON.stringify({site,runtime:runtimePackage,output},null,2));
}finally{await fs.rm(temp,{recursive:true,force:true});}
