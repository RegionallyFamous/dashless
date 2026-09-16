<?php
/** Run only against an expendable local WordPress: php hosted/tests/integration.php /path/to/wordpress */
ob_start();
$root=$argv[1]??'';
if(!is_file($root.'/wp-load.php'))throw new RuntimeException('Pass a disposable WordPress installation.');
putenv('DASHLESS_TEST_CHECKOUT=1');putenv('DASHLESS_STRIPE_SECRET_KEY=sk_test_fixture');putenv('DASHLESS_STRIPE_PRICE_ID=price_fixture');putenv('DASHLESS_OAUTH_CLIENT_ID=chatgpt-fixture');putenv('DASHLESS_OAUTH_REDIRECT_URI=https://chatgpt.com/connector_platform_oauth_redirect');putenv('DASHLESS_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
$key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$private);$public=openssl_pkey_get_details($key)['key'];
putenv('DASHLESS_OAUTH_PRIVATE_KEY='.$private);putenv('DASHLESS_OAUTH_PUBLIC_KEY='.$public);
require $root.'/wp-load.php';
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local environment required.');
error_reporting(E_ALL & ~E_DEPRECATED);
use Dashless\Hub\{Store,Identity,Crypto,Config,OAuth,Billing,StripeGateway,Cloud,Agent,Jobs,Mcp,Failure,Provisioner};
$store=new Store();$store->install();global $wpdb;$wpdb->query('DELETE FROM '.$store->table());
$passed=0;
function check($yes,$description){global $passed;if(!$yes)throw new RuntimeException('FAIL: '.$description);$passed++;echo "PASS $description\n";}
function rejects(callable $fn,$description){try{$fn();}catch(Throwable $e){check(true,$description);return;}check(false,$description);}
$identity=new Identity($store);$mail=[];
add_filter('pre_wp_mail',function($return,$args)use(&$mail){$mail[]=$args;return true;},10,2);
$identity->request('alice@example.test','127.0.0.1','/oauth/authorize?state=returntest');
check(str_contains($mail[0]['message'],'return='),'magic email retains OAuth continuation');
preg_match('/token=([a-f0-9]{64})/',$mail[0]['message'],$match);$token=$match[1];
check($store->get('magic',hash('sha256',$token))['status']==='unused','email fetch does not consume link');
$alice=$identity->consume($token);rejects(fn()=>$identity->consume($token),'magic link cannot be replayed');
$identity->request('bob@example.test','127.0.0.2');preg_match('/token=([a-f0-9]{64})/',$mail[1]['message'],$match);$bob=$identity->consume($match[1]);
$expired=bin2hex(random_bytes(32));$store->add('magic',hash('sha256',$expired),['email'=>'expired@example.test'],0,'unused',time()-1);rejects(fn()=>$identity->consume($expired),'expired link rejected');
check($alice!==$bob,'separate accounts created');
rejects(fn()=>Identity::slug('support'),'platform names reserved');
check(Crypto::open(Crypto::seal('fixture-secret'))==='fixture-secret','encrypted credential roundtrip');
$lock=$store->lock('race');check($lock && !$store->lock('race'),'database lease excludes concurrent operation');$store->unlock('race','wrong');check(!$store->lock('race'),'wrong lease cannot unlock');$store->unlock('race',$lock);

$oauth=new OAuth($store);$verifier=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
$query=['response_type'=>'code','client_id'=>'chatgpt-fixture','redirect_uri'=>getenv('DASHLESS_OAUTH_REDIRECT_URI'),'scope'=>'blog:read blog:write blog:publish','state'=>'test-state','resource'=>Config::resource(),'code_challenge'=>$challenge,'code_challenge_method'=>'S256'];
rejects(fn()=>$oauth->authorization(array_merge($query,['resource'=>'https://attacker.test/mcp'])),'OAuth wrong resource rejected');
rejects(fn()=>$oauth->authorization(array_merge($query,['code_challenge_method'=>'plain'])),'OAuth plain PKCE rejected');
$location=$oauth->approve($query,$alice,true)->getHeaderLine('Location');parse_str(parse_url($location,PHP_URL_QUERY),$redirect);
check($redirect['iss']===Config::origin() && $redirect['state']==='test-state','OAuth issuer and state returned');
$body=['grant_type'=>'authorization_code','client_id'=>'chatgpt-fixture','code'=>$redirect['code'],'redirect_uri'=>$query['redirect_uri'],'code_verifier'=>$verifier,'resource'=>Config::resource()];
$tokens=json_decode((string)$oauth->token($body)->getBody(),true);$auth=$oauth->authenticate('Bearer '.$tokens['access_token']);check($auth['owner']===$alice,'signed access token resolves account');
rejects(fn()=>$oauth->token($body),'authorization code replay rejected');
$refresh=['grant_type'=>'refresh_token','client_id'=>'chatgpt-fixture','refresh_token'=>$tokens['refresh_token'],'resource'=>Config::resource()];
$new=json_decode((string)$oauth->token($refresh)->getBody(),true);check(isset($new['access_token']),'refresh rotates token');rejects(fn()=>$oauth->token($refresh),'refresh token replay rejected');
$oauth->revoke(['client_id'=>'chatgpt-fixture','token'=>$new['refresh_token']]);rejects(fn()=>$oauth->authenticate('Bearer '.$new['access_token']),'revocation invalidates access chain');
$denied=$oauth->approve($query,$alice,false)->getHeaderLine('Location');parse_str(parse_url($denied,PHP_URL_QUERY),$deny);check(($deny['error']??'')==='access_denied' && ($deny['iss']??'')===Config::origin(),'OAuth denial includes issuer');

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
$a=$identity->account($alice);$a['state']='ready';$a['site_id']=123;$identity->save($a);
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
add_filter('pre_http_request',function($pre,$args,$url){if($url==='https://localhost:8874/site-fixture.zip')return ['headers'=>[],'body'=>'fixture-package','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];throw new RuntimeException('Unexpected external HTTP in fixture test: '.$url);},10,3);
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
