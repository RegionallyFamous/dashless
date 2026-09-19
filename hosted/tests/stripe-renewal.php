<?php
/** Real test-clock renewal/failed-payment/recovery checks; disposable local WP only. */
ob_start();$root=$argv[1]??'';$config=$argv[2]??'';
if(!$root || !is_file($root.'/wp-load.php') || !$config || !is_file($config)){fwrite(STDERR,"Usage: php hosted/tests/stripe-renewal.php /path/to/local/wp /protected/stripe-sandbox.json\n");exit(2);}
$c=json_decode(file_get_contents($config),true,512,JSON_THROW_ON_ERROR);
if(!str_starts_with($c['secret_key']??'','sk_test_'))throw new RuntimeException('Sandbox required');
putenv('DASHLESS_STRIPE_SECRET_KEY='.$c['secret_key']);putenv('DASHLESS_STRIPE_PRICE_ID='.$c['price_id']);$_SERVER['REQUEST_METHOD']='GET';require $root.'/wp-load.php';if(wp_get_environment_type()!=='local')throw new RuntimeException('Local only');
use Dashless\Hub\{Store,Identity,Billing,StripeGateway};
$client=new Stripe\StripeClient(['api_key'=>$c['secret_key'],'stripe_version'=>Stripe\Util\ApiVersion::CURRENT]);$store=new Store();$identity=new Identity($store);$gateway=new StripeGateway();$billing=new Billing($store,$identity,$gateway);$owner=null;$clock=null;$slug='renewal-'.gmdate('YmdHis');$checks=[];
function ok($condition,$label){global $checks;if(!$condition)throw new RuntimeException('FAIL '.$label);$checks[]=$label;echo "PASS $label\n";}
function advance($time){global $client,$clock;$client->testHelpers->testClocks->advance($clock->id,['frozen_time'=>$time]);for($n=0;$n<40;$n++){usleep(1500000);$state=$client->testHelpers->testClocks->retrieve($clock->id,[]);if($state->status==='ready')return;}throw new RuntimeException('Clock still advancing');}
try{
 $clock=$client->testHelpers->testClocks->create(['frozen_time'=>time(),'name'=>'Dashless renewal acceptance']);
 $owner=wp_insert_user(['user_login'=>$slug,'user_email'=>$slug.'@example.test','user_pass'=>wp_generate_password(40),'role'=>'subscriber']);if(is_wp_error($owner))throw new RuntimeException('Fixture user failed');
 $customer=$client->customers->create(['email'=>$slug.'@example.test','test_clock'=>$clock->id]);
 $pm=$client->paymentMethods->attach('pm_card_visa',['customer'=>$customer->id]);
 $client->customers->update($customer->id,['invoice_settings'=>['default_payment_method'=>$pm->id]]);
 $identity->save(['user_id'=>$owner,'slug'=>$slug,'domain'=>$slug.'.dashless.blog','state'=>'checkout','entitlement'=>'none','checkout_attempt'=>$slug,'stripe_customer'=>$customer->id]);$store->add('reservation',$slug,['slug'=>$slug],$owner,'reserved');
 $sub=$client->subscriptions->create(['customer'=>$customer->id,'items'=>[['price'=>$c['price_id']]],'metadata'=>['dashless_user'=>(string)$owner,'dashless_slug'=>$slug,'dashless_attempt'=>$slug],'expand'=>['latest_invoice']]);
 $billing->reconcile($sub->toArray());$initialEnd=$identity->account($owner)['paid_through'];ok($identity->account($owner)['entitlement']==='active','initial clock-backed payment grants access');
 advance($initialEnd+60);advance($initialEnd+3700);
 $sub=$gateway->subscription($sub->id);$billing->reconcile($sub);$renewedEnd=$identity->account($owner)['paid_through'];ok($renewedEnd>$initialEnd && $sub['latest_invoice']['status']==='paid','real automatic renewal extends paid-through date');
 $bad=$client->paymentMethods->attach('pm_card_chargeCustomerFail',['customer'=>$customer->id]);$client->customers->update($customer->id,['invoice_settings'=>['default_payment_method'=>$bad->id]]);
 advance($renewedEnd+60);advance($renewedEnd+3700);
 $sub=$gateway->subscription($sub['id']);$billing->reconcile($sub);$a=$identity->account($owner);
 ok($sub['status']==='past_due' && $a['entitlement']==='grace' && $a['paid_through']===$renewedEnd,'real failed renewal starts grace without extending paid access');
 $client->customers->update($customer->id,['invoice_settings'=>['default_payment_method'=>$pm->id]]);
 $client->invoices->pay($sub['latest_invoice']['id'],['payment_method'=>$pm->id]);$sub=$gateway->subscription($sub['id']);$billing->reconcile($sub);
 ok($identity->account($owner)['entitlement']==='active' && !isset($identity->account($owner)['past_due_since']),'paying failed invoice restores active entitlement');
 $sub=$client->subscriptions->update($sub['id'],['cancel_at_period_end'=>true,'expand'=>['latest_invoice']])->toArray();$billing->reconcile($sub);$end=$identity->account($owner)['paid_through'];advance($end+60);$sub=$gateway->subscription($sub['id']);$billing->reconcile($sub);
 ok($sub['status']==='canceled' && $identity->account($owner)['entitlement']==='expired','real period-end cancellation expires entitlement');
 // Stripe itself reports whether the registered webhook endpoint acknowledged events.
 $events=$client->events->all(['type'=>'customer.subscription.updated','limit'=>20])->toArray();$delivered=false;
 foreach($events['data'] as $event)if(($event['data']['object']['id']??'')===$sub['id'] && $event['pending_webhooks']===0)$delivered=true;
 ok($delivered,'Stripe reports subscription events acknowledged by registered webhook');
 echo json_encode(['passed'=>count($checks),'checks'=>$checks,'limits'=>'Stripe clock is real; local Hub reconciliation is invoked directly. No deployed customer site or cleanup job exercised.'],JSON_PRETTY_PRINT)."\n";
}finally{
 if($clock)$client->testHelpers->testClocks->delete($clock->id,[]);
 if(is_int($owner)){$store->remove('reservation',$slug);$store->remove('account',(string)$owner);require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($owner);}
}
