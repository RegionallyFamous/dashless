// Read-only live transport and metadata checks. Not an end-to-end ChatGPT certification.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const origin=process.argv[2]||'https://dashless.blog';
if(!origin.startsWith('https://'))throw Error('HTTPS origin required');
const catalog=JSON.parse(await readFile(new URL('../../../templates/astro/src/lib/themes.json',import.meta.url),'utf8'));
const themeIds=catalog.map(theme=>theme.id);
const headers={'Content-Type':'application/json',Accept:'application/json, text/event-stream'};
async function rpc(method,params={}) {const response=await fetch(origin+'/mcp',{method:'POST',headers,body:JSON.stringify({jsonrpc:'2.0',id:1,method,params})});return {response,data:await response.json()};}
const probe=await fetch(origin+'/mcp');assert.equal(probe.status,401);assert.ok(probe.headers.get('www-authenticate')?.includes('oauth-protected-resource'));
const emptyProbe=await fetch(origin+'/mcp',{method:'POST',headers:{'Content-Type':'application/octet-stream'},body:''});assert.equal(emptyProbe.status,401);assert.ok(emptyProbe.headers.get('www-authenticate')?.includes('oauth-protected-resource'));
const init=await rpc('initialize',{protocolVersion:'2025-11-25',capabilities:{},clientInfo:{name:'Dashless release verification',version:'1'}});
assert.equal(init.response.status,200);assert.match(init.data.result.instructions,/list_themes/);assert.match(init.data.result.instructions,/theme_version/);
const {data}=await rpc('tools/list'),tools=data.result.tools;assert.equal(tools.length,28);
for(const tool of tools) {for(const name of ['readOnlyHint','openWorldHint','destructiveHint'])assert.equal(typeof tool.annotations[name],'boolean');assert.equal(tool.inputSchema.additionalProperties,false);assert.ok(tool.securitySchemes?.length);}
for(const name of ['list_themes','get_theme'])assert.equal(tools.find(t=>t.name===name)?.annotations.readOnlyHint,true);
const schema=tools.find(t=>t.name==='update_design').inputSchema.properties.changes.properties;assert.ok(schema.theme_id&&schema.theme_version);
const denied=await rpc('tools/call',{name:'list_themes',arguments:{}});assert.equal(denied.response.status,401);assert.ok(denied.response.headers.get('www-authenticate'));
const resource=await rpc('resources/read',{uri:'ui://dashless/workflow-v1.html'});assert.ok(resource.data.result.contents[0].text.length>1000);assert.ok(resource.data.result.contents[0]._meta.ui.csp);
const oauth=await(await fetch(origin+'/.well-known/oauth-authorization-server')).json();assert.ok(oauth.code_challenge_methods_supported.includes('S256'));
assert.equal(oauth.userinfo_endpoint,oauth.issuer+'userinfo');
assert.equal(oauth.jwks_uri,oauth.issuer+'.well-known/jwks.json');
for(const scope of ['openid','profile','email','blog:read','blog:write','blog:publish'])assert.ok(oauth.scopes_supported.includes(scope),`authorization metadata missing ${scope}`);
const liveReleaseIds=new Set();
const themesPage=await fetch(origin+'/themes/');const html=await themesPage.text();const themesRelease=themesPage.headers.get('x-dashless-release');assert.equal(themesPage.status,200,'/themes/ must respond 200');assert.ok(themesRelease,'/themes/ is missing x-dashless-release');liveReleaseIds.add(themesRelease);
const images=[];for(const id of themeIds) {const match=html.match(new RegExp(`(?:src|href)="([^"]*theme-preview-${id}[^\"]*\\.png)"`));assert.ok(match,`themes page is missing preview asset: ${id}`);const url=new URL(match[1],origin).toString();const r=await fetch(url);assert.equal(r.status,200);assert.match(r.headers.get('content-type'),/image\/png/);const release=r.headers.get('x-dashless-release');assert.ok(release,`${id} preview is missing x-dashless-release`);images.push({id,url,accessible:true,release});}
const pages={};for(const route of ['/','/support/','/privacy/','/terms/']){const r=await fetch(origin+route),html=await r.text();const retiredProvider=/WordPress\.com/i.test(html);const hasContactLink=/mailto:|cdn-cgi\/l\/email-protection|__cf_email__/i.test(html);const release=r.headers.get('x-dashless-release');assert.equal(r.status,200,`${route} must respond 200`);assert.equal(retiredProvider,false,`${route} contains retired provider copy`);assert.equal(hasContactLink,true,`${route} is missing a support contact link`);assert.ok(release,`${route} is missing x-dashless-release`);liveReleaseIds.add(release);pages[route]={status:r.status,contains_release_draft:/release draft/i.test(html),has_contact_link:hasContactLink,contains_retired_provider:retiredProvider,release};}
assert.equal(liveReleaseIds.size,1,`public pages and previews are split across releases: ${[...liveReleaseIds].join(', ')}`);
const challenge=await fetch(origin+'/.well-known/openai-apps-challenge');
console.log(JSON.stringify({checked_at:new Date().toISOString(),origin,public_transport_passed:true,tool_count:tools.length,theme_discovery:true,release_id:[...liveReleaseIds][0],theme_images:images,oauth:{pkce_s256:true,scopes:oauth.scopes_supported,userinfo_advertised:!!oauth.userinfo_endpoint},pages,domain_challenge_status:challenge.status,limitations:['No OAuth login or actual ChatGPT conversation is exercised by this read-only check.','A domain challenge HTTP response does not prove portal ownership verification.']},null,2));
