<?php
// Real disposable WordPress → deployed Railway → verified private candidate.
$root=$argv[1]??'';$url=$argv[2]??'';$keyFile=$argv[3]??'';
if (!str_starts_with($root,'/tmp/dashless-site-test-railway-') || !is_file($root.'/wp-load.php') || $url==='' || !is_file($keyFile)) { fwrite(STDERR,"Usage: php hosted/railway/site-integration.php /tmp/dashless-site-test-railway-N https://builder.example /protected/site-key\n"); exit(2); }
ob_start();require $root.'/wp-load.php';error_reporting(E_ALL & ~E_DEPRECATED);
if (wp_get_environment_type()!=='local') throw new RuntimeException('Local fixture required.');
use Dashless\Site\{App,RemoteRuntime,Routes,Failure};
$app=App::boot();$app->store->install();wp_set_current_user(1);
$site=4242001;$owner=21;
update_option('dashless_hosted_config',['site_id'=>$site,'account_id'=>$owner,'hub'=>'https://dashless.blog','credential_hash'=>hash('sha256',random_bytes(32)),'service_user'=>1,'adopted'=>false],false);
$app->store->put('entitlement','current',['active'=>true,'retain_until'=>0]);
(new RemoteRuntime($app))->configure(['url'=>$url,'token'=>hash_hmac('sha256','dashless-builder-v1:'.$site,trim(file_get_contents($keyFile)))]);
$draft=$app->tool('create_draft',['actor'=>['account_id'=>$owner],'arguments'=>['post_type'=>'post','client_key'=>'railway-draft-'.wp_generate_uuid4(),'title'=>'Built on Railway, owned in WordPress','content'=>'<p>This draft goes through the real remote build and private candidate import.</p>']]);
$id=$draft['post']['id'];$j=$app->tool('create_preview',['actor'=>['account_id'=>$owner],'arguments'=>['client_key'=>'railway-preview-'.wp_generate_uuid4(),'post_type'=>'post','id'=>$id]]);
$states=[];$expires=time()+90;
do { $result=$app->jobs->run($j['job_id']);$states[]=$result['status'];if($result['status']!=='queued')break;sleep(2); } while(time()<$expires);
if ($result['status']!=='succeeded' || !$result['preview_ready']) throw new RuntimeException(json_encode($result));
$p=$app->store->owned('preview',$result['preview_id'],$owner);
$html=(new Routes($app))->artifact($p['candidate'],'index.html','');
if (!str_contains($html,'Built on Railway') || get_post_status($id)!=='draft') throw new RuntimeException('Candidate content or draft state invalid');
$rejected=false;try{$app->approve(['preview_id'=>$result['preview_id'],'actor'=>['account_id'=>$owner,'source'=>'model'],'idempotency_key'=>wp_generate_uuid4()]);}catch(Failure $e){$rejected=$e->slug==='human_approval_required';}
if(!$rejected)throw new RuntimeException('Model approval accepted');
$approval=$app->approve(['preview_id'=>$result['preview_id'],'actor'=>['account_id'=>$owner,'source'=>'authenticated_browser'],'idempotency_key'=>wp_generate_uuid4()]);
$pub=$app->tool('publish_previewed',['actor'=>['account_id'=>$owner],'arguments'=>['preview_id'=>$result['preview_id'],'approval_id'=>$approval['approval_id'],'client_key'=>wp_generate_uuid4()]]);
// Public URL belongs to this disposable local fixture. Mock only this final HTTP check.
add_filter('pre_http_request',function($pre,$args,$request)use($app){
 if (!str_starts_with($request,home_url('/')))return $pre;
 $active=$app->store->get('release','active')['data'];return ['headers'=>['x-dashless-release'=>$active['release_id']],'body'=>(new Routes($app))->artifact($active['candidate'],'index.html',''),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
},10,3);
$published=$app->jobs->run($pub['job_id']);if($published['status']!=='succeeded'||!$published['deployed'])throw new RuntimeException(json_encode($published));
ob_end_clean();echo json_encode(['passed'=>true,'transport'=>'real_https_to_railway','preview_states'=>$states,'preview_ready'=>true,'draft_preserved_until_approval'=>true,'model_approval_rejected'=>true,'approved_publication'=>true,'public_http_verification'=>'local_fixture_mock','job_id'=>$j['job_id']],JSON_PRETTY_PRINT)."\n";
