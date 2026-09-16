import {chromium} from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {createServer} from 'node:http';
import {readFile,mkdir,writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../',import.meta.url));
const html=await readFile(path.join(root,'dist/workflow.html'));
const parent=`<!doctype html><html lang="en"><title>Local MCP Apps fixture host</title><body style="margin:0"><iframe title="Dashless" src="/widget" style="width:100%;height:1100px;border:0"></iframe><script>
window.calls=[];window.opened=[];
window.send=(data)=>document.querySelector('iframe').contentWindow.postMessage({jsonrpc:'2.0',method:'ui/notifications/tool-result',params:data},location.origin);
addEventListener('message',event=>{if(event.source!==document.querySelector('iframe').contentWindow)return;const m=event.data;if(!m?.jsonrpc)return;let result={};
if(m.method==='ui/initialize')result={protocolVersion:m.params.protocolVersion,hostInfo:{name:'fixture',version:'1'},hostCapabilities:{serverTools:{},openLinks:{}},hostContext:{theme:'light',displayMode:'inline'}};
else if(m.method==='tools/call'){window.calls.push(m.params);result=window.nextResult||{structuredContent:{status:'running',job_id:'11111111-1111-4111-8111-111111111111',saved:true}};}
else if(m.method==='ui/open-link'){window.opened.push(m.params.url);result={};}
if(m.id!==undefined)event.source.postMessage({jsonrpc:'2.0',id:m.id,result},event.origin);
});</script></body></html>`;
const server=createServer((req,res)=>{res.setHeader('Content-Type','text/html');res.end(req.url==='/widget'?html:parent);});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});let checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++;console.log('PASS '+message);};
try{
 const context=await browser.newContext({viewport:{width:780,height:1100}});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(`http://127.0.0.1:${server.address().port}`);const frame=page.frameLocator('iframe');await frame.getByText('Your words. Your call.').waitFor();
 const send=async(data,meta={})=>{await page.evaluate(({data,meta})=>window.send({structuredContent:data,_meta:meta}),{data,meta});};
 await send({job_id:'11111111-1111-4111-8111-111111111111',status:'queued',saved:true});await frame.getByRole('heading',{name:'Your changes are next'}).waitFor();
 await frame.getByRole('button',{name:'Refresh status'}).click();await frame.getByRole('heading',{name:'Preparing your blog'}).waitFor();check((await page.evaluate(()=>window.calls))[0].name==='get_job','refresh uses authenticated status tool');
 await send({status:'succeeded',saved:true,preview_ready:true,preview_id:'22222222-2222-4222-8222-222222222222'},{review_url:'https://dashless.blog/preview/?preview=22222222-2222-4222-8222-222222222222&handoff=fixture',review_expires_at:Math.floor(Date.now()/1000)+900});
 await frame.getByRole('heading',{name:'Ready for your review'}).waitFor();
 await frame.getByRole('button',{name:'Review & Publish'}).click();
 await frame.getByText('Finish reviewing and confirm in Dashless. Then refresh status here.').waitFor();
 check((await page.evaluate(()=>window.opened)).length===1,'Publish control opens browser review');
 check(!(await page.evaluate(()=>window.calls)).some(x=>/publish_previewed|rollback_release|approvals/.test(x.name)),'component never mints human approval');
 const axe=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();check(axe.violations.length===0,'desktop WCAG A/AA automated checks');
 await mkdir(path.join(root,'submission/screenshots'),{recursive:true});
 await frame.locator('main').screenshot({path:path.join(root,'submission/screenshots/preview-desktop.png')});
 await page.setViewportSize({width:390,height:1100});
 check(await frame.locator('body').evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile has no horizontal overflow');
 await frame.locator('main').screenshot({path:path.join(root,'submission/screenshots/preview-mobile.png')});
 const mobile=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();check(mobile.violations.length===0,'mobile WCAG A/AA automated checks');
 await send({status:'failed',saved:true,previous_release_preserved:true});await frame.getByRole('heading',{name:'We couldn’t finish your changes'}).waitFor();
 check(await frame.getByRole('button',{name:'Review & Publish'}).isDisabled(),'failed build cannot open an old approval link');
 await frame.locator('main').screenshot({path:path.join(root,'submission/screenshots/failed-mobile.png')});
 await send({status:'succeeded',saved:true,preview_ready:true,published_in_wordpress:true,deployed:true,publicly_verified:false});check(!await frame.getByRole('heading',{name:'Your blog is live'}).count(),'deploy without public verification is not called live');
 await send({status:'succeeded',saved:true,preview_ready:true,published_in_wordpress:true,deployed:true,publicly_verified:true});await frame.getByRole('heading',{name:'Your blog is live'}).waitFor();
 await frame.locator('main').screenshot({path:path.join(root,'submission/screenshots/verified-mobile.png')});
 check(await frame.locator('input[type=file],textarea,details').count()===0,'component leaves all media input to ChatGPT attachments');
 check(errors.length===0,'component executes without browser errors');
 await writeFile(path.join(root,'submission/screenshots/evidence.json'),JSON.stringify({kind:'local fixture host, not ChatGPT',checks,axeViolations:0,screenshots:'actual packaged component; synthetic state and no customer data'},null,2)+'\n');
 console.log(`${checks} browser checks passed. Local fixture bridge only.`);
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
