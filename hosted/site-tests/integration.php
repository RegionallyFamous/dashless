<?php
/** Real WordPress + SQLite integration. Only run against the disposable site-test installation. */
ob_start();$root=$argv[1]??'';
if (!str_starts_with($root,'/tmp/dashless-site-test-') || !is_file($root.'/wp-load.php')) { throw new RuntimeException('Pass the isolated site-test WordPress root.'); }
require $root.'/wp-load.php';error_reporting(E_ALL & ~E_DEPRECATED);
if (wp_get_environment_type()!=='local') { throw new RuntimeException('Local WordPress required.'); }
use Dashless\Site\{App,Support,Routes,Runtime,Cli,Failure};
$app=App::boot();$app->store->install();global $wpdb;$wpdb->query('DELETE FROM '.$app->store->table());delete_option('dashless_hosted_config');
foreach (get_posts(['post_type'=>['post','page','attachment'],'post_status'=>'any','posts_per_page'=>-1]) as $p) { wp_delete_post($p->ID,true); }
$runtime=dirname(__DIR__).'/runtime';$secret=bin2hex(random_bytes(32));
$manifest=json_decode(file_get_contents($runtime.'/runtime-manifest.json'),true);foreach ($manifest['files'] as &$f) { $f['bytes']=filesize($runtime.'/'.$f['path']);$f['sha256']=hash_file('sha256',$runtime.'/'.$f['path']); }unset($f);file_put_contents($runtime.'/runtime-manifest.json',json_encode($manifest));
add_action('dashless_hosted_process_failure',fn($work)=>fwrite(STDERR,file_get_contents($work.'/process.log')));
add_action('dashless_hosted_job_exception',fn($e)=>fwrite(STDERR,(string)$e));
update_option('dashless_hosted_config',['site_id'=>152056190,'account_id'=>11,'hub'=>'https://dashless.example.test','credential_hash'=>hash('sha256',$secret),'service_user'=>1,'runtime_path'=>$runtime,'runtime_sha256'=>hash_file('sha256',$runtime.'/runtime-manifest.json'),'runtime_manifest_sha256'=>hash_file('sha256',$runtime.'/runtime-manifest.json'),'runtime_verified'=>true,'adopted'=>false],false);
$app->store->put('entitlement','current',['active'=>true,'retain_until'=>0]);wp_set_current_user(1);do_action('rest_api_init');$passed=0;
function check($yes,$label){global $passed;if(!$yes)throw new RuntimeException('FAIL: '.$label);$passed++;echo "PASS $label\n";}
function rejects(callable $fn,string $slug,string $label){try{$fn();}catch(Failure $e){check($e->slug===$slug,$label.' ('.$e->slug.')');return;}throw new RuntimeException('FAIL no rejection: '.$label);}
function tool(string $name,array $a=[],int $owner=11){global $app;return $app->tool($name,['actor'=>['account_id'=>$owner],'arguments'=>$a,'idempotency_key'=>$a['client_key']??null]);}
function req(string $method,string $path,array|string|null $data=null,bool $auth=true,array $headers=[]){global $secret;$r=new WP_REST_Request($method,'/dashless-hosted/v1'.$path);if($auth){$r->set_header('Authorization','Bearer '.$secret);$r->set_header('X-Dashless-Contract','1');}foreach($headers as $k=>$v)$r->set_header($k,$v);if(is_array($data)){$r->set_header('Content-Type','application/json');$r->set_body(json_encode($data));}elseif(is_string($data))$r->set_body($data);return rest_do_request($r);}
check(req('GET','/capabilities',null,false)->get_status()===401,'anonymous service access denied');
check(req('GET','/capabilities')->get_data()['site_id']===152056190,'atomic ID returned instead of local blog ID');
rejects(fn()=>tool('get_design',[],22),'wrong_account','second account cannot access first site');
rejects(fn()=>tool('create_draft',['post_type'=>'post','client_key'=>'draft-alpha','title'=>'A','content'=>'<p>A</p>','status'=>'publish']),'invalid_arguments','schema rejects direct publishing');
$draft=tool('create_draft',['post_type'=>'post','client_key'=>'draft-alpha','title'=>'A story','content'=>'<p>Private first version</p>']);$id=$draft['post']['id'];
check(tool('create_draft',['post_type'=>'post','client_key'=>'draft-alpha','title'=>'A story','content'=>'<p>Private first version</p>'])===$draft,'draft retry creates exactly one item');
rejects(fn()=>tool('create_draft',['post_type'=>'post','client_key'=>'draft-alpha','title'=>'Changed','content'=>'<p>A</p>']),'idempotency_conflict','changed input cannot reuse key');
check(get_post_status($id)==='draft','draft remains private');
$g=$app->content->generation();update_post_meta($id,'_wp_attachment_image_alt','new metadata');check($app->content->generation()>$g,'metadata invalidates snapshots');
$upload=tool('create_media_upload',['filename'=>'private.png','alt_text'=>'An unpublished red pixel','client_key'=>'upload-alpha']);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZp8AAAAASUVORK5CYII=');
check(req('POST','/uploads/'.$upload['upload_id'].'/content',$png,true,['X-Dashless-Account-ID'=>'22'])->get_status()===403,'cross-account upload denied');
check(req('POST','/uploads/'.$upload['upload_id'].'/content','<?php echo 1;',true,['X-Dashless-Account-ID'=>'11'])->get_status()===400,'executable upload rejected');
$u=req('POST','/uploads/'.$upload['upload_id'].'/content',$png,true,['X-Dashless-Account-ID'=>'11'])->get_data();check(isset($u['media_id']),'raw binary upload creates private attachment');$media=$u['media_id'];
check(req('POST','/uploads/'.$upload['upload_id'].'/content',$png,true,['X-Dashless-Account-ID'=>'11'])->get_data()['media_id']===$media,'upload completion retry is idempotent');
check(req('GET','/media/'.$media,null,false)->get_status()===401,'private media cannot be requested anonymously');
$draft=tool('get_post',['post_type'=>'post','id'=>$id]);tool('update_draft',['post_type'=>'post','id'=>$id,'expected_modified_gmt'=>$draft['modified_gmt'],'featured_media'=>$media]);
$j=tool('create_preview',['client_key'=>'preview-alpha','post_type'=>'post','id'=>$id]);check($j['status']==='queued','preview HTTP persists a job without running a build');
check($j['message']==='Your preview is waiting.','people see plain-language progress, not queue terminology');
$lease=$app->store->lock('build',260);check($app->jobs->run($j['job_id'])['status']==='queued','second worker cannot claim active site lease');$app->store->unlock('build',$lease);
$worker=proc_open(['php',__DIR__.'/lease-worker.php',$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
check(trim(fgets($pipes[1]))==='locked' && $app->jobs->run($j['job_id'])['status']==='queued','a separate PHP process excludes concurrent job execution');fwrite($pipes[0],"release\n");fclose($pipes[0]);fclose($pipes[1]);check(proc_close($worker)===0,'independent worker releases its lease');
$job=$app->jobs->run($j['job_id']);check($job['status']==='succeeded','real Astro preview build succeeds: '.json_encode($job));$preview=$job['preview_id'];
check($job['full_snapshot'] && $job['snapshot_counts']['posts']===1,'build reports full WordPress snapshot');
check($app->jobs->run($j['job_id'])['attempts']===1,'terminal job replay does not build twice');
check(req('GET','/previews/'.$preview.'/files/index.html',null,false)->get_status()===401,'direct preview HTML denied');
$p=$app->store->owned('preview',$preview,11);$candidate=$app->store->owned('candidate',$p['candidate'],11);$asset=array_values(array_filter($candidate['manifest']['files'],fn($f)=>str_starts_with($f['path'],'media/')))[0]['path'];
check(req('GET','/previews/'.$preview.'/files/'.$asset,null,false)->get_status()===401,'direct private preview asset denied');
$handoff=req('POST','/previews/'.$preview.'/browser',['account_id'=>11,'ttl'=>3600])->get_data();parse_str(parse_url($handoff['url'],PHP_URL_QUERY),$q);
$r=new WP_REST_Request('GET','/dashless-hosted/v1/previews/'.$preview.'/open');$r->set_query_params($q);$opened=rest_do_request($r);check($opened->get_status()===303,'one-use browser ticket opens session');
check(rest_do_request($r)->get_status()===401,'browser ticket cannot replay');$cookie=$opened->get_headers()['Set-Cookie'];check(str_contains($cookie,'Secure; HttpOnly; SameSite=None')&&!str_contains($cookie,'Domain='),'preview session is secure and host scoped');
preg_match('/^([^=]+)=([^;]+)/',$cookie,$c);$_COOKIE[$c[1]]=$c[2];$html=req('GET','/previews/'.$preview.'/files/index.html',null,false)->get_data()['__dashless_body'];
check(str_contains($html,'A story') && !str_contains($html,'/__DASHLESS_BASE__'),'preview page references only session-protected asset paths');
check(req('GET','/previews/'.$preview.'/files/'.$asset,null,false)->get_status()===200,'authorized browser can load its private image');
check(req('GET','/previews/'.$preview.'/files/../snapshot.json',null,false)->get_status()===404,'asset traversal rejected');
rejects(fn()=>$app->approve(['preview_id'=>$preview,'actor'=>['account_id'=>11,'source'=>'model'],'idempotency_key'=>'approval-alpha']),'human_approval_required','model cannot mint approval');
$ap=$app->approve(['preview_id'=>$preview,'actor'=>['account_id'=>11,'source'=>'authenticated_browser'],'idempotency_key'=>'approval-alpha']);
$private=get_post_meta($media,'_dashless_private_media',true);$chunk=$app->vault()->get($private['key'].':0');$app->vault()->put($private['key'].':0',$chunk.'tampered');$work=$app->runtime->workspace();
rejects(fn()=>$app->content->assertCurrent($p,true,$work),'artifact_invalid','tampered private media rejects publication before WordPress save');$app->vault()->put($private['key'].':0',$chunk);Runtime::removeWorkspace($work);
$work=$app->runtime->workspace();Support::write($work.'/changed.png',$chunk.'new file bytes');$app->vault()->putFile($private['key'],$work.'/changed.png');
rejects(fn()=>$app->content->assertCurrent($p,true,$work),'stale_approval','validly stored changed asset invalidates approval even without a generation change');Support::write($work.'/changed.png',$chunk);$app->vault()->putFile($private['key'],$work.'/changed.png');Runtime::removeWorkspace($work);
$generation=$app->content->generation();update_post_meta($id,'fixture_new_content','changed');rejects(fn()=>$app->content->assertCurrent($p),'stale_approval','changed content generation invalidates exact preview');
tool('update_design',['expected_version'=>0,'client_key'=>'design-alpha','changes'=>['palette'=>'night']]);
rejects(fn()=>tool('publish_previewed',['preview_id'=>$preview,'approval_id'=>$ap['approval_id'],'client_key'=>'publish-stale']),'stale_approval','changed design rejects approval');
$j2=tool('create_preview',['client_key'=>'preview-beta','post_type'=>'post','id'=>$id]);$built=$app->jobs->run($j2['job_id']);check($built['status']==='succeeded','new design builds a new candidate');$preview=$built['preview_id'];
$ap=$app->approve(['preview_id'=>$preview,'actor'=>['account_id'=>11,'source'=>'authenticated_browser'],'idempotency_key'=>'approval-beta']);
$pub=tool('publish_previewed',['preview_id'=>$preview,'approval_id'=>$ap['approval_id'],'client_key'=>'publish-alpha']);check($pub['status']==='queued' && get_post_status($id)==='draft','approval consumption queues publication without editing WordPress in HTTP');
check(tool('publish_previewed',['preview_id'=>$preview,'approval_id'=>$ap['approval_id'],'client_key'=>'publish-alpha'])===$pub,'same publication key returns same queued job');
rejects(fn()=>tool('publish_previewed',['preview_id'=>$preview,'approval_id'=>$ap['approval_id'],'client_key'=>'publish-replay']),'approval_unavailable','consumed approval cannot mint another job');
// Force a real activation verification failure, with a safe public-fetch fixture.
$offline=fn()=>new WP_Error('fixture_offline','Offline');add_filter('pre_http_request',$offline,10,3);
$failed=$app->jobs->run($pub['job_id']);check($failed['status']==='failed' && $failed['published_in_wordpress'] && !$failed['deployed'],'failed activation distinguishes saved WordPress from public success: '.json_encode($failed));
check(empty($app->store->get('release','active')['data']),'failed first activation restores prior empty pointer');
remove_filter('pre_http_request',$offline,10);
// Successful public HTTP response fixture verifies activation state transitions, not real network reachability.
$public=function()use($app){$active=$app->store->get('release','active')['data'];return ['headers'=>['x-dashless-release'=>$active['release_id']],'body'=>(new Routes($app))->artifact($active['candidate'],'index.html',''),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];};add_filter('pre_http_request',$public,10,3);
$revisions=count(wp_get_post_revisions($id));$app->jobs->retry($pub['job_id']);$published=$app->jobs->run($pub['job_id']);
check($published['status']==='succeeded' && $published['publicly_verified'] && count(wp_get_post_revisions($id))===$revisions,'publication retry deploys without repeating WordPress save');
$active=$app->store->get('release','active')['data'];
$staged=tool('stage_update',['post_type'=>'post','id'=>$id,'expected_modified_gmt'=>get_post($id)->post_modified_gmt,'changes'=>['title'=>'Revised title']]);
check(get_post($id)->post_title==='A story','staged update leaves the published parent untouched');
$next=tool('create_preview',['client_key'=>'preview-gamma','change_id'=>$staged['change_id']]);$ready=$app->jobs->run($next['job_id']);check($ready['status']==='succeeded','published changeset renders as private candidate');
$approval=$app->approve(['preview_id'=>$ready['preview_id'],'actor'=>['account_id'=>11,'source'=>'authenticated_browser'],'idempotency_key'=>'approval-gamma']);
$queued=tool('publish_previewed',['preview_id'=>$ready['preview_id'],'approval_id'=>$approval['approval_id'],'client_key'=>'publish-gamma']);check($app->jobs->run($queued['job_id'])['publicly_verified'],'second approved release verifies');
$ra=$app->approve(['release_id'=>$active['release_id'],'actor'=>['account_id'=>11,'source'=>'authenticated_browser'],'idempotency_key'=>'rollback-alpha'],true);
$rollback=tool('rollback_release',['release_id'=>$active['release_id'],'approval_id'=>$ra['approval_id'],'client_key'=>'rollback-job']);check($app->jobs->run($rollback['job_id'])['publicly_verified'],'approved rollback verifies exact previous candidate');
rejects(fn()=>tool('rollback_release',['release_id'=>$active['release_id'],'approval_id'=>$ra['approval_id'],'client_key'=>'rollback-replayed']),'approval_unavailable','rollback approval cannot replay');
$export=tool('export_site',['client_key'=>'export-alpha']);$pause=$app->entitlement(['active'=>false]);check($pause['retain_until']>=time()+30*DAY_IN_SECONDS-2,'pausing an active account defaults to a full 30-day recovery period');$app->entitlement(['active'=>false,'retain_until'=>time()+30*DAY_IN_SECONDS]);
rejects(fn()=>tool('get_design'),'site_inactive','suspension disables editing and design reads');
add_filter('dashless_hosted_export_chunk_budget',fn()=>1);$ex=$app->jobs->run($export['job_id']);check($ex['status']==='queued' && $ex['continuation_required'] && $ex['progress']['archive_bytes']>0,'bounded export checkpoints and releases its lease');$exportId=$ex['export_id'];
remove_all_filters('dashless_hosted_export_chunk_budget');$ex=$app->jobs->run($export['job_id']);check($ex['status']==='succeeded' && $ex['export_id']===$exportId && $ex['attempts']===2,'suspended owner resumes the same export without restarting');
$artifact=$app->store->owned('export',$ex['export_id'],11);ob_start();$app->vault()->stream($artifact['key']);$tar=ob_get_clean();$work=$app->runtime->workspace();Support::write($work.'/export.tar',$tar);$archive=new PharData($work.'/export.tar');
check(isset($archive['content.json'],$archive['design.json'],$archive['manifest.json'],$archive['source/template/astro.config.mjs']) && !str_contains($tar,$secret),'portable tar includes content/design/media/source and excludes service credentials');
check($archive['media/'.$media.'-private.png']->getContent()===$png,'resumed archive preserves exact original media bytes');unset($archive);Runtime::removeWorkspace($work);
$download=req('POST','/exports/'.$ex['export_id'].'/download',['account_id'=>11])->get_data();parse_str(parse_url($download['url'],PHP_URL_QUERY),$dq);$dr=new WP_REST_Request('GET','/dashless-hosted/v1/exports/'.$ex['export_id'].'/file');$dr->set_query_params($dq);
check(rest_do_request($dr)->get_status()===200 && rest_do_request($dr)->get_status()===401,'private export ticket works once during retention');
$app->entitlement(['active'=>false,'retain_until'=>time()-1]);check(req('POST','/exports/'.$ex['export_id'].'/download',['account_id'=>11])->get_status()===403,'expired retention blocks private export');
$app->entitlement(['active'=>true]);$new=bin2hex(random_bytes(32));Cli::rotate($app,hash('sha256',$new));check(req('GET','/capabilities')->get_status()===200,'previous credential has bounded verification overlap');$cfg=$app->config();$cfg['previous_until']=time()-1;update_option('dashless_hosted_config',$cfg,false);check(req('GET','/capabilities')->get_status()===401,'old credential expires');$secret=$new;check(req('GET','/capabilities')->get_status()===200,'rotated credential remains valid');
echo "\n$passed real WordPress site integration checks passed. Public verification used a failure fixture; builds used real Astro.\n";
