<?php
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { return; }
WP_CLI::add_command('dashless railway-smoke',function(){
global $base,$token;
$site=(string)DASHLESS_HUB_SITE_ID;$id=wp_generate_uuid4();$base=DASHLESS_BUILDER_URL.'/v1/sites/'.$site.'/jobs/'.$id;$token=hash_hmac('sha256','dashless-builder-v1:'.$site,DASHLESS_BUILDER_MASTER_KEY);
function builder_call($method,$suffix='',$body=null) {global $base,$token;$args=['method'=>$method,'timeout'=>20,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'limit_response_size'=>64*1024*1024];if($body!==null)$args['body']=is_string($body)?$body:wp_json_encode($body);$r=wp_remote_request($base.$suffix,$args);if(is_wp_error($r)||wp_remote_retrieve_response_code($r)>=300)throw new RuntimeException('Builder transport failed');return wp_remote_retrieve_body($r);}
$input=json_decode(file_get_contents(dirname(__DIR__).'/dashless-railway-fixture.json'),true);$input['site_id']=(int)$site;$start=microtime(true);
builder_call('POST','',$input);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZp8AAAAASUVORK5CYII=');
// This fixture has no uploaded images; it exercises full text routes and generated share cards.
builder_call('POST','/start',new stdClass());
$state=[];
for($n=0;$n<60;$n++){$state=json_decode(builder_call('GET'),true);if(in_array($state['status'],['succeeded','failed'],true))break;sleep(1);}
if(($state['status']??'')!=='succeeded')throw new RuntimeException('Remote build did not succeed');
$result=json_decode(builder_call('GET','/result'),true);$archive=builder_call('GET','/archive');
if(($result['quality']['passed']??false)!==true||$result['post_count']!==115||!hash_equals($result['archive']['sha256'],hash('sha256',$archive)))throw new RuntimeException('Output failed verification');
builder_call('DELETE');
echo wp_json_encode(['passed'=>true,'source'=>'WP Cloud Hub','builder'=>'Railway','posts'=>$result['post_count'],'html_pages'=>$result['html_pages'],'archive_sha256'=>$result['archive']['sha256'],'duration_ms'=>(int)((microtime(true)-$start)*1000),'checkout_enabled'=>\Dashless\Hub\Config::checkoutAllowed()]);

});
