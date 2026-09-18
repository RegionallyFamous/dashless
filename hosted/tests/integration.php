<?php
/** Run only against an expendable local WordPress: php hosted/tests/integration.php /path/to/wordpress */
ob_start();
$root=$argv[1]??'';
if(!is_file($root.'/wp-load.php'))throw new RuntimeException('Pass a disposable WordPress installation.');
putenv('DASHLESS_TEST_CHECKOUT=1');putenv('DASHLESS_STRIPE_SECRET_KEY=sk_test_fixture');putenv('DASHLESS_STRIPE_PRICE_ID=price_fixture');putenv('DASHLESS_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
require $root.'/wp-load.php';
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local environment required.');
error_reporting(E_ALL & ~E_DEPRECATED);
use Dashless\Hub\{Store,Identity,Crypto,Config,OAuth,Billing,StripeGateway,Cloud,Agent,Jobs,Mcp,Failure,Provisioner,Domains,Screens};
$store=new Store();$store->install();global $wpdb;$wpdb->query('DELETE FROM '.$store->table());
$passed=0;
function check($yes,$description){global $passed;if(!$yes)throw new RuntimeException('FAIL: '.$description);$passed++;echo "PASS $description\n";}
function rejects(callable $fn,$description){try{$fn();}catch(Throwable $e){check(true,$description);return;}check(false,$description);}
$_SERVER['REQUEST_METHOD']='GET';
$identity=new Identity($store);$mail=[];
add_filter('pre_wp_mail',function($return,$args)use(&$mail){$mail[]=$args;return true;},10,2);
require __DIR__.'/auth0-fixture.php';Auth0Fixture::install();
foreach(['alice','bob'] as $name){$fixture=get_user_by('email','auth0-'.$name.'@example.test');if($fixture)Auth0Fixture::bind((int)$fixture->ID,'auth0|'.$name);}
$browser=bin2hex(random_bytes(32));
$start=function($return='/account/')use($identity,$browser){parse_str(parse_url($identity->begin($return,$browser),PHP_URL_QUERY),$q);Auth0Fixture::$query=$q;return $q;};
function authRejects(callable $fn,string $description):void {try{$fn();}catch(Failure $e){check(true,$description);return;}check(false,$description);}
$q=$start('/preview/?preview=fixture&handoff=private');
check($q['scope']==='openid profile email' && $q['redirect_uri']===Identity::callback() && $q['code_challenge_method']==='S256' && !empty($q['nonce']),'Auth0 login uses OIDC, fixed callback, nonce and S256 PKCE');
authRejects(fn()=>$identity->complete($q['state'],str_repeat('f',64),'fixture-code'),'wrong browser cannot redeem login');
$result=$identity->complete($q['state'],$browser,'fixture-code');$alice=$result['user_id'];check($result['return']==='/preview/?preview=fixture&handoff=private','login preserves private review handoff');
authRejects(fn()=>$identity->complete($q['state'],$browser,'fixture-code'),'Auth0 state cannot replay');
$q=$start('https://attacker.test');check($identity->complete($q['state'],$browser,'fixture-code')['return']==='/#account','external return URL rejected');
Auth0Fixture::$profile=['sub'=>'auth0|bob','email'=>'auth0-bob@example.test','email_verified'=>true];$q=$start();$bob=$identity->complete($q['state'],$browser,'fixture-code')['user_id'];
$q=$start();$row=$store->get('auth0_state',hash('sha256',$q['state']));$store->put('auth0_state',hash('sha256',$q['state']),$row['data'],0,'unused',time()-1);authRejects(fn()=>$identity->complete($q['state'],$browser,'fixture-code'),'expired provider state rejected');
foreach([false,'true',1,null] as $unverified){Auth0Fixture::$profile['email_verified']=$unverified;$q=$start();authRejects(fn()=>$identity->complete($q['state'],$browser,'fixture-code'),'email verification must be boolean true');}
Auth0Fixture::$profile['email_verified']=true;Auth0Fixture::$profile['sub']='auth0|different';$q=$start();authRejects(fn()=>$identity->complete($q['state'],$browser,'fixture-code'),'matching email cannot take over another identity');
Auth0Fixture::$profile=['sub'=>'auth0|paused','email'=>'auth0-paused@example.test','email_verified'=>true];update_option('dashless_hub_signup_paused',true);$q=$start();authRejects(fn()=>$identity->complete($q['state'],$browser,'fixture-code'),'signup pause blocks new identity');
Auth0Fixture::$profile=['sub'=>'auth0|bob','email'=>'auth0-bob@example.test','email_verified'=>true];$q=$start();check($identity->complete($q['state'],$browser,'fixture-code')['user_id']===$bob,'signup pause allows existing identity');delete_option('dashless_hub_signup_paused');
$q=$start();authRejects(fn()=>$identity->complete($q['state'],$browser,'','access_denied'),'provider denial cannot sign in');
Auth0Fixture::$overrides=['nonce'=>'wrong'];$q=$start();authRejects(fn()=>$identity->complete($q['state'],$browser,'fixture-code'),'ID token nonce mismatch rejected');Auth0Fixture::$overrides=[];
check(Identity::verified($alice) && Identity::verified($bob) && $alice!==$bob,'separate Auth0 identities created');
check(!$mail,'Dashless does not send or mint login links');
wp_set_password('fixture-known-local-password',$alice);check(is_wp_error(wp_authenticate(get_userdata($alice)->user_login,'fixture-known-local-password')),'even valid customer local passwords are denied');
$linkUser=get_user_by('login','auth0-link-fixture');$linkOwner=$linkUser?(int)$linkUser->ID:wp_insert_user(['user_login'=>'auth0-link-fixture','user_email'=>'auth0-link@example.test','user_pass'=>wp_generate_password(40),'role'=>'subscriber']);delete_user_meta($linkOwner,'dashless_auth0_sub');delete_user_meta($linkOwner,'dashless_auth0_issuer');
$identity->save(['user_id'=>$linkOwner,'state'=>'ready','site_id'=>789,'stripe_customer'=>'cus_preserved']);
authRejects(fn()=>$identity->connectAuth0('auth0|link','auth0-link@example.test',true),'existing account requires verified operator migration');
authRejects(fn()=>$identity->linkExisting($alice,'auth0|link'),'operator link rejects wrong existing owner');
$identity->linkExisting($linkOwner,'auth0|link');check(Identity::verified($linkOwner) && !user_can($linkOwner,'manage_options') && $identity->account($linkOwner)['stripe_customer']==='cus_preserved' && $identity->account($linkOwner)['site_id']===789,'explicit migration preserves blog, billing and role');
authRejects(fn()=>$identity->linkExisting($linkOwner,'auth0|link'),'operator link cannot replay or reassign');
rejects(fn()=>Identity::slug('support'),'platform names reserved');
check(Domains::normalize('https://Example.com/')==='example.com','customer domains normalize to a hostname');
authRejects(fn()=>Domains::normalize('not a domain'),'customer domains reject invalid hostnames');
check(Crypto::open(Crypto::seal('fixture-secret'))==='fixture-secret','encrypted credential roundtrip');
$lock=$store->lock('race');check($lock && !$store->lock('race'),'database lease excludes concurrent operation');$store->unlock('race','wrong');check(!$store->lock('race'),'wrong lease cannot unlock');$store->unlock('race',$lock);

$oauth=new OAuth($store);$metadata=$oauth->metadata();
check($metadata['issuer']===Config::auth0Issuer().'/' && $metadata['token_endpoint']===Config::auth0Issuer().'/oauth/token','discovery uses actual provider issuer and token endpoint');
check($oauth->protectedMetadata()['authorization_servers']===[Config::auth0Issuer().'/'] && $oauth->protectedMetadata()['resource']===Config::resource(),'resource metadata separates Auth0 authority from Hub audience');
check(!method_exists($oauth,'approve') && !method_exists($oauth,'token') && !class_exists('League\OAuth2\Server\AuthorizationServer'),'local token issuer and its dependency are removed');
$token=Auth0Fixture::access($alice);check($oauth->authenticate('Bearer '.$token)['owner']===$alice,'real RSA-signed access token resolves exact account');
foreach([['iss'=>'https://wrong.auth0.com/'],['aud'=>'https://wrong.test/mcp'],['aud'=>'browser-fixture'],['exp'=>time()-10],['iat'=>time()+600],['nbf'=>time()+600],['sub'=>'machine@clients'],['gty'=>'client-credentials'],['scope'=>'fleet:admin'],['scope'=>['blog:read']],['sub'=>''],['exp'=>null],['iat'=>null]] as $bad)authRejects(fn()=>$oauth->authenticate('Bearer '.Auth0Fixture::access($alice,$bad)),'invalid access claim rejected: '.array_key_first($bad));
$parts=explode('.',$token);$parts[1]=Firebase\JWT\JWT::urlsafeB64Encode(json_encode(['sub'=>'attacker']));authRejects(fn()=>$oauth->authenticate('Bearer '.implode('.',$parts)),'tampered signature rejected');
$wrongKey=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($wrongKey,$wrongPrivate);
$forged=Firebase\JWT\JWT::encode(['iss'=>Config::auth0Issuer().'/','sub'=>'auth0|alice','aud'=>Config::resource(),'iat'=>time()-1,'exp'=>time()+600],$wrongPrivate,'RS256',Auth0Fixture::$jwk['kid']);authRejects(fn()=>$oauth->authenticate('Bearer '.$forged),'untrusted signing key rejected');
check($oauth->authenticate('Bearer '.Auth0Fixture::access($alice,['scope'=>'openid blog:read fleet:admin']))['scopes']===['blog:read'],'only explicitly granted blog scopes survive');
$subject=(string)get_user_meta($alice,'dashless_auth0_sub',true);
Auth0Fixture::$grants=[['id'=>'own','user_id'=>$subject,'audience'=>Config::resource()],['id'=>'other-user','user_id'=>'auth0|someone','audience'=>Config::resource()],['id'=>'other-api','user_id'=>$subject,'audience'=>'https://other.test']];
Auth0Fixture::$revokeFails=true;authRejects(fn()=>$oauth->disconnect($alice),'provider outage is reported during disconnect');authRejects(fn()=>$oauth->authenticate('Bearer '.$token),'disconnect blocks access immediately despite provider outage');
Auth0Fixture::$revokeFails=false;$oauth->disconnect($alice);check(Auth0Fixture::$deleted===['own'],'disconnect deletes only owner grants for this API');authRejects(fn()=>$oauth->authenticate('Bearer '.$token),'successful disconnect rejects previously issued access tokens');
check(!$store->get('oauth_epoch',(string)$alice)['data']['blocked'],'successful grant revocation permits a new authorization');
Auth0Fixture::$grants=[];
$store->remove('oauth_epoch',(string)$alice);
delete_user_meta($bob,'dashless_auth0_issuer');check(!Identity::verified($bob),'old provider-only session does not authorize a customer');Auth0Fixture::bind($bob,'auth0|bob');

putenv('DASHLESS_STRIPE_WEBHOOK_SECRET=whsec_fixture');$wire=json_encode(['id'=>'evt_signed','object'=>'event','type'=>'invoice.paid','created'=>time(),'data'=>['object'=>['id'=>'in_fixture']]]);$now=time();$signature='t='.$now.',v1='.hash_hmac('sha256',$now.'.'.$wire,'whsec_fixture');
$gateway=new StripeGateway();check($gateway->event($wire,$signature)['id']==='evt_signed','Stripe SDK validates real signed webhook payload');
rejects(fn()=>$gateway->event($wire.' ',$signature),'tampered signed webhook rejected');
$old=$now-600;$oldSignature='t='.$old.',v1='.hash_hmac('sha256',$old.'.'.$wire,'whsec_fixture');rejects(fn()=>$gateway->event($wire,$oldSignature),'expired webhook signature rejected');

class TestStripe extends StripeGateway {
    public array $subs=[],$sessions=[],$checkouts=[],$refunds=[],$cancels=[];
    public function price():array{return ['unit_amount'=>999,'currency'=>'usd','recurring'=>['interval'=>'month','interval_count'=>1],'active'=>true];}
    public function customer(array $a,string $email):string{return 'cus_'.$a['user_id'];}
    public function checkout(array $a,string $attempt):array{$id='cs_'.$attempt;$this->checkouts[$attempt]=$a;return $this->sessions[$id]=['id'=>$id,'status'=>'open','url'=>'https://checkout.stripe.com/test'];}
    public function session(string $id):array{return $this->sessions[$id];}
    public function subscription(string $id):array{return $this->subs[$id];}
    public function event(string $raw,string $signature):array{return json_decode($raw,true);}
    public function portal(string $id):string{return 'https://billing.stripe.com/test';}
    public function cancel(string $id,string $key):void{$this->cancels[$key]=$id;}
    public function invoicePaymentIntent(string $invoice):string{return 'pi_'.$invoice;}
    public function refund(string $id,string $key):string{$this->refunds[$key]=$id;return 're_fixture';}
}
$stripe=new TestStripe();$billing=new Billing($store,$identity,$stripe);
$billing->checkout($alice,'alice-blog');$billing->checkout($alice,'alice-blog');check(count($stripe->checkouts)===1,'repeated checkout reuses session');rejects(fn()=>$billing->checkout($bob,'alice-blog'),'address uniqueness across customers');
$a=$identity->account($alice);check($a['state']==='checkout' && empty($a['site_id']),'checkout alone does not provision');
$sub=['id'=>'sub_alice','customer'=>$a['stripe_customer'],'metadata'=>['dashless_user'=>(string)$alice,'dashless_slug'=>$a['slug'],'dashless_attempt'=>$a['checkout_attempt']],'status'=>'active','latest_invoice'=>['id'=>'in_initial','status'=>'paid'],'items'=>['data'=>[['price'=>['id'=>'price_fixture'],'quantity'=>1,'current_period_end'=>time()+30*DAY_IN_SECONDS]]]];
$stripe->subs[$sub['id']]=$sub;
$event=['id'=>'evt_paid','type'=>'invoice.paid','created'=>time(),'data'=>['object'=>['subscription'=>$sub['id']]]];$billing->receive(json_encode($event),'test');$billing->receive(json_encode($event),'test');$billing->process('evt_paid');$billing->process('evt_paid');
check(count($store->rows('stripe_event'))===1 && $identity->account($alice)['state']==='provisioning','webhook replay provisions once from fresh Stripe state');
$a=$identity->account($alice);$a['state']='ready';$a['site_id']=123;$a['release_id']='release_fixture';$a['ready_at']=1700000000;$a['public_domain']='alice.dashless.blog';$a['phase_state']=['verify'=>['remote'=>['content_generation'=>12]]];$identity->save($a);
$public=Screens::publicAccount($a);check($public['site_url']==='https://alice.dashless.blog' && $public['release']['verified']===true && $public['release']['content_generation']===12,'account summary exposes verified release health without private credentials');
$sub['cancel_at_period_end']=true;$billing->reconcile($sub);check($identity->account($alice)['entitlement']==='active','scheduled cancellation retains paid access');
$sub['status']='past_due';$a=$identity->account($alice);$a['paid_through']=time()-DAY_IN_SECONDS;$identity->save($a);$billing->reconcile($sub);check($identity->account($alice)['entitlement']==='grace','failed renewal gets seven-day grace');
$a=$identity->account($alice);$a['past_due_since']=time()-8*DAY_IN_SECONDS;$identity->save($a);$billing->reconcile($sub);check($identity->account($alice)['entitlement']==='expired','expired grace suspends entitlement');
$sub['status']='active';$billing->reconcile($sub);check($identity->account($alice)['entitlement']==='active' && !isset($identity->account($alice)['delete_after']),'paid reactivation clears retention');

class TestCloud extends Cloud {public array $dispatch=[],$tasks=[];public function task(int $site,array $args):string{$id='task_'.count($this->dispatch);$this->dispatch[]=['site'=>$site,'args'=>$args];$this->tasks[$id]=['complete'=>false];return $id;}public function taskStatus(string $id):array{return $this->tasks[$id];}}
class TestAgent extends Agent {public array $states=[],$calls=[];public function call(array $a,string $method,string $path,array $body=[]):array{$this->calls[]=$a['site_id'];$id=basename($path);return ['contract_version'=>1,'site_id'=>$a['site_id'],'job_id'=>$id,'status'=>$this->states[$id]??'queued'];}}
$cloud=new TestCloud();$agent=new TestAgent();$jobs=new Jobs($store,$identity,$cloud,$agent);
$j1=wp_generate_uuid4();$j2=wp_generate_uuid4();$jobs->remember($alice,['job_id'=>$j1]);$jobs->tick($alice);$jobs->remember($alice,['job_id'=>$j2]);$jobs->tick($alice);check(count($cloud->dispatch)===1,'second build stays queued while first task runs');
rejects(fn()=>$jobs->status($bob,$j1),'cross-account job access rejected');
$cloud->tasks['task_0']=['complete'=>true];$agent->states[$j1]='succeeded';$jobs->status($alice,$j1);check(count($cloud->dispatch)===2,'completion releases next queued build');
$cloud->tasks['task_1']=['complete'=>true];$agent->states[$j2]='failed';$jobs->tick($alice);check($store->get('job',$j2)['status']==='failed','failed task reconciles terminal site state');
$a=$identity->account($alice);$a['state']='suspended';$a['entitlement']='expired';$identity->save($a);$export=wp_generate_uuid4();$jobs->remember($alice,['job_id'=>$export],'export');$jobs->tick($alice);check(count($cloud->dispatch)===3,'suspended account can execute recovery export');
$mcp=new Mcp($store,$identity,$agent,$jobs);$result=$mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'get_job','arguments'=>['job_id'=>$j1,'site_id'=>999]]],['owner'=>$alice,'scopes'=>['blog:read']]);check(isset($result['error']),'MCP refuses customer-supplied routing');
putenv('DASHLESS_TEST_CHECKOUT=0');check(!Config::checkoutAllowed(),'live checkout disabled without acceptance gates');
// Provisioning crash recovery: a timed-out create is recovered by hostname + operation, never repeated.
putenv('DASHLESS_SITE_PACKAGE_URL=https://localhost:8874/site-fixture.zip');putenv('DASHLESS_SITE_PACKAGE_SHA256='.hash('sha256','fixture-package'));
add_filter('pre_http_request',function($pre,$args,$url){if($pre!==false)return $pre;if($url==='https://localhost:8874/site-fixture.zip')return ['headers'=>[],'body'=>'fixture-package','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];throw new RuntimeException('Unexpected external HTTP in fixture test: '.$url);},10,3);
class ProvisionCloud extends TestCloud {
    public int $creates=0;public ?array $site=null;public array $proof=[];
    public function find(string $domain):?array{return $this->site;}
    public function provenance(int $id):array{return $this->proof;}
    public function create(array $a):array{$this->creates++;$this->site=['site_id'=>456,'domain'=>$a['domain']];$this->proof=['dashless'=>['operation'=>$a['provision_id'],'account'=>$a['user_id']]];throw new Failure('cloud_unavailable','Uncertain fixture response.',503);}
}
class ProvisionAgent extends TestAgent {
    public function call(array $a,string $method,string $path,array $body=[]):array {
        $base=['contract_version'=>1,'site_id'=>$a['site_id']];
        if($path==='/capabilities')return $base+['capabilities'=>array_fill_keys(['jobs','private_previews','approvals','exports','design','runtime'],true)];
        if(str_starts_with($path,'/jobs'))return $base+['status'=>'succeeded','release_id'=>'release-fixture'];
        return $base+['ready'=>false];
    }
    public function publicHealth(array $a,string $release):bool{return $release==='release-fixture';}
}
$b=$identity->account($bob);$b['state']='provisioning';$b['entitlement']='active';$b['slug']='bob-blog';$b['domain']='bob-blog.dashless.blog';$b['paid_at']=time();$identity->save($b);
$pc=new ProvisionCloud();$pa=new ProvisionAgent();$provisioner=new Provisioner($store,$identity,$pc,$pa);
$provisioner->tick($bob);check($identity->account($bob)['state']==='provision_error' && $pc->creates===1,'uncertain creation persists recovery state');
$provisioner->tick($bob);check($identity->account($bob)['site_id']===456 && $pc->creates===1,'creation recovered without duplicate site');
$pc->tasks['task_0']=['complete'=>true,'meta'=>['success_count'=>1,'failure_count'=>0]];$provisioner->tick($bob);
$pc->tasks['task_1']=['complete'=>true,'meta'=>['success_count'=>1,'failure_count'=>0]];$provisioner->tick($bob);
check($identity->account($bob)['state']==='ready','provisioning requires capabilities, build and public verification');
check(!isset($identity->account($bob)['admin_secret']),'bootstrap administrator secret discarded after ready');
// Other products in a shared Stripe account must not poison the retry queue.
$stripe->subs['sub_unrelated']=['id'=>'sub_unrelated','metadata'=>[]];
$store->add('stripe_event','evt_unrelated',['subscription'=>'sub_unrelated'],0,'pending');
$billing->process('evt_unrelated');check($store->get('stripe_event','evt_unrelated')['status']==='ignored','unmapped Stripe subscriptions are ignored without endless retries');
// A historical invoice event cannot restore a currently canceled subscription.
$sub['status']='canceled';$stripe->subs[$sub['id']]=$sub;$event['id']='evt_late_paid';$billing->receive(json_encode($event),'test');$billing->process('evt_late_paid');check($identity->account($alice)['entitlement']==='expired','late paid event obeys current canceled subscription');
$a=$identity->account($alice);$a['state']='suspended';$identity->save($a);putenv('DASHLESS_TEST_CHECKOUT=1');$billing->recover($alice);$a=$identity->account($alice);check(!empty($a['recovery_checkout']) && $a['site_id']===123,'recovery checkout preserves existing site');
$billing->reconcile($sub);check(empty($identity->account($alice)['stripe_subscription']),'old subscription event cannot overwrite recovery');
$sub['id']='sub_recovery';$sub['status']='active';$sub['metadata']['dashless_attempt']=$a['checkout_attempt'];$billing->reconcile($sub);check($identity->account($alice)['entitlement']==='active','new paid recovery reactivates entitlement');
$b=$identity->account($bob);$b['state']='provision_error';$b['paid_at']=time()-2*DAY_IN_SECONDS;$b['first_invoice']='in_failed';$b['stripe_subscription']='sub_failed';$b['provision_failure_verified']=time();$identity->save($b);
$billing->refundFailedProvisioning($bob);$billing->refundFailedProvisioning($bob);check(count($stripe->refunds)===1 && count($stripe->cancels)===1 && $identity->account($bob)['state']==='refunded','initial failed provisioning cancels and refunds idempotently');
check($identity->account($bob)['delete_after']>time(),'refunded provisioning retains bounded cleanup deadline');
putenv('DASHLESS_TEST_CHECKOUT=0');
echo "\n$passed integration checks passed. External services used fixtures; no payments or sites created.\n";
