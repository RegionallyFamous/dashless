<?php
/** Disposable WordPress + real JWT verification with an Auth0 fixture + synthetic site runtime; never production acceptance. */
require dirname(__DIR__,2).'/tests/integration.php';
use Dashless\Hub\{App,Agent,Chatgpt,ChatgptUploads,Config,Crypto,Failure,Jobs,Mcp,OAuth,Previews,Routes};
$baseline=$passed;
// Existing suite's network deny hook stays in place, with a narrow deterministic file fixture override.
$image=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jM1sAAAAASUVORK5CYII=');
class ChatgptSiteFixture extends Agent {
 public array $sites=[],$calls=[]; public bool $uploadCap=true;
 public function call(array $a,string $method,string $route,array $data=[]):array {
  $owner=$a['user_id'];$this->calls[]=[$owner,$method,$route];
  $this->sites[$owner]??=['generation'=>1,'design'=>1,'assets'=>1,'jobs'=>[],'previews'=>[],'approvals'=>[],'uploads'=>[],'published'=>[]];$s=&$this->sites[$owner];$args=$data['arguments']??[];
  $base=['contract_version'=>1,'site_id'=>$a['site_id']];
  if($route==='/capabilities')return $base+['capabilities'=>['jobs'=>true,'private_previews'=>true,'approvals'=>true,'exports'=>true,'design'=>true,'runtime'=>true,'chatgpt_upload_v1'=>$this->uploadCap,'rollback_approval_v1'=>true]];
  if(str_starts_with($route,'/jobs/')){return $s['jobs'][basename($route)]??throw new Failure('job_missing','Job not found.',404);}
  if($route==='/tools/create_draft')return $base+['id'=>1,'title'=>$args['title'],'saved'=>true];
  if($route==='/tools/create_media_upload'){$id=wp_generate_uuid4();$s['uploads'][$id]=['used'=>false,'key'=>$args['client_key']];return $base+['upload_id'=>$id,'expires_at'=>gmdate('c',time()+600),'max_bytes'=>20*1024*1024,'transfer'=>'hub_binary_relay'];}
  if(preg_match('~/uploads/([^/]+)/complete~',$route,$m)){
   if(!isset($s['uploads'][$m[1]]))throw new Failure('upload_missing','Upload not found.',404);
   $u=&$s['uploads'][$m[1]];if($u['used'])return $base+['media_id'=>7];
   if(hash('sha256',base64_decode($data['transfer']['data']))!==$data['transfer']['sha256'])throw new Failure('hash_invalid','Invalid file.');
   $u['used']=true;$s['assets']++;return $base+['media_id'=>7];
  }
  if($route==='/tools/create_preview'){
   $id=wp_generate_uuid4();$job=wp_generate_uuid4();$s['previews'][$id]=[$s['generation'],$s['design'],$s['assets'],'candidate-'.$id];
   $s['jobs'][$job]=$base+['job_id'=>$job,'status'=>'succeeded','preview_id'=>$id,'saved'=>true,'preview_ready'=>true];return $base+['job_id'=>$job,'status'=>'queued'];
  }
  if($route==='/tools/request_publication_approval'){
   if(!isset($s['previews'][$args['preview_id']]))throw new Failure('preview_missing','Preview not found.',404);
   return $base+['preview_id'=>$args['preview_id'],'preview_ready'=>true,'_meta'=>['secret'=>'upstream-must-not-pass'],'preview_url'=>'private-must-not-pass'];
  }
  if(in_array($route,['/approvals','/approvals/rollback'],true)){
   if(($data['actor']['source']??'')!=='authenticated_browser' || ($data['actor']['account_id']??0)!==$owner)throw new Failure('not_human','Browser approval required.',403);
   $target=$data['preview_id']??$data['release_id'];$snapshot=$s['previews'][$target]??null;
   if(($data['kind']??'')!=='rollback' && (!$snapshot || array_slice($snapshot,0,3)!==[$s['generation'],$s['design'],$s['assets']]))throw new Failure('stale_preview','Create a new preview.',409);
   $approval=wp_generate_uuid4();$s['approvals'][$approval]=['target'=>$target,'snapshot'=>$snapshot,'used'=>false];return $base+['approval_id'=>$approval];
  }
  if(in_array($route,['/tools/publish_previewed','/tools/rollback_release'],true)){
   $id=$args['approval_id'];$target=$args['preview_id']??$args['release_id'];$approval=$s['approvals'][$id]??null;
   if(!$approval || $approval['used'] || $approval['target']!==$target)throw new Failure('approval_invalid','Review and approve this exact version.',403);
   if($route==='/tools/publish_previewed' && array_slice($approval['snapshot'],0,3)!==[$s['generation'],$s['design'],$s['assets']])throw new Failure('stale_approval','The preview changed.',409);
   $s['approvals'][$id]['used']=true;$job=wp_generate_uuid4();$s['jobs'][$job]=$base+['job_id'=>$job,'status'=>'succeeded','release_id'=>'release-'.$target,'saved'=>true,'preview_ready'=>true,'published_in_wordpress'=>true,'deployed'=>true,'publicly_verified'=>true];return $base+['job_id'=>$job,'status'=>'queued'];
  }
  if($route==='/tools/get_release')return $base+['active'=>['release_id'=>'release-current'],'previous'=>['release_id'=>'release-old','verified'=>true,'manifest_hash'=>str_repeat('a',64)]];
  if($route==='/tools/export_site'){$job=wp_generate_uuid4();$s['jobs'][$job]=$base+['job_id'=>$job,'status'=>'succeeded','export_ready'=>true];return $base+['job_id'=>$job,'status'=>'queued'];}
  throw new Failure('fixture_unsupported','Unsupported fixture operation.');
 }
}
class ChatgptCloudFixture extends TestCloud { public function taskStatus(string $id):array{return ['complete'=>true];} }
$site=new ChatgptSiteFixture();$cloud=new ChatgptCloudFixture();$jobs=new Jobs($store,$identity,$cloud,$site);
$app=new App();$app->store=$store;$app->identity=$identity;$app->agent=$site;$app->jobs=$jobs;
$mcp=new Mcp($store,$identity,$site,$jobs);$previews=new Previews($app);$routes=new Routes($app);
$authByOwner=[];
$invoke=function($owner,$name,$args=[],$scopes=null)use($mcp,$oauth,&$authByOwner){$auth=$oauth->authenticate('Bearer '.$authByOwner[$owner]);if($scopes!==null)$auth['scopes']=$scopes;return $mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],$auth);};
$accounts=[];
foreach(['chatgpt-a','chatgpt-b'] as $n){
 $user=get_user_by('login',$n);$id=$user?$user->ID:wp_insert_user(['user_login'=>$n,'user_pass'=>wp_generate_password(40),'user_email'=>$n.'@example.test','role'=>'subscriber']);
 Auth0Fixture::bind((int)$id,'auth0|fixture-'.$id);$a=['user_id'=>(int)$id,'state'=>'ready','entitlement'=>'active','site_id'=>9000+(int)$id,'slug'=>$n,'domain'=>$n.'.dashless.blog','site_secret'=>Crypto::seal('fixture-site-secret')];$identity->save($a);$accounts[]=$a;
 $authByOwner[$id]=Auth0Fixture::access((int)$id);check($oauth->authenticate('Bearer '.$authByOwner[$id])['owner']===(int)$id,'subscriber connects through RSA-verified Auth0 fixture token');
}
[$aa,$bb]=$accounts;
foreach(Chatgpt::PROTOCOLS as $version){$r=$mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['protocolVersion'=>$version]],[]);check($r['result']['protocolVersion']===$version,'protocol negotiation '.$version);}
check($mcp->handle(['jsonrpc'=>'2.0','method'=>'notifications/initialized'],[])===null,'initialized notification accepted without response');
check($mcp->handle(['jsonrpc'=>'2.0','method'=>'tools/list'],[])['error']['code']===-32600,'request without ID rejected');
$r=$mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'resources/read','params'=>['uri'=>'file:///etc/passwd']],[]);check($r['error']['code']===-32002,'resource path traversal rejected');
$r=$mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'resources/read','params'=>['uri'=>Chatgpt::URI]],[]);check(!empty($r['result']['contents'][0]['text']) && $r['result']['contents'][0]['text']===file_get_contents(Chatgpt::root().'/dist/workflow.html'),'packaged component served as resource');
$r=$mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],[]);check(count($r['result']['tools'])===28,'25 site tools plus three additive Hub tools');
foreach($r['result']['tools'] as $tool)check(isset($tool['securitySchemes'],$tool['outputSchema'],$tool['_meta']['ui']['visibility']),'descriptor auth/schema/visibility '.$tool['name']);
$themes=$invoke($aa['user_id'],'list_themes',[]);check(array_column($themes['result']['structuredContent']['themes'],'id')===array_column(\Dashless\Hub\Themes::catalog(),'id'),'theme catalog is discoverable through authenticated MCP');
$theme=$invoke($aa['user_id'],'get_theme',['theme_id'=>'field-notes']);check($theme['result']['structuredContent']['id']==='field-notes','named theme metadata is returned');
$r=$invoke($aa['user_id'],'create_draft',[],['blog:read']);check($r['result']['isError'] && isset($r['result']['_meta']['mcp/www_authenticate']),'insufficient scope prompts reauthorization');
$r=$invoke($aa['user_id'],'get_status',['account_id'=>$bb['user_id']]);check(isset($r['error']),'model cannot choose account');
$r=$invoke($aa['user_id'],'publish_previewed',['preview_id'=>wp_generate_uuid4(),'approval_id'=>'forged','client_key'=>'forged-key']);check($r['result']['isError'],'arbitrary approval ID cannot publish');
$r=$invoke($aa['user_id'],'publish_previewed',['approved'=>true]);check(isset($r['error']),'approved true is not an approval');
check(Chatgpt::modelData(['_meta'=>['secret'=>'x'],'nested'=>['preview_url'=>'x','token'=>'x','okay'=>true],'site_id'=>999])===['nested'=>['okay'=>true]],'nested model-visible projection strips credentials and tenant identifiers');
rejects(fn()=>Chatgpt::modelData(['message'=>'https://x.test/?token=secret']),'credentials embedded in strings fail closed');
putenv('DASHLESS_CHATGPT_FILE_ORIGINS=https://files.oaiusercontent.com');
foreach(['file:///etc/passwd','http://files.oaiusercontent.com/a','https://127.0.0.1/a','https://files.oaiusercontent.com.evil.test/a','https://u:p@files.oaiusercontent.com/a','https://files.oaiusercontent.com:443/a'] as $url)rejects(fn()=>ChatgptUploads::validateUrl($url),'unsafe transfer URL rejected');
rejects(fn()=>ChatgptUploads::inspect('<svg><script/></svg>'),'SVG/executable upload rejected');
rejects(fn()=>ChatgptUploads::inspect(str_repeat('x',ChatgptUploads::MAX_BYTES+1)),'oversized upload rejected');
rejects(fn()=>ChatgptUploads::inspect($image,'image/jpeg'),'mismatched declared MIME rejected');
// Replace the previous suite's deny-only filter; all nonfixture network calls still fail.
remove_all_filters('pre_http_request');Auth0Fixture::hook();
add_filter('pre_http_request',function($pre,$args,$url)use($image,$accounts,$site){
 if($pre!==false)return $pre;
 foreach($accounts as $a)if(str_starts_with($url,'https://'.$a['domain'].'/wp-json/dashless-hosted/v1/uploads/')){
   check($args['method']==='PUT' && $args['redirection']===0 && $args['headers']['X-Dashless-Account-Id']===(string)$a['user_id'],'binary relay derives site and account from authenticated identity');
   $route=str_replace('https://'.$a['domain'].'/wp-json/dashless-hosted/v1','',$url);$route=str_replace('/content','/complete',$route);
   $result=$site->call($a,'POST',$route,['transfer'=>['data'=>base64_encode($args['body']),'sha256'=>hash('sha256',$args['body'])]]);
   return ['headers'=>[],'body'=>wp_json_encode($result),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
 }

 if($url!=='https://files.oaiusercontent.com/fixture.png')throw new RuntimeException('Unexpected network request.');
 check($args['redirection']===0 && !isset($args['headers']['Authorization']),'file download refuses redirects and forwards no credentials');
 return ['headers'=>['content-type'=>'image/png'],'body'=>$image,'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
},10,3);
$ids=[];
foreach($accounts as $a){
 $owner=$a['user_id'];
 $r=$invoke($owner,'create_draft',['post_type'=>'post','client_key'=>'draft-key-'.$owner,'title'=>'Private fixture','content'=>'<p>Only this account.</p>']);check($r['result']['structuredContent']['saved'],'isolated account draft saved');
 $upload=['file'=>['download_url'=>'https://files.oaiusercontent.com/fixture.png','file_id'=>'file-fixture-'.$owner,'mime_type'=>'image/png','file_name'=>'fixture.png'],'alt_text'=>'A fixture pixel','client_key'=>'image-key-'.$owner];
 $r=$invoke($owner,'import_chatgpt_file',$upload);check(($r['result']['structuredContent']['media_id']??0)===7,'supported ChatGPT file envelope imports into owner session');
 $before=count($site->calls);$r=$invoke($owner,'import_chatgpt_file',$upload);check(!$r['result']['isError'] && count($site->calls)===$before+1,'upload retry reuses confirmed result without completing twice');
 $changed=$upload;$changed['file']['file_id']='file-other';check($invoke($owner,'import_chatgpt_file',$changed)['result']['isError'],'changed upload key binding rejected');
 $r=$invoke($owner,'create_preview',['client_key'=>'preview-key-'.$owner]);$job=$r['result']['structuredContent']['job_id'];
 $r=$invoke($owner,'show_workflow',['job_id'=>$job]);$preview=$r['result']['structuredContent']['preview_id'];$ids[$owner]=$preview;
 parse_str(parse_url($r['result']['_meta']['review_url'],PHP_URL_QUERY),$query);
 check(!str_contains(json_encode($r['result']['structuredContent']),$query['handoff']) && !str_contains(json_encode($r['result']['content']),$query['handoff']),'review ticket absent from everything visible to model');
 rejects(fn()=>Chatgpt::verifyHandoff($store,$owner===$aa['user_id']?$bb:$aa,'publish',$preview,$query['handoff']),'browser account switching cannot change ticket tenant');
 $r=$invoke($owner,'request_publication_approval',['preview_id'=>$preview]);check(!str_contains(json_encode($r),'upstream-must-not-pass') && !str_contains(json_encode($r),'private-must-not-pass'),'untrusted upstream widget secrets are discarded');
 $queued=$previews->approve($owner,$preview,$query['handoff']);check($queued['status']==='queued','authenticated browser workflow queues exact approved preview');
 $done=$invoke($owner,'get_job',['job_id'=>$job]);check($done['result']['structuredContent']['publicly_verified']===true,'preview status follows browser publication job through verification');
 $approval=array_key_last($site->sites[$owner]['approvals']);
 $replay=$invoke($owner,'publish_previewed',['preview_id'=>$preview,'approval_id'=>$approval,'client_key'=>'different-replay-key']);check($replay['result']['isError'],'consumed approval cannot publish again under another key');
 $rollback=$invoke($owner,'request_rollback_approval',['release_id'=>'release-old']);parse_str(parse_url($rollback['result']['_meta']['review_url'],PHP_URL_QUERY),$q);
 $restored=$previews->rollback($owner,'release-old',$q['handoff']);check($invoke($owner,'get_job',['job_id'=>$restored['job_id']])['result']['structuredContent']['publicly_verified'],'exact rollback browser approval completes fixture journey');
 $export=$invoke($owner,'export_site',['client_key'=>'export-key-'.$owner]);check($invoke($owner,'get_job',['job_id'=>$export['result']['structuredContent']['job_id']])['result']['structuredContent']['export_ready'],'isolated export completes fixture journey');
}
check($invoke($bb['user_id'],'request_publication_approval',['preview_id'=>$ids[$aa['user_id']]])['result']['isError'],'cross-account preview inaccessible');
foreach(['generation','design','assets'] as $dimension){
 $owner=$aa['user_id'];$r=$invoke($owner,'create_preview',['client_key'=>'stale-'.$dimension]);$ready=$invoke($owner,'show_workflow',['job_id'=>$r['result']['structuredContent']['job_id']]);$preview=$ready['result']['structuredContent']['preview_id'];
 $site->sites[$owner][$dimension]++;rejects(fn()=>$previews->approve($owner,$preview),'changed '.$dimension.' invalidates immutable approval');
}
$meta=Chatgpt::handoff($store,$aa,'publish',wp_generate_uuid4());parse_str(parse_url($meta['review_url'],PHP_URL_QUERY),$q);
$changedSite=$aa;$changedSite['site_id']++;rejects(fn()=>Chatgpt::verifyHandoff($store,$changedSite,'publish',$q['preview'],$q['handoff']),'same owner cannot refresh a handoff onto a different site');
$record=$store->get('consent','chatgpt:'.hash('sha256',$q['handoff']));$store->put('consent','chatgpt:'.hash('sha256',$q['handoff']),$record['data'],$record['owner'],'unused',time()-1);
rejects(fn()=>Chatgpt::verifyHandoff($store,$aa,'publish',$q['preview'],$q['handoff']),'expired review handoff rejected');
$meta=Chatgpt::handoff($store,$aa,'publish',wp_generate_uuid4());parse_str(parse_url($meta['review_url'],PHP_URL_QUERY),$q);$oauth->disconnect($aa['user_id']);rejects(fn()=>Chatgpt::verifyHandoff($store,$aa,'publish',$q['preview'],$q['handoff']),'disconnect invalidates existing browser handoff');
wp_set_current_user($bb['user_id']);$request=new WP_REST_Request('POST','/dashless-hub/v1/preview/approve');$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
check(!$routes->browserPermission($request),'model/application-password identity and nonce without browser cookie rejected');
$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($bb['user_id'],time()+600,'logged_in');$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
check($routes->browserPermission($request),'verified owner cookie plus REST nonce accepted');
$request->set_header('Origin','https://evil.test');check(!$routes->browserPermission($request),'cross-origin human callback rejected');
$request->set_header('Origin',Config::origin());$request->set_header('X-WP-Nonce','forged');check(!$routes->browserPermission($request),'forged nonce rejected');
authRejects(fn()=>$oauth->authenticate('Bearer '.Auth0Fixture::access($bb['user_id'],['aud'=>'https://evil.test'])),'wrong Auth0 token audience rejected');
authRejects(fn()=>$oauth->authenticate('Bearer '.Auth0Fixture::access($bb['user_id'],['scope'=>'fleet:admin'])),'unknown-only Auth0 token scope rejected');
$owner=$bb['user_id'];$upload=['file'=>['download_url'=>'https://files.oaiusercontent.com/fixture.png','file_id'=>'file-expired','mime_type'=>'image/png','file_name'=>'fixture.png'],'alt_text'=>'Expired fixture','client_key'=>'expired-upload-key'];
$binding=hash('sha256',wp_json_encode([$bb['site_id'],'file-expired','fixture.png','image/png','Expired fixture']));
$store->put('consent','upload:'.$owner.':expired-upload-key',['binding'=>$binding,'epoch'=>Chatgpt::epoch($store,$owner)],$owner,'pending',time()-1);
check($invoke($owner,'import_chatgpt_file',$upload)['result']['structuredContent']['code']==='upload_expired','expired upload session cannot be completed');
$site->uploadCap=false;check($invoke($owner,'import_chatgpt_file',$upload)['result']['structuredContent']['code']==='capability_unavailable','unsupported site file transfer fails closed');
class ContinuationSite extends Agent {
 public array $state=['status'=>'queued','continuation_required'=>true];
 public function call(array $a,string $method,string $route,array $data=[]):array{return ['contract_version'=>1,'site_id'=>$a['site_id'],'job_id'=>basename($route)]+$this->state;}
}
$continuationOwner=900001;$identity->save(['user_id'=>$continuationOwner,'state'=>'ready','entitlement'=>'active','site_id'=>999001]);
$continuationSite=new ContinuationSite();$continuationCloud=new TestCloud();$continuationJobs=new Jobs($store,$identity,$continuationCloud,$continuationSite);$continuationId=wp_generate_uuid4();
$continuationJobs->remember($continuationOwner,['job_id'=>$continuationId],'export');$continuationJobs->tick($continuationOwner);$continuationJobs->tick($continuationOwner);
check(count($continuationCloud->dispatch)===1 && $store->get('job',$continuationId)['status']==='running','checkpoint cannot redispatch while prior task is not terminal');
$continuationCloud->tasks['task_0']=['complete'=>true];$continuationJobs->tick($continuationOwner);
check(count($continuationCloud->dispatch)===1 && $store->get('job',$continuationId)['status']==='queued' && !isset($store->get('job',$continuationId)['data']['task_id']),'explicit completed-task checkpoint requeues without dispatch in the same tick');
do{$waitingId=wp_generate_uuid4();}while(strcmp(hash('sha256',$waitingId),hash('sha256',$continuationId))>=0);
$continuationJobs->remember($continuationOwner,['job_id'=>$waitingId]);
$continuationJobs->tick($continuationOwner);check(count($continuationCloud->dispatch)===2 && $continuationCloud->dispatch[1]['args'][2]===$continuationId,'next tick prioritizes the exact continuation UUID over unrelated queued work');
$continuationCloud->tasks['task_1']=['complete'=>true];$continuationSite->state=['status'=>'running','continuation_required'=>true];$continuationJobs->tick($continuationOwner);check(count($continuationCloud->dispatch)===2 && $store->get('job',$continuationId)['status']==='running','running site state never automatically retries');
$continuationSite->state=['status'=>'queued','continuation_required'=>false];$continuationJobs->tick($continuationOwner);check(count($continuationCloud->dispatch)===2 && $store->get('job',$continuationId)['status']==='running','queued site without explicit checkpoint never automatically retries');
$row=$store->get('job',$continuationId);unset($row['data']['task_id']);$store->put('job',$continuationId,$row['data'],$continuationOwner,'running');$continuationSite->state=['status'=>'queued','continuation_required'=>true];$continuationJobs->tick($continuationOwner);check(count($continuationCloud->dispatch)===2 && $store->get('job',$continuationId)['status']==='running','unknown native dispatch cannot authorize a continuation');
check(!Config::checkoutAllowed(),'checkout remains gated after fixtures');
echo '\n'.($passed-$baseline)." ChatGPT integration checks passed; two synthetic subscriber journeys, not real WordPress site runtime or ChatGPT.\n";
