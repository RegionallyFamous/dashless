<?php
/** Real Stripe sandbox smoke. Uses only disposable LOCAL WordPress and sk_test credentials.
 * php hosted/tests/stripe-sandbox.php /path/to/local/wp /protected/stripe-sandbox.json
 * Does not provision sites or change live Stripe configuration.
 */
ob_start();
$root=$argv[1]??'';$file=$argv[2]??'';
$c=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
if(!str_starts_with($c['secret_key']??'','sk_test_'))throw new RuntimeException('Sandbox secret required.');
putenv('DASHLESS_STRIPE_SECRET_KEY='.$c['secret_key']);putenv('DASHLESS_STRIPE_PRICE_ID='.$c['price_id']);putenv('DASHLESS_STRIPE_PORTAL_CONFIGURATION_ID='.$c['portal_configuration_id']);putenv('DASHLESS_TEST_CHECKOUT=1');
$_SERVER['REQUEST_METHOD']='GET';require $root.'/wp-load.php';
if(wp_get_environment_type()!=='local')throw new RuntimeException('Disposable local WordPress required.');
use Dashless\Hub\{Store,Identity,Billing,StripeGateway};
$client=new Stripe\StripeClient(['api_key'=>$c['secret_key'],'stripe_version'=>Stripe\Util\ApiVersion::CURRENT]);
$store=new Store();$identity=new Identity($store);$gateway=new StripeGateway();$billing=new Billing($store,$identity,$gateway);
$run=gmdate('YmdHis');$checks=[];$subscriptions=[];$customers=[];$sessions=[];$owners=[];
function verify($condition,$label){global $checks;if(!$condition)throw new RuntimeException('FAIL '.$label);$checks[]=$label;echo "PASS $label\n";}
try {
 $price=$gateway->price();verify(!$price['livemode'] && $price['unit_amount']===999 && $price['currency']==='usd' && $price['recurring']['interval']==='month','real sandbox price is USD 9.99 monthly');
 $portal=$client->billingPortal->configurations->retrieve($c['portal_configuration_id'],[])->toArray();
 verify($portal['active'] && $portal['features']['subscription_cancel']['mode']==='at_period_end' && !$portal['features']['subscription_update']['enabled'] && $portal['features']['payment_method_update']['enabled'] && $portal['features']['invoice_history']['enabled'],'real Portal permits payment updates/invoices and period-end cancellation only');
 $webhook=$client->webhookEndpoints->retrieve($c['webhook_id'],[])->toArray();verify($webhook['status']==='enabled' && $webhook['api_version']===Stripe\Util\ApiVersion::CURRENT,'real webhook enabled with SDK API version');
 $owner=wp_insert_user(['user_login'=>'billing-'.$run,'user_email'=>'billing-'.$run.'@example.test','user_pass'=>wp_generate_password(40),'role'=>'subscriber']);if(is_wp_error($owner))throw new RuntimeException('Fixture creation failed');$owners[]=$owner;
 $identity->save(['user_id'=>$owner,'state'=>'new','entitlement'=>'none']);
 $checkout=$billing->checkout($owner,'billing-'.$run);$a=$identity->account($owner);$customers[]=$a['stripe_customer'];$sessions[]=$a['checkout_session'];
 verify(str_starts_with($checkout['url'],'https://checkout.stripe.com/'),'real hosted Checkout session created');
 verify($billing->checkout($owner,$a['slug'])['url']===$checkout['url'],'repeat checkout reuses the same real session');
 verify($identity->account($owner)['entitlement']==='none','opening Checkout does not grant paid access');
 $session=$gateway->session($a['checkout_session']);verify($session['mode']==='subscription' && !$session['livemode'],'Checkout is a sandbox subscription');
 // Expire the incomplete Checkout before exercising a direct real test-card subscription.
 $client->checkout->sessions->expire($session['id'],[]);
 $pm=$client->paymentMethods->attach('pm_card_visa',['customer'=>$a['stripe_customer']]);
 $client->customers->update($a['stripe_customer'],['invoice_settings'=>['default_payment_method'=>$pm->id]]);
 $sub=$client->subscriptions->create(['customer'=>$a['stripe_customer'],'items'=>[['price'=>$c['price_id']]],'default_payment_method'=>$pm->id,'metadata'=>['dashless_user'=>(string)$owner,'dashless_slug'=>$a['slug'],'dashless_attempt'=>$a['checkout_attempt']],'expand'=>['latest_invoice']]);$subscriptions[]=$sub->id;
 $billing->reconcile($sub->toArray());$a=$identity->account($owner);
 verify($a['entitlement']==='active' && $a['state']==='provisioning' && !empty($a['first_invoice']),'real test-card payment grants entitlement and queues provisioning');
 $portalUrl=$billing->portal($owner)['url'];verify(str_starts_with($portalUrl,'https://billing.stripe.com/'),'real Customer Portal session opens');
 $sub=$client->subscriptions->update($sub->id,['cancel_at_period_end'=>true,'expand'=>['latest_invoice']]);$billing->reconcile($sub->toArray());$a=$identity->account($owner);
 verify($a['cancel_at_period_end'] && $a['entitlement']==='active','period-end cancellation keeps paid access until period ends');
 $sub=$client->subscriptions->update($sub->id,['cancel_at_period_end'=>false,'expand'=>['latest_invoice']]);$billing->reconcile($sub->toArray());verify(!$identity->account($owner)['cancel_at_period_end'],'real scheduled cancellation can be undone');
 $intent=$gateway->invoicePaymentIntent($a['first_invoice']);verify(str_starts_with($intent,'pi_'),'paid invoice payment intent resolves through current Stripe API');
 // Exercise the actual failed-provisioning cancellation and refund against test money.
 $a=$identity->account($owner);$a['state']='provision_error';$a['paid_at']=time()-2*DAY_IN_SECONDS;$a['provision_failure_verified']=time();$identity->save($a);
 $billing->refundFailedProvisioning($owner);$a=$identity->account($owner);$billing->refundFailedProvisioning($owner);
 verify($a['state']==='refunded' && $client->refunds->retrieve($a['refund_id'],[])->status==='succeeded','failed-provisioning refund succeeds with real sandbox payment');
 verify($client->subscriptions->retrieve($sub->id,[])->status==='canceled','failed-provisioning subscription is canceled');
 $billing->reconcile($gateway->subscription($sub->id));verify($identity->account($owner)['entitlement']==='expired','late reconciliation cannot reactivate canceled subscription');
 echo json_encode(['passed'=>count($checks),'checks'=>$checks,'mode'=>'real Stripe sandbox APIs, isolated local Hub; no deployed provisioner or browser Checkout completion'],JSON_PRETTY_PRINT)."\n";
} finally {
 foreach($subscriptions as $id){try{$sub=$client->subscriptions->retrieve($id,[]);if($sub->status!=='canceled')$client->subscriptions->cancel($id,[]);}catch(Throwable $e){echo "Cleanup subscription needs review: $id\n";}}
 foreach($sessions as $id){try{$s=$client->checkout->sessions->retrieve($id,[]);if($s->status==='open')$client->checkout->sessions->expire($id,[]);}catch(Throwable $e){}}
 foreach($customers as $id){try{$client->customers->delete($id,[]);}catch(Throwable $e){echo "Cleanup customer needs review: $id\n";}}
 foreach($owners as $owner){$a=$identity->account($owner);if(!empty($a['slug']))$store->remove('reservation',$a['slug']);$store->remove('account',(string)$owner);require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($owner);}
}
