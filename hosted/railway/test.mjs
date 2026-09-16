import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { randomUUID, createHash } from 'node:crypto';
import { createBuilder, siteToken } from './server.mjs';
import { fixture, fixtureImage } from '../../tests/fixtures/publication/site.mjs';
const master='a'.repeat(64), hash=b=>createHash('sha256').update(b).digest('hex');
async function setup(t, executor) {
 const root=await fs.mkdtemp(path.join(os.tmpdir(),'dashless-builder-'));
 const app=await createBuilder({root,master,executor,runtime:path.resolve('hosted/runtime')});
 await new Promise(r=>app.server.listen(0,'127.0.0.1',r));
 const base=`http://127.0.0.1:${app.server.address().port}/v1/sites/123/jobs/`;
 t.after(async()=>{await app.close();await fs.rm(root,{recursive:true,force:true});});
 const call=(id,method='GET',suffix='',body,site=123)=>fetch(base.replace('/123/','/'+site+'/')+id+suffix,{method,headers:{Authorization:'Bearer '+siteToken(master,123),'Content-Type':'application/json'},body:body===undefined?undefined:typeof body==='string'||Buffer.isBuffer(body)?body:JSON.stringify(body)});
 return {root,call};
}
async function wait(call,id){for(let n=0;n<600;n++){const j=await (await call(id)).json();if(['succeeded','failed'].includes(j.status))return j;await new Promise(r=>setTimeout(r,100));}throw Error('timeout');}
test('scope, immutable input, checksum, replay and real full build', {timeout:120000},async t=>{
 const {call}=await setup(t);const id=randomUUID(),snapshot={...fixture({count:115,withMedia:true}),site_id:123,account_id:1};
 assert.equal((await call(id,'POST','',snapshot)).status,202);
 assert.equal((await call(id,'GET','',undefined,124)).status,401);
 assert.equal((await call(id,'POST','',{...snapshot,generation:2})).status,409);
 assert.equal((await call(id,'PUT','/media/9000-photo.png',Buffer.from('wrong'))).status,400);
 assert.equal((await call(id,'POST','/start',{})).status,409);
 assert.equal((await call(id,'PUT','/media/9000-photo.png',fixtureImage)).status,200);
 assert.equal((await call(id,'POST','/start',{})).status,202);
 assert.equal((await call(id,'POST','',snapshot)).status,200);
 assert.equal((await wait(call,id)).status,'succeeded');
 const result=await (await call(id,'GET','/result')).json();assert.equal(result.post_count,115);assert.equal(result.quality.passed,true);
 const archive=Buffer.from(await (await call(id,'GET','/archive')).arrayBuffer());assert.equal(hash(archive),result.archive.sha256);
 assert.equal((await call(id,'GET','/files/snapshot.json')).status,404);
 assert.equal((await call(id,'DELETE')).status,200);
 assert.equal((await call(id)).status,404);
});
test('durable queue runs one build at a time and preserves failures',async t=>{
 let active=0,max=0;
 const {call}=await setup(t,async()=>{max=Math.max(max,++active);await new Promise(r=>setTimeout(r,80));active--;throw Error('fixture failure');});
 const a=randomUUID(),b=randomUUID();
 for(const id of [a,b]){assert.equal((await call(id,'POST','',{...fixture({count:0}),site_id:123})).status,202);await call(id,'POST','/start',{});}
 assert.equal((await wait(call,a)).status,'failed');assert.equal((await wait(call,b)).status,'failed');assert.equal(max,1);
});
test('restart marks interrupted builds failed and does not replay them',async t=>{
 const root=await fs.mkdtemp(path.join(os.tmpdir(),'dashless-restart-')),id=randomUUID(),key='123-'+id;
 await fs.mkdir(path.join(root,key));await fs.writeFile(path.join(root,key,'job.json'),JSON.stringify({key,id,site:'123',status:'running',created:Date.now()}));
 const app=await createBuilder({root,master});await new Promise(r=>app.server.listen(0,'127.0.0.1',r));
 t.after(async()=>{await app.close();await fs.rm(root,{recursive:true,force:true});});
 const j=JSON.parse(await fs.readFile(path.join(root,key,'job.json')));assert.equal(j.status,'failed');assert.equal(j.error,'worker_restarted');
});
