import {cp,mkdir,mkdtemp,readFile,readdir,rm,utimes,writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import path from 'node:path';
const root=path.resolve('hosted'),out=path.join(root,'dist'),stage=await mkdtemp(path.join(tmpdir(),'dashless-release-'));
const normalize=async dir=>{for(const e of await readdir(dir,{withFileTypes:true})){const p=path.join(dir,e.name);if(e.isDirectory())await normalize(p);else await utimes(p,new Date('2026-01-01T00:00:00Z'),new Date('2026-01-01T00:00:00Z'));}await utimes(dir,new Date('2026-01-01T00:00:00Z'),new Date('2026-01-01T00:00:00Z'));};
await mkdir(out,{recursive:true});
const chatgptPackage=spawnSync(process.execPath,[path.join(root,'chatgpt/scripts/package.mjs')],{encoding:'utf8'});
if(chatgptPackage.status!==0)throw new Error(chatgptPackage.stderr || chatgptPackage.stdout);
const manifest={version:'0.1.0',contract:1,checkout:'disabled by default',packages:{}};
try {
 for(const [source,slug] of [['hub','dashless-hub'],['theme','dashless']]){
  if(source==='hub') {
   // The Hub now requires its ChatGPT adapter; reuse the complete allowlisted package.
   const unpack=spawnSync('/usr/bin/unzip',['-q',path.join(root,'chatgpt/dist/dashless-hub-chatgpt-0.1.0.zip'),'-d',stage],{encoding:'utf8'});
   if(unpack.status!==0)throw new Error(unpack.stderr);
  } else await cp(path.join(root,source),path.join(stage,slug),{recursive:true,filter:file=>!['.git','.env','auth.json'].includes(path.basename(file))});
  if(source==='hub') {
   await readFile(path.join(stage,slug,'vendor/autoload.php'));
   await readFile(path.join(stage,slug,'chatgpt/php/Integration.php'));
  }
  await normalize(path.join(stage,slug));
  const name=`${slug}-0.1.0.zip`,target=path.join(out,name);await rm(target,{force:true});
  const r=spawnSync('/usr/bin/zip',['-qr',target,slug],{cwd:stage,encoding:'utf8'});if(r.status!==0)throw new Error(r.stderr);
  const bytes=await readFile(target);manifest.packages[name]={sha256:createHash('sha256').update(bytes).digest('hex'),bytes:bytes.length};
 }
 await writeFile(path.join(out,'manifest.json'),JSON.stringify(manifest,null,2)+'\n');console.log(JSON.stringify(manifest,null,2));
}finally{await rm(stage,{recursive:true,force:true});}
