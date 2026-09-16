import { App } from '@modelcontextprotocol/ext-apps';
import { describe, phases, reviewUrl, nextDelay } from './state.mjs';
const app = new App({name:'Dashless',version:'0.1.0'}, {});
const $ = id => document.getElementById(id);
let current={}, metadata={}, timer, attempts=0, connected=false, busy=false, generation=0;
function render(result) {
  clearTimeout(timer);
  if(result.isError){metadata={};$('review').disabled=true;$('notice').textContent=result.structuredContent?.message || 'Reconnect or refresh to try again.';return;}
  current=result.structuredContent || {}; metadata=result._meta || {}; generation++;
  const [title,description]=describe(current);$('title').textContent=title;$('description').textContent=description;

  $('phases').replaceChildren(...phases.map(([key,label])=>{
    const li=document.createElement('li');const icon=document.createElement('span');const text=document.createElement('span');
    li.dataset.state=current[key]===true?'done':'pending';icon.textContent=current[key]===true?'✓':'·';icon.setAttribute('aria-hidden','true');
    text.textContent=label+(current[key]===true?' — confirmed':current[key]===false?' — not complete':' — not confirmed');li.append(icon,text);return li;
  }));
  $('review').textContent=current.approval_required && current.release_id ? 'Review earlier version ↗' : 'Review & Publish ↗';
  $('review').disabled=!connected || !(reviewUrl(metadata.review_url) || current.preview_id);
  $('refresh').disabled=!connected || !current.job_id;
  $('notice').textContent='';
  if(['queued','running'].includes(current.status) && attempts<40 && !document.hidden)timer=setTimeout(poll,nextDelay(attempts++));
  else if(attempts>=40)$('notice').textContent='Automatic checks paused. Refresh status to check again.';
}
async function poll(){if(busy || !current.job_id)return;busy=true;const expected=generation;
  try {const result=await app.callServerTool({name:'get_job',arguments:{job_id:current.job_id}});if(expected===generation)render(result);}
  catch{$('notice').textContent='Status is temporarily unavailable. Refresh to try again.';}finally{busy=false;}}
$('refresh').onclick=()=>{attempts=0;void poll();};
$('review').onclick=async()=>{
  $('review').disabled=true;
  try {
    let url=reviewUrl(metadata.review_url);
    if(!url || metadata.review_expires_at*1000<=Date.now()) {
      const result=await app.callServerTool(current.approval_required && current.release_id ? {name:'request_rollback_approval',arguments:{release_id:current.release_id}} : {name:'request_publication_approval',arguments:{preview_id:current.preview_id}});
      if(result.isError)throw new Error();metadata=result._meta || {};url=reviewUrl(metadata.review_url);
    }
    if(!url)throw new Error();
    const response=await app.openLink({url});if(response.isError)throw new Error();
    $('notice').textContent='Finish reviewing and confirm in Dashless. Then refresh status here.';
  }catch{$('notice').textContent='The review link is unavailable. Reopen the preview from your conversation.';}
  finally{$('review').disabled=false;}
};
app.ontoolresult=result=>{attempts=0;render(result);};
app.ontoolcancelled=()=>{clearTimeout(timer);$('notice').textContent='The request was canceled. Ask Dashless to check the latest status.';};
app.onteardown=async()=>{clearTimeout(timer);generation++;return {};};
document.addEventListener('visibilitychange',()=>{if(document.hidden)clearTimeout(timer);else if(connected && ['queued','running'].includes(current.status))void poll();});
render({});
try{await app.connect();connected=true;render({structuredContent:current,_meta:metadata});}
catch{$('notice').textContent='Ask Dashless to show your preview in ChatGPT. You can keep making changes in your conversation.';}
