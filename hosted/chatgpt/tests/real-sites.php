<?php
/** Real local Hub -> HTTP site REST -> native Astro -> HTTP public verification. No WP Cloud/ChatGPT claim. */
require dirname(__DIR__,2).'/tests/integration.php';
use Dashless\Hub\{App,Agent,Cloud,Crypto,Config,Jobs,Mcp,Previews};
$baseline=$passed;$oauthQuery=$query;remove_all_filters('pre_http_request');
function localCommand(array $command): string {
 $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($p);
 if($status!==0)throw new RuntimeException('Local fixture command failed: '.substr($err,-1500).' '.substr($out,-1500));return $out;
}
$origins=[];$accounts=[];$credentials=[];$tokenByOwner=[];$rootByOwner=[];
foreach(['a'=>8892,'b'=>8893] as $letter=>$port){
 $name='chatgpt-real-'.$letter;$u=get_user_by('login',$name);$owner=(int)($u?$u->ID:wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.test','user_pass'=>wp_generate_password(48),'role'=>'subscriber']));update_user_meta($owner,'dashless_email_verified',true);
 $secret=bin2hex(random_bytes(32));$file=tempnam('/tmp','dashless-fixture-secret-');chmod($file,0600);file_put_contents($file,$secret);
 $siteRoot='/tmp/dashless-site-test-chatgpt-'.$letter;$origin='http://localhost:'.$port;
 try{localCommand(['php',dirname(__DIR__,2).'/site-tests/configure.php',$siteRoot,(string)(880000+$owner),(string)$owner,$file,$origin]);}finally{unlink($file);}
 localCommand(['php',__DIR__.'/prepare-site.php',$siteRoot]);
 $a=['user_id'=>$owner,'state'=>'ready','entitlement'=>'active','site_id'=>880000+$owner,'slug'=>$name,'domain'=>$name.'.dashless.blog','site_secret'=>Crypto::seal($secret)];$identity->save($a);$accounts[]=$a;$origins[$a['domain']]=$origin;$credentials[$owner]=$secret;$rootByOwner[$owner]=$siteRoot;
 $location=$oauth->approve($oauthQuery,$owner,true)->getHeaderLine('Location');parse_str(parse_url($location,PHP_URL_QUERY),$authResponse);
 $tokens=json_decode((string)$oauth->token(['grant_type'=>'authorization_code','client_id'=>'chatgpt-fixture','code'=>$authResponse['code'],'redirect_uri'=>$oauthQuery['redirect_uri'],'code_verifier'=>$verifier,'resource'=>Config::resource()])->getBody(),true);$tokenByOwner[$owner]=$tokens['access_token'];
 check($oauth->authenticate('Bearer '.$tokens['access_token'])['owner']===$owner,'real site owner connected using local OAuth');
}
$image=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jM1sAAAAASUVORK5CYII=');
add_filter('pre_http_request',function($pre,$args,$url)use($origins,$image){
 $host=parse_url($url,PHP_URL_HOST);
 if(isset($origins[$host]))return wp_remote_request($origins[$host].parse_url($url,PHP_URL_PATH).(parse_url($url,PHP_URL_QUERY)?'?'.parse_url($url,PHP_URL_QUERY):''),$args);
 if($url==='https://files.oaiusercontent.com/fixture.png')return ['headers'=>[],'body'=>$image,'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
 if($host==='localhost')return $pre;
 throw new RuntimeException('Unexpected external service in local integration.');
},10,3);
class LocalQueueCloud extends Cloud {
 public array $taskIds=[];
 public function __construct(private Agent $agent,private array $accounts){}
 public function task(int $site,array $args):string{$id='local-'.count($this->taskIds);$this->taskIds[$id]=[$site,$args[2]];return $id;}
 public function taskStatus(string $id):array{[$site,$job]=$this->taskIds[$id];foreach($this->accounts as $a)if($a['site_id']===$site){$r=$this->agent->call($a,'GET','/jobs/'.$job);return ['complete'=>in_array($r['status'],['succeeded','failed','canceled'],true)];}throw new RuntimeException('Unknown task.');}
}
$agent=new Agent();$cloud=new LocalQueueCloud($agent,$accounts);$jobs=new Jobs($store,$identity,$cloud,$agent);$app=new App();$app->store=$store;$app->identity=$identity;$app->agent=$agent;$app->jobs=$jobs;$mcp=new Mcp($store,$identity,$agent,$jobs);$previews=new Previews($app);
$call=function($owner,$name,$args=[])use($mcp,$oauth,$tokenByOwner){$r=$mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],$oauth->authenticate('Bearer '.$tokenByOwner[$owner]));if(isset($r['error']) || !empty($r['result']['isError']))throw new RuntimeException('Hub tool failed: '.$name.' '.wp_json_encode($r));return $r['result'];};
$run=function($owner,$job)use($rootByOwner,$call){
 for($step=0;$step<128;$step++){
  localCommand(['wp','dashless','build-job',$job,'--path='.$rootByOwner[$owner]]);$result=$call($owner,'get_job',['job_id'=>$job])['structuredContent'];
  if(($result['status']??'')==='succeeded')return $result;
  if(($result['status']??'')==='queued' && ($result['continuation_required']??false)===true){$call($owner,'get_job',['job_id'=>$job]);continue;}
  throw new RuntimeException('Real build failed: '.wp_json_encode($result));
 }
 throw new RuntimeException('Local fixture continuation bound exceeded.');
};
putenv('DASHLESS_CHATGPT_FILE_ORIGINS=https://files.oaiusercontent.com');$previewByOwner=[];
foreach($accounts as $a){
 $owner=$a['user_id'];$draft=$call($owner,'create_draft',['post_type'=>'post','client_key'=>'real-draft-'.$owner,'title'=>'Private journey '.$owner,'content'=>'<p>Only my isolated test blog.</p>'])['structuredContent'];$post=$draft['post']['id'];check($draft['post']['status']==='draft','real WordPress draft remains unpublished');
 $upload=$call($owner,'import_chatgpt_file',['file'=>['file_id'=>'file-local-'.$owner,'download_url'=>'https://files.oaiusercontent.com/fixture.png','file_name'=>'pixel.png','mime_type'=>'image/png'],'alt_text'=>'A fixture pixel','client_key'=>'real-upload-'.$owner])['structuredContent'];check($upload['media_id']>0,'real site stores Hub-relayed private media');
 $current=$call($owner,'get_post',['post_type'=>'post','id'=>$post])['structuredContent'];$call($owner,'update_draft',['post_type'=>'post','id'=>$post,'expected_modified_gmt'=>$current['modified_gmt'],'featured_media'=>$upload['media_id']]);
 $job=$call($owner,'create_preview',['post_type'=>'post','id'=>$post,'client_key'=>'real-preview-'.$owner])['structuredContent']['job_id'];$done=$run($owner,$job);$preview=$done['preview_id'];$previewByOwner[$owner]=$preview;check($done['preview_ready'] && $done['full_snapshot'],'real Astro preview completes outside HTTP');
 $denied=wp_remote_get($origins[$a['domain']].'/wp-json/dashless-hosted/v1/previews/'.$preview.'/files/index.html');check(wp_remote_retrieve_response_code($denied)===401,'real HTTP rejects unsigned private preview HTML');
 $denied=wp_remote_get($origins[$a['domain']].'/wp-json/dashless-hosted/v1/media/'.$upload['media_id']);check(wp_remote_retrieve_response_code($denied)===401,'real HTTP rejects unsigned private media');
 $review=$call($owner,'request_publication_approval',['preview_id'=>$preview]);parse_str(parse_url($review['_meta']['review_url'],PHP_URL_QUERY),$q);
 $queued=$previews->approve($owner,$preview,$q['handoff']);check($previews->approve($owner,$preview,$q['handoff'])['job_id']===$queued['job_id'],'real browser approval retry reuses one publication job');$published=$run($owner,$queued['job_id']);check($published['publicly_verified'] && $published['deployed'] && $published['published_in_wordpress'],'real HTTP verifies first approved public release');
 $live=wp_remote_get($origins[$a['domain']].'/');check(wp_remote_retrieve_header($live,'x-dashless-release')===$published['release_id'],'actual public response carries approved release header');
 $first=$published['release_id'];
 $current=$call($owner,'get_post',['post_type'=>'post','id'=>$post])['structuredContent'];$staged=$call($owner,'stage_update',['post_type'=>'post','id'=>$post,'expected_modified_gmt'=>$current['modified_gmt'],'changes'=>['title'=>'Second version '.$owner]])['structuredContent'];
 $secondJob=$call($owner,'create_preview',['change_id'=>$staged['change_id'],'client_key'=>'second-preview-'.$owner])['structuredContent']['job_id'];$second=$run($owner,$secondJob);
 $review=$call($owner,'request_publication_approval',['preview_id'=>$second['preview_id']]);parse_str(parse_url($review['_meta']['review_url'],PHP_URL_QUERY),$q);$queued=$previews->approve($owner,$second['preview_id'],$q['handoff']);$run($owner,$queued['job_id']);
 $releases=$call($owner,'get_release')['structuredContent'];check($releases['previous']['release_id']===$first,'real second release preserves exact rollback target');
 $review=$call($owner,'request_rollback_approval',['release_id'=>$first]);parse_str(parse_url($review['_meta']['review_url'],PHP_URL_QUERY),$q);$queued=$previews->rollback($owner,$first,$q['handoff']);$restored=$run($owner,$queued['job_id']);check($restored['release_id']===$first && $restored['publicly_verified'],'real exact-target rollback is verified by HTTP');
 $export=$call($owner,'export_site',['client_key'=>'real-export-'.$owner])['structuredContent'];$exported=$run($owner,$export['job_id']);check(!empty($exported['export_id']),'real export artifact completes');
 $private=$agent->call($a,'POST','/exports/'.$exported['export_id'].'/download',['account_id'=>$owner,'ttl'=>60]);$download=wp_remote_get($private['url']);check(wp_remote_retrieve_response_code($download)===200 && in_array(strtok(wp_remote_retrieve_header($download,'content-type'),';'),['application/zip','application/x-tar'],true) && strlen(wp_remote_retrieve_body($download))>512,'real private export downloads its declared archive format');check(wp_remote_retrieve_response_code(wp_remote_get($private['url']))===401,'real export ticket cannot replay');
}
[$a,$b]=$accounts;
try{$call($b['user_id'],'request_publication_approval',['preview_id'=>$previewByOwner[$a['user_id']]]);throw new RuntimeException('Cross-account preview accepted.');}catch(RuntimeException $e){check(str_starts_with($e->getMessage(),'Hub tool failed:'),'real site cross-account preview lookup denied');}
echo "\n".($passed-$baseline)." real local Hub/site journey checks passed. Two WordPress installations, actual Astro and public HTTP; OpenAI file source and Cloud dispatch simulated. Not WP Cloud or ChatGPT acceptance.\n";
