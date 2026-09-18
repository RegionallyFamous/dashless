// Verify all catalog editions against a deployed builder using isolated sample data.
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {randomUUID,createHash} from 'node:crypto';
import {siteToken} from './server.mjs';
import {fixture} from '../../tests/fixtures/publication/site.mjs';
import {themes} from '../../templates/astro/src/lib/design.mjs';
const origin=process.argv[2],master=(await fs.readFile(process.argv[3],'utf8')).trim();
if(!origin?.startsWith('https://'))throw Error('HTTPS builder required');
const site=4242003,work=await fs.mkdtemp(path.join(os.tmpdir(),'dashless-theme-smoke-')),results=[];
try {
 for(const theme of themes) {
  const id=randomUUID(),url=`${origin}/v1/sites/${site}/jobs/${id}`,started=Date.now();
  async function call(method,suffix='',body) {
   const response=await fetch(url+suffix,{method,headers:{Authorization:'Bearer '+siteToken(master,site),'Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)});
   if(!response.ok)throw Error(`Builder HTTP ${response.status}`);return response;
  }
  const snapshot={...fixture({count:3,...theme.defaults}),site_id:site,account_id:42};
  Object.assign(snapshot.design,{theme_id:theme.id,theme_version:theme.version});
  await call('POST','',snapshot);
  try {
   await call('POST','/start',{});let state;
   for(let i=0;i<120;i++){state=await(await call('GET')).json();if(['succeeded','failed'].includes(state.status))break;await new Promise(r=>setTimeout(r,2000));}
   assert.equal(state.status,'succeeded',JSON.stringify(state.error));
   const result=await(await call('GET','/result')).json(),archive=Buffer.from(await(await call('GET','/archive')).arrayBuffer());
   assert.equal(createHash('sha256').update(archive).digest('hex'),result.archive.sha256);assert.equal(result.quality.passed,true);
   const zip=path.join(work,theme.id+'.zip');await fs.writeFile(zip,archive);
   const html=execFileSync('unzip',['-p',zip,'index.html'],{encoding:'utf8'});
   assert.ok(html.includes(`data-design="${theme.id}"`));
   assert.ok(html.includes(`data-palette="${theme.defaults.palette}"`));
   const entries=execFileSync('unzip',['-Z1',zip],{encoding:'utf8'}).split('\n').filter(name=>name.endsWith('.css'));
   const css=entries.map(name=>execFileSync('unzip',['-p',zip,name],{encoding:'utf8'})).join('\n');
   assert.ok(css.includes('grid-template-columns:minmax(0,1fr) 205px'),'Hero must reserve space for its decoration');
   assert.ok(css.includes('.story-card.featured:not(:has(.story-image))'),'Imageless lead must span its card');

   results.push({theme:theme.id,version:theme.version,passed:true,duration_ms:Date.now()-started,html_pages:result.html_pages,archive_sha256:result.archive.sha256});
  } finally {await call('DELETE');}
 }
 console.log(JSON.stringify({origin,passed:true,themes:results},null,2));
} finally {await fs.rm(work,{recursive:true,force:true});}
