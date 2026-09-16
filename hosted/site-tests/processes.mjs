import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {spawn} from 'node:child_process';
const temp=await fs.mkdtemp(path.join(os.tmpdir(),'dashless-process-tests-'));
const done=p=>new Promise((resolve,reject)=>{p.on('error',reject);p.on('close',(code,signal)=>resolve({code,signal}));});
try{
 await fs.copyFile(new URL('../runtime/supervise.mjs',import.meta.url),path.join(temp,'supervise.mjs'));
 await fs.writeFile(path.join(temp,'build.mjs'),`import fs from 'node:fs';import {spawn} from 'node:child_process';const child=spawn(process.execPath,['-e','setInterval(()=>{},1000)'],{stdio:'ignore'});fs.writeFileSync(process.argv[2]+'/children.json',JSON.stringify([process.pid,child.pid]));setInterval(()=>{},1000);`);
 for(const test of ['deadline','parent_lost']){
  const work=path.join(temp,test);await fs.mkdir(work);
  const parent=spawn(process.execPath,['-e','setInterval(()=>{},1000)'],{stdio:'ignore'});const parentDone=done(parent);
  const runner=spawn(process.execPath,[path.join(temp,'supervise.mjs'),work,String(parent.pid),String(Date.now()+(test==='deadline'?450:10000))],{stdio:'ignore'});const completed=done(runner);
  let ids;for(let n=0;n<100;n++){try{ids=JSON.parse(await fs.readFile(path.join(work,'children.json'),'utf8'));break;}catch{await new Promise(r=>setTimeout(r,20));}}
  assert.ok(ids,'child and grandchild started');if(test==='parent_lost'){parent.kill('SIGKILL');await parentDone;}
  const status=await completed;assert.equal(status.code,1);const result=JSON.parse(await fs.readFile(path.join(work,'process-result.json'),'utf8'));assert.equal(result.reason,test==='deadline'?'build_deadline':'parent_lost');
  for(const pid of ids){let alive=true;try{process.kill(pid,0);}catch(e){if(e.code==='ESRCH')alive=false;}assert.equal(alive,false,`child ${pid} reaped after ${test}`);}
  parent.kill('SIGTERM');await parentDone;console.log(`PASS ${test}: bounded supervisor terminates and reaps child tree`);
 }
}finally{await fs.rm(temp,{recursive:true,force:true});}
