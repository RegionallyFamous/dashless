import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const runtime=path.dirname(fileURLToPath(import.meta.url));
const [workspace,parentText,deadlineText]=process.argv.slice(2);
const parent=Number(parentText), deadline=Math.min(Number(deadlineText),Date.now()+235000);
const launch=process.env.DASHLESS_NODE_LAUNCHER?JSON.parse(process.env.DASHLESS_NODE_LAUNCHER):[process.execPath];
let peak=0,reason=null,stopping=false,lastWrite=0,measured=false;
const child=spawn(launch[0],[...launch.slice(1),path.join(runtime,'build.mjs'),workspace],{cwd:workspace,detached:true,stdio:'inherit',env:process.env});
function stop(why){if(stopping)return;stopping=true;reason=why;if(child.pid)try{process.kill(-child.pid,'SIGTERM');}catch{}setTimeout(()=>{if(child.pid)try{process.kill(-child.pid,'SIGKILL');}catch{}},750);}
process.on('SIGTERM',()=>stop('terminated'));process.on('SIGINT',()=>stop('terminated'));
function sample(){
  try{process.kill(parent,0);}catch{stop('parent_lost');}
  if(Date.now()>deadline)stop('build_deadline');
  if(process.platform!=='linux')return;
  const rows=[];
  for(const id of fs.readdirSync('/proc').filter(n=>/^\d+$/.test(n))){try{const s=fs.readFileSync(`/proc/${id}/stat`,'utf8');const fields=s.slice(s.lastIndexOf(')')+2).split(' ');const status=fs.readFileSync(`/proc/${id}/status`,'utf8');rows.push({pid:Number(id),ppid:Number(fields[1]),rss:Number(status.match(/^VmRSS:\s+(\d+)/m)?.[1]||0)*1024});}catch{}}
  const ids=new Set([parent,process.pid]);let changed=true;while(changed){changed=false;for(const r of rows)if(ids.has(r.ppid)&&!ids.has(r.pid)){ids.add(r.pid);changed=true;}}
  const rss=rows.filter(r=>ids.has(r.pid)).reduce((s,r)=>s+r.rss,0);peak=Math.max(peak,rss);if(rss>1000*1024*1024)stop('build_memory_limit');
  measured=measured||(rows.some(r=>r.pid===parent&&r.rss>0)&&rows.some(r=>r.pid===process.pid&&r.rss>0)&&rows.some(r=>r.pid===child.pid&&r.rss>0));
  if(Date.now()-lastWrite>500){lastWrite=Date.now();fs.writeFileSync(path.join(workspace,'process-metrics.json'),JSON.stringify({peak_memory_bytes:peak,process_count:ids.size,reason}));}
}
const timer=setInterval(sample,50);sample();
child.on('error',()=>stop('build_launch_failed'));
child.on('close',async(code,signal)=>{clearInterval(timer);sample();if(reason)await new Promise(r=>setTimeout(r,800));fs.writeFileSync(path.join(workspace,'process-result.json'),JSON.stringify({exit_code:code,signal,reason,peak_memory_bytes:measured?peak:0,memory_method:measured?'proc_tree_rss_sample_50ms':'unavailable',deadline_seconds:235}));process.exit(reason||signal?1:(code||0));});
