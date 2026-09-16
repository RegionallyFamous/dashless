import fs from 'node:fs/promises';
import { randomUUID, createHash } from 'node:crypto';
import { siteToken } from './server.mjs';
import { fixture, fixtureImage } from '../../tests/fixtures/publication/site.mjs';
const origin=process.argv[2], master=(await fs.readFile(process.argv[3],'utf8')).trim();
if(!origin?.startsWith('https://'))throw Error('HTTPS builder required');
const site=4242002,id=randomUUID(),url=`${origin}/v1/sites/${site}/jobs/${id}`;
async function call(method,suffix='',body){const r=await fetch(url+suffix,{method,headers:{Authorization:'Bearer '+siteToken(master,site),'Content-Type':'application/json'},body:body===undefined?undefined:Buffer.isBuffer(body)?body:JSON.stringify(body)});if(!r.ok)throw Error(`Builder HTTP ${r.status}`);return r;}
const snapshot={...fixture({count:115,home:true,withMedia:true}),site_id:site,account_id:42};const start=Date.now();
await call('POST','',snapshot);await call('PUT','/media/9000-photo.png',fixtureImage);await call('POST','/start',{});
let state;
for(let i=0;i<120;i++){state=await(await call('GET')).json();if(['succeeded','failed'].includes(state.status))break;await new Promise(r=>setTimeout(r,2000));}
if(state.status!=='succeeded')throw Error(`Build ${state.status}: ${state.error}`);
const result=await(await call('GET','/result')).json();const archive=Buffer.from(await(await call('GET','/archive')).arrayBuffer());
if(result.post_count!==115||result.quality.passed!==true||createHash('sha256').update(archive).digest('hex')!==result.archive.sha256)throw Error('Output verification failed');
console.log(JSON.stringify({passed:true,provider:'Railway',job_id:id,duration_ms:Date.now()-start,posts:result.post_count,pages:result.page_count,media:result.media_count,html_pages:result.html_pages,files:result.files.length,archive_bytes:archive.length,archive_sha256:result.archive.sha256,quality:result.quality},null,2));
await call('DELETE');
