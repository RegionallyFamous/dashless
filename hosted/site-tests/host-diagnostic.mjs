// Fixed operator probes only. No customer source, network requests, or credentials.
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath} from 'node:url';
const [mode,work]=process.argv.slice(2),root=path.dirname(fileURLToPath(import.meta.url));
const allowed=['node','node-basic','timer','cpu','astro-import','rolldown-import','transform','transform-js','astro-minimal','astro-setup','astro-traced','astro-plain','astro-cold','shared-empty','astro-cold-sampled','astro-cold-cpu-one','shared-cpu-one','astro-cold-again-cpu-one','astro-cold-control','shared-again-cpu-one'];
if(!allowed.includes(mode)||!/^\/tmp\/dashless-host-diagnostic-[a-f0-9-]{36}\/[a-z-]+$/.test(work))throw new Error('Invalid fixed probe');
const memory=()=>{try{return{rss:process.memoryUsage().rss};}catch(e){return{rss:null,rss_error:e.code};}};
const stage=(name,data={})=>fs.appendFileSync(path.join(work,'trace.jsonl'),JSON.stringify({time:new Date().toISOString(),stage:name,...memory(),usage:process.resourceUsage(),...data})+'\n');
stage('node-start',{version:process.version,pid:process.pid,ppid:process.ppid,parallelism:os.availableParallelism(),cpus:os.cpus().length});
if(mode.endsWith('cpu-one')||mode.endsWith('sampled'))setInterval(()=>stage('usage-sample'),100).unref();
for(const signal of ['SIGTERM','SIGINT','SIGQUIT','SIGABRT','SIGSYS'])process.on(signal,()=>{stage('signal',{signal});process.exit(128+({SIGTERM:15,SIGINT:2,SIGQUIT:3,SIGABRT:6,SIGSYS:31}[signal]));});
process.on('exit',code=>stage('node-exit',{code}));
try{
 if(mode==='node'||mode==='node-basic'){
  const proc={};for(const name of ['/proc/self/status','/proc/self/limits','/sys/fs/cgroup/memory.max']){try{proc[name]=fs.readFileSync(name,'utf8').slice(0,4000);}catch(e){proc[name]={code:e.code};}}stage('environment',{proc});
 }else if(mode==='timer'){
  for(let i=0;i<12;i++){await new Promise(r=>setTimeout(r,1000));stage('timer',{second:i+1});}
 }else if(mode==='cpu'){
  for(let i=0;i<12;i++){const until=Date.now()+500;let n=0;while(Date.now()<until)n++;stage('cpu',{step:i+1});await new Promise(r=>setTimeout(r,0));}
 }else if(mode==='astro-import'){
  stage('before-import');await import('astro');stage('after-import');
 }else if(mode==='rolldown-import'||mode==='transform'||mode==='transform-js'){
  stage('before-import');const rolldown=await import(mode==='transform-js'?'rolldown/utils':'rolldown');stage('after-import');if(mode!=='rolldown-import'){stage('before-transform');const r=await rolldown.transform('input.js','export const answer = 42',{lang:'js'});stage('after-transform',{bytes:r.code.length});}
 }else if(['shared-empty','shared-cpu-one','shared-again-cpu-one'].includes(mode)){
  const snapshot={frontend_contract:1,generation:1,site_url:'https://diagnostic.example',settings:{homePageId:0,postsPageId:0,language:'en'},design:{version:0,palette:'paper',typography:'editorial',layout:'journal',site_title:'Private hosting check',description:'Private empty build check.',logo_media_id:0,navigation:[]},items:[],media:[],assets:{},terms:{category:[],post_tag:[]},target:null};
  await fsp.writeFile(path.join(work,'snapshot.json'),JSON.stringify(snapshot));process.argv[2]=work;stage('before-shared-build');await import('./build.mjs');stage('after-shared-build',{result:JSON.parse(await fsp.readFile(path.join(work,'build-result.json'),'utf8'))});
 }else if(['astro-minimal','astro-setup','astro-traced','astro-plain'].includes(mode)||mode.startsWith('astro-cold')){
  const source=path.join(work,'source');await fsp.mkdir(path.join(source,'src/pages'),{recursive:true});await fsp.writeFile(path.join(source,'package.json'),'{"type":"module"}');await fsp.writeFile(path.join(source,'src/pages/index.astro'),'<!doctype html><html lang="en"><head><title>Private hosting check</title></head><body><h1>Private hosting check</h1></body></html>');await fsp.symlink(path.join(root,'node_modules'),path.join(source,'node_modules'),'dir');
  process.chdir(source);stage('before-import');const {build}=await import('astro');stage('after-import');
  if(mode==='astro-setup'){
   stage('before-resolve-config');const {resolveConfig}=await import('./node_modules/astro/dist/core/config/config.js');const {userConfig,astroConfig}=await resolveConfig({root:source},'build');stage('after-resolve-config');
   const {loadOrCreateNodeLogger}=await import('./node_modules/astro/dist/core/logger/load.js');await loadOrCreateNodeLogger(astroConfig,{});stage('after-logger');
   const {eventCliSession}=await import('./node_modules/astro/dist/events/session.js');const event=eventCliSession('build',userConfig);stage('after-session-event');
   const {telemetry}=await import('./node_modules/astro/dist/events/index.js');await telemetry.record(event);stage('after-telemetry');
   const {createSettings}=await import('./node_modules/astro/dist/core/config/settings.js');await createSettings(astroConfig,undefined,source);stage('after-settings');
  }else{
   const hooks=Object.fromEntries(['astro:config:setup','astro:config:done','astro:build:start','astro:build:done'].map(name=>[name,()=>stage(name)]));
   const config=mode==='astro-plain'?{root:source}:{root:source,logLevel:'debug',integrations:[{name:'fixed-diagnostic',hooks}]};
   if(mode.startsWith('astro-cold'))Object.assign(config,{cacheDir:path.join(work,'astro-cache'),vite:{cacheDir:path.join(work,'vite-cache')}});
   stage('before-build');await build(config);stage('after-build',{bytes:(await fsp.stat(path.join(source,'dist/index.html'))).size});
  }
 }
 stage('done');
}catch(e){stage('exception',{name:e.name,message:e.message,stack:e.stack});process.exitCode=1;}
