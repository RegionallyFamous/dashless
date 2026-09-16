import {handleRequest} from '../../server/dashless-mcp.mjs';
import {writeFile} from 'node:fs/promises';
const list=await handleRequest({jsonrpc:'2.0',id:1,method:'tools/list',params:{}});
const names=['inspect_site','list_posts','get_post','create_draft','update_draft','stage_update','list_revisions','stage_revision_restore','list_terms','ensure_terms','list_media','update_media'];
const tools=list.result.tools.filter(t=>names.includes(t.name)).map(t=>({...t,scope:t.annotations.readOnlyHint?'blog:read':'blog:write'}));
const add=(name,description,scope,properties={},required=[],read=false)=>tools.push({name,description,scope,inputSchema:{type:'object',properties,required,additionalProperties:false},annotations:{readOnlyHint:read,destructiveHint:scope==='blog:publish',openWorldHint:scope==='blog:publish'}});
const str={type:'string',minLength:1};const key={type:'string',minLength:8,maxLength:100};
add('get_status','Read the connected blog and included account access.','blog:read',{},[],true);
add('get_job','Read a previously returned job identifier.','blog:read',{job_id:str},['job_id'],true);
add('get_design','Read the current curated design and its version.','blog:read',{},[],true);
const changes={type:'object',additionalProperties:false,properties:{
 palette:{type:'string',enum:['paper','night','lilac']}, typography:{type:'string',enum:['editorial','modern','classic']},layout:{type:'string',enum:['journal','magazine','minimal']},
 site_title:{type:'string',maxLength:120},description:{type:'string',maxLength:300},logo_media_id:{type:'integer',minimum:0},
 navigation:{type:'array',maxItems:8,items:{type:'object',properties:{label:{type:'string',maxLength:40},page_id:{type:'integer',minimum:1}},required:['label','page_id'],additionalProperties:false}}
}};
add('update_design','Stage requested curated design changes; does not publish.','blog:write',{expected_version:{type:'integer',minimum:0},client_key:key,changes},['expected_version','client_key','changes']);
add('create_preview','Queue an exact private preview of the requested content/design. Never publish.','blog:write',{client_key:key,post_type:{type:'string',enum:['post','page']},id:{type:'integer',minimum:1},change_id:str,design_version:{type:'integer',minimum:0}},['client_key']);
add('request_publication_approval','Show a private preview with an authenticated user Publish control. This tool does not grant approval.','blog:write',{preview_id:str},['preview_id']);
add('publish_previewed','Queue publication only after the server has recorded user approval for this exact preview.','blog:publish',{preview_id:str,approval_id:str,client_key:key},['preview_id','approval_id','client_key']);
add('get_release','Read active and previous verified releases.','blog:read',{},[],true);
add('rollback_release','Roll back to an earlier verified release after authenticated user approval.','blog:publish',{release_id:str,approval_id:str,client_key:key},['release_id','approval_id','client_key']);
add('export_site','Prepare a private content and source export for the account owner.','blog:read',{client_key:key},['client_key']);
add('create_media_upload','Create an authenticated upload page for media when a ChatGPT file transfer is unavailable.','blog:write',{filename:{type:'string',maxLength:200},alt_text:{type:'string',maxLength:2000},client_key:key},['filename','alt_text','client_key']);
await writeFile(new URL('../hub/contracts/tools.v1.json',import.meta.url),JSON.stringify(tools,null,2)+'\n');
