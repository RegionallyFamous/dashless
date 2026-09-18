<?php
/** Disposable local WordPress only; all mail is intercepted and never delivered. */
ob_start();
$root=$argv[1]??'';
if(!is_file($root.'/wp-load.php'))throw new RuntimeException('Pass a disposable WordPress installation.');
require $root.'/wp-load.php';
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local environment required.');
error_reporting(E_ALL & ~E_DEPRECATED);
use Dashless\Hub\{AuthMail,Config,Failure,Store};
add_filter('home_url',fn()=>'http://dashless.example.test');
$secret=bin2hex(random_bytes(32));
putenv('DASHLESS_AUTH0_EMAIL_SECRET='.$secret);
putenv('DASHLESS_AUTH0_CLIENT_ID=website-fixture');
putenv('DASHLESS_AUTH0_CHATGPT_CLIENT_ID=chatgpt-fixture');
putenv('DASHLESS_AUTH0_MANAGEMENT_AUDIENCE=https://mail-fixture.us.auth0.com/api/v2/');
putenv('DASHLESS_AUTH0_EMAIL_FROM=signin@'.parse_url(Config::origin(),PHP_URL_HOST));
$store=new Store();$store->install();$relay=new AuthMail($store);$passed=0;$mail=[];$send=true;
add_filter('pre_wp_mail',function($previous,$args)use(&$mail,&$send){$mail[]=$args;return $send;},PHP_INT_MAX,2);
function mailCheck(bool $ok,string $description):void {global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$description);$passed++;echo "PASS $description\n";}
function mailReject(callable $call,int $status,string $description):void {try{$call();}catch(Failure $e){mailCheck($e->status===$status,$description);return;}mailCheck(false,$description);}
$run=bin2hex(random_bytes(8));
$payload=['tenant'=>'mail-fixture','client_id'=>'website-fixture','message_type'=>'verification_code','to'=>$run.'@example.test','subject'=>'Your sign-in code','html'=>'<p>123456</p>','text'=>'123456'];
$deliver=function(array $data)use($relay,$secret){$raw=wp_json_encode($data);$time=(string)time();return $relay->deliver($raw,$time,hash_hmac('sha256',$time."\n".$raw,$secret));};
$raw=wp_json_encode($payload);$time=(string)time();$signature=hash_hmac('sha256',$time."\n".$raw,$secret);
mailReject(fn()=>$relay->deliver($raw,$time,''),401,'unsigned mail is refused');
mailReject(fn()=>$relay->deliver($raw.' ',$time,$signature),401,'altered content is refused');
foreach([time()-301,time()+301] as $invalidTime){$t=(string)$invalidTime;mailReject(fn()=>$relay->deliver($raw,$t,hash_hmac('sha256',$t."\n".$raw,$secret)),401,'expired or future signature is refused');}
putenv('DASHLESS_AUTH0_EMAIL_SECRET=');mailReject(fn()=>$deliver($payload),503,'unconfigured relay is disabled');putenv('DASHLESS_AUTH0_EMAIL_SECRET='.$secret);
foreach([
    ['tenant'=>'another-tenant'],['client_id'=>'another-app'],['client_id'=>''],
] as $bad)mailReject(fn()=>$deliver(array_replace($payload,$bad)),403,'wrong tenant or app is refused');
foreach([
    ['to'=>"person@example.test\r\nBcc: other@example.test"],['to'=>'one@example.test,two@example.test'],['to'=>['one@example.test']],
    ['subject'=>"Sign in\nBcc: other@example.test"],['subject'=>str_repeat('a',301)],['subject'=>''],
    ['html'=>'','text'=>''],['message_type'=>'marketing'],['bcc'=>'other@example.test'],['from'=>'attacker@example.test'],
] as $bad)mailReject(fn()=>$deliver(array_replace($payload,$bad)),400,'malformed or expanded mail request is refused');
mailReject(fn()=>$deliver(array_replace($payload,['html'=>str_repeat('a',AuthMail::LIMIT)])),413,'oversized message is refused');
putenv('DASHLESS_AUTH0_EMAIL_FROM=signin@other.test');mailReject(fn()=>$deliver($payload),503,'sender cannot impersonate another domain');putenv('DASHLESS_AUTH0_EMAIL_FROM=signin@'.parse_url(Config::origin(),PHP_URL_HOST));
mailCheck(count($mail)===0,'all rejected requests reached no mail transport');
mailCheck($deliver($payload)===['accepted'=>true] && count($mail)===1,'valid signed message reaches the host mail transport');
mailCheck($mail[0]['to']===$payload['to'] && $mail[0]['message']===$payload['html'] && $mail[0]['headers']===['From: Dashless <signin@'.parse_url(Config::origin(),PHP_URL_HOST).'>','Content-Type: text/html; charset=UTF-8'],'recipient, content and fixed sender preserved');
mailCheck($deliver($payload)===['accepted'=>true] && count($mail)===1,'delivery retry does not send a duplicate');
$saved=$store->get('auth0_mail',hash_hmac('sha256',$raw,$secret));mailCheck($saved['data']===[] && $saved['status']==='accepted','no recipient, message or sign-in code is stored');
$chat=array_replace($payload,['client_id'=>'chatgpt-fixture','html'=>'','text'=>'654321']);$deliver($chat);mailCheck($mail[1]['message']==='654321' && str_contains($mail[1]['headers'][1],'text/plain'),'ChatGPT can send a plain-text code');
$failed=array_replace($payload,['subject'=>'Transport retry']);$send=false;
mailReject(fn()=>$deliver($failed),503,'host mail failure is reported');mailCheck(!$store->get('auth0_mail',hash_hmac('sha256',wp_json_encode($failed),$secret)),'failed transport is not marked accepted');
$send=true;$deliver($failed);mailCheck(count($mail)===4,'failed transport can retry successfully');
$test=array_replace($payload,['client_id'=>'','message_type'=>'try_provider_configuration_email']);
mailReject(fn()=>$deliver($test),403,'dashboard test cannot target arbitrary recipients');
$test['to']=(string)get_option('admin_email');$deliver($test);mailCheck(count($mail)===5,'dashboard test is restricted to the existing site administrator');
$limited=array_replace($payload,['to'=>'limited-'.$run.'@example.test']);
$store->put('rate','auth0-mail:recipient:'.hash_hmac('sha256',$limited['to'],$secret).':'.intdiv(time(),600),['count'=>10],0,'',time()+1200);
mailReject(fn()=>$deliver($limited),429,'recipient request rate is limited');
$request=new WP_REST_Request('POST','/dashless-hub/v1/auth0/email');$request->set_body($raw);$request->set_header('Content-Type','application/json');
wp_set_current_user(0);$response=rest_do_request($request);mailCheck($response->get_status()===401,'real REST route refuses anonymous unsigned messages');
$request->set_header('x-dashless-mail-time',$time);$request->set_header('x-dashless-mail-signature',$signature);
$response=rest_do_request($request);mailCheck($response->get_status()===200 && $response->get_data()===['accepted'=>true] && count($mail)===5,'real REST route accepts authenticated retry without a login cookie or duplicate mail');
$store->put('auth0_mail','expired-mail-fixture',[],0,'accepted',time()-1);$store->prune();mailCheck(!$store->get('auth0_mail','expired-mail-fixture'),'old delivery digests are pruned');
echo "\n$passed host-backed Auth0 mail checks passed. No email was sent.\n";
ob_end_flush();
