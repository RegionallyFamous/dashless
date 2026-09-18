// Read-only Auth0 discovery and negative-authentication check. No sign-in or token issuance.
import assert from 'node:assert/strict';
const origin=process.argv[2]||'https://dashless.blog';
assert.equal(new URL(origin).protocol,'https:');
assert.equal(new URL(origin).origin,origin,'Supply only the Hub HTTPS origin.');
const checks=[];
async function json(url){const r=await fetch(url,{signal:AbortSignal.timeout(15000)});assert.equal(r.status,200);return r.json();}
const resource=await json(origin+'/.well-known/oauth-protected-resource/mcp');
assert.equal(resource.resource,origin+'/mcp');assert.equal(resource.authorization_servers.length,1);
const issuer=resource.authorization_servers[0];assert.equal(new URL(issuer).protocol,'https:');assert.equal(issuer,new URL(issuer).origin+'/');assert.notEqual(issuer,origin+'/');
const provider=await json(issuer+'.well-known/openid-configuration');
assert.equal(provider.issuer,issuer);assert.equal(provider.token_endpoint,issuer+'oauth/token');assert.equal(provider.authorization_endpoint,issuer+'authorize');assert.ok(provider.code_challenge_methods_supported.includes('S256'));
checks.push('protected resource identifies Auth0 and the exact blog audience','Auth0 discovery advertises canonical endpoints and S256');
const hub=await json(origin+'/.well-known/oauth-authorization-server');
for(const key of ['issuer','authorization_endpoint','token_endpoint','jwks_uri','userinfo_endpoint'])assert.equal(hub[key],provider[key],key+' agrees with provider');
checks.push('public Hub discovery matches the actual provider');
const rejected=await fetch(origin+'/mcp',{method:'POST',headers:{Authorization:'Bearer deliberately-invalid','Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({jsonrpc:'2.0',id:1,method:'tools/call',params:{name:'get_status',arguments:{}}}),signal:AbortSignal.timeout(15000)});
assert.equal(rejected.status,401);assert.ok(rejected.headers.get('www-authenticate')?.includes('oauth-protected-resource'));
checks.push('invalid access token is rejected with reconnect instructions');
for(const path of ['/oauth/authorize','/oauth/token','/oauth/revoke','/auth/wordpress/start','/auth/wordpress/callback']){const r=await fetch(origin+path,{redirect:'manual',signal:AbortSignal.timeout(15000)});assert.equal(r.status,410,path+' retired');}
checks.push('old identity and token-issuing endpoints are retired');
console.log(JSON.stringify({checked_at:new Date().toISOString(),origin,issuer,checks,limitations:['No email delivery, account login, consent, refresh, disconnect, migration, or actual ChatGPT journey is exercised.']},null,2));
