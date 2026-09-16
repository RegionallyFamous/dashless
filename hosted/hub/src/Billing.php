<?php
namespace Dashless\Hub;
final class Billing {
    public function __construct(private Store $store,private Identity $identity,private StripeGateway $stripe) {}
    public function checkout(int $owner,string $requested): array {
        if (!Config::checkoutAllowed()) throw new Failure('checkout_disabled','Subscriptions are not open yet.',503);
        $slug=Identity::slug($requested);
        return $this->store->locked('account:'.$owner,function()use($owner,$slug){
            $a=$this->identity->account($owner);
            if (!empty($a['stripe_subscription']) && !in_array($a['entitlement']??'',['expired','none'],true)) throw new Failure('already_subscribed','You already have a subscription. Manage it in billing.',409);
            if (!empty($a['site_id']) && empty($a['recovery_checkout'])) throw new Failure('recovery_required','Use account recovery to reactivate your existing blog.',409);
            if(!empty($a['recovery_checkout']) && $slug!==$a['slug'])throw new Failure('address_fixed','Recovery keeps your existing blog address.',409);
            $price=$this->stripe->price();
            if (($price['unit_amount']??0)!==999 || ($price['currency']??'')!=='usd' || ($price['recurring']['interval']??'')!=='month' || ($price['recurring']['interval_count']??1)!==1 || empty($price['active'])) throw new Failure('price_mismatch','The subscription price needs configuration.',503);
            if (!empty($a['checkout_session'])) {
                $session=$this->stripe->session($a['checkout_session']);
                if (($session['status']??'')==='open') {
                    if ($a['slug']!==$slug) throw new Failure('checkout_open','Finish or let the current checkout expire before changing your address.',409);
                    return ['url'=>$session['url']];
                }
                if (($session['status']??'')==='complete') throw new Failure('payment_processing','Your payment is being confirmed. Check setup status shortly.',409);
                // Only a remotely verified expired session releases its address.
                if (($session['status']??'')!=='expired') throw new Failure('checkout_uncertain','Checkout status needs reconciliation.',409);
                $this->store->locked('slug:'.$a['slug'],function()use($a,$owner){
                    $r=$this->store->get('reservation',$a['slug']);
                    if ($r && $r['owner']===$owner && $r['status']==='reserved') $this->store->remove('reservation',$a['slug']);
                });
                unset($a['checkout_session'],$a['checkout_attempt'],$a['checkout_expires']);
            }
            if (!empty($a['checkout_attempt']) && $a['slug']!==$slug) throw new Failure('checkout_uncertain','Resume checkout with your reserved address.',409);
            $this->store->locked('slug:'.$slug,function()use($slug,$owner){
                $r=$this->store->get('reservation',$slug);
                if ($r && $r['owner']!==$owner) throw new Failure('address_taken','That blog address is already reserved.',409);
                if (!$r) $this->store->add('reservation',$slug,['slug'=>$slug],$owner,'reserved');
            });
            $a['slug']=$slug; $a['domain']=$slug.'.'.Config::domain();
            if (empty($a['checkout_attempt'])) { $a['checkout_attempt']=wp_generate_uuid4();$a['checkout_expires']=time()+1800; }
            $a['state']=!empty($a['recovery_checkout'])?'suspended':'checkout';$this->identity->save($a);
            if (empty($a['stripe_customer'])) { $a['stripe_customer']=$this->stripe->customer($a,get_userdata($owner)->user_email);$this->identity->save($a); }
            // Retry the same attempt after an uncertain network response. Never silently start another.
            $session=$this->stripe->checkout($a,$a['checkout_attempt']);
            $a['checkout_session']=$session['id']; $this->identity->save($a);
            return ['url'=>$session['url']];
        });
    }
    public function recover(int $owner): array {
        $a=$this->refresh($owner);
        if(($a['entitlement']??'')!=='expired' || $a['state']!=='suspended' || ($a['delete_after']??0)<=time() || !empty($a['delete_sent']))throw new Failure('recovery_unavailable','This blog is not eligible for recovery.',409);
        // Still-collecting subscriptions are recovered through Portal, never duplicated.
        if(!in_array($a['subscription_status']??'',['canceled','incomplete_expired'],true) && empty($a['recovery_checkout']))return $this->portal($owner);
        $this->store->locked('account:'.$owner,function()use($owner){
            $a=$this->identity->account($owner);
            if(empty($a['recovery_checkout'])) {
                $a['retired_subscriptions'][]=$a['stripe_subscription'];
                unset($a['stripe_subscription'],$a['checkout_session'],$a['checkout_attempt'],$a['checkout_expires']);
                $a['recovery_checkout']=true;$this->identity->save($a);
            }
        });
        return $this->checkout($owner,$a['slug']);
    }
    public function portal(int $owner): array {
        $a=$this->identity->account($owner);
        if (empty($a['stripe_customer'])) throw new Failure('billing_missing','No billing account exists yet.',404);
        return ['url'=>$this->stripe->portal($a['stripe_customer'])];
    }
    public function receive(string $body,string $signature): string {
        try { $event=$this->stripe->event($body,$signature); }
        catch (\Throwable $e) { throw new Failure('invalid_signature','Invalid webhook signature.',400); }
        // Store only identifiers; no card, address, or unnecessary invoice data.
        $object=$event['data']['object']??[];
        $sub=$object['subscription']??$object['parent']['subscription_details']['subscription']??null;
        if (str_starts_with($event['type'],'customer.subscription.')) $sub=$object['id'];
        if (is_array($sub)) $sub=$sub['id'];
        $supported=in_array($event['type'],['checkout.session.completed','checkout.session.async_payment_succeeded','customer.subscription.created','customer.subscription.updated','customer.subscription.deleted','invoice.paid','invoice.payment_failed','invoice.payment_action_required'],true);
        $this->store->add('stripe_event',$event['id'],['event_id'=>$event['id'],'subscription'=>$sub,'type'=>$event['type'],'created'=>$event['created']],0,$supported&&$sub?'pending':'ignored');
        return $event['id'];
    }
    public function process(string $id): void {
        $this->store->locked('stripe-event:'.$id,function()use($id){
            $r=$this->store->get('stripe_event',$id);
            if (!$r || $r['status']!=='pending') return;
            $sub=$this->stripe->subscription($r['data']['subscription']);
            $this->reconcile($sub);
            $this->store->put('stripe_event',$id,$r['data'],0,'done');
        });
    }
    public function refresh(int $owner): array {
        $a=$this->identity->account($owner);
        if(empty($a['stripe_subscription']) && !empty($a['checkout_session'])) {
            $session=$this->stripe->session($a['checkout_session']);
            if(($session['status']??'')==='complete') {
                $id=$session['subscription']??null;if(is_array($id))$id=$id['id']??null;
                if(!$id)throw new Failure('checkout_uncertain','Completed checkout needs billing reconciliation.',409);
                $this->reconcile($this->stripe->subscription($id));
            }
        }
        if (!empty($a['stripe_subscription'])) $this->reconcile($this->stripe->subscription($a['stripe_subscription']));
        return $this->identity->account($owner);
    }
    public function reconcile(array $sub): void {
        $owner=(int)($sub['metadata']['dashless_user']??0);
        if (!$owner) throw new Failure('subscription_unmapped','Subscription is not mapped to a Dashless account.',409);
        $this->store->locked('account:'.$owner,function()use($sub,$owner){
            $a=$this->identity->account($owner);
            if(in_array($sub['id'],$a['retired_subscriptions']??[],true))return;
            $customer=is_array($sub['customer'])?$sub['customer']['id']:$sub['customer'];
            if (($a['stripe_customer']??'')!==$customer || ($a['slug']??'')!==($sub['metadata']['dashless_slug']??'')) throw new Failure('subscription_mismatch','Subscription ownership does not match.',403);
            if (!empty($a['stripe_subscription']) && $a['stripe_subscription']!==$sub['id']) throw new Failure('duplicate_subscription','A second subscription needs operator review.',409);
            if (empty($a['stripe_subscription']) && ($sub['metadata']['dashless_attempt']??'')!==($a['checkout_attempt']??'')) throw new Failure('subscription_mismatch','Subscription checkout does not match.',403);
            $items=$sub['items']['data']??[];$item=$items[0]??[];
            if(count($items)!==1 || ($item['price']['id']??'')!==Config::required('stripe_price_id') || ($item['quantity']??0)!==1) throw new Failure('plan_mismatch','Subscription plan requires operator review.',409);
            $a['stripe_subscription']=$sub['id'];$a['subscription_status']=$sub['status'];$a['billing_checked_at']=time();
            $a['cancel_at_period_end']=(bool)($sub['cancel_at_period_end']??false);
            $end=(int)($item['current_period_end']??$sub['current_period_end']??0);
            $invoice=$sub['latest_invoice']??[];
            $paid=is_array($invoice) && ($invoice['status']??'')==='paid';
            if ($sub['status']==='active' && $paid && $end>time()) {
                $a['entitlement']='active';$a['paid_through']=$end;unset($a['recovery_checkout']);
                unset($a['past_due_since'],$a['suspended_at'],$a['delete_after']);
                if (empty($a['first_invoice'])) $a['first_invoice']=$invoice['id'];
                if (empty($a['paid_at'])) $a['paid_at']=time();
                if (in_array($a['state'],['checkout','new'],true)) $a['state']='provisioning';
                $r=$this->store->get('reservation',$a['slug']);
                if (!$r || $r['owner']!==$owner) throw new Failure('address_conflict','Your paid address needs operator review.',409);
                $this->store->put('reservation',$a['slug'],['slug'=>$a['slug']],$owner,'committed');
            } elseif ($sub['status']==='past_due' && !empty($a['paid_through'])) {
                // Base the grace period on the unpaid period boundary, not delivery time of a webhook.
                $a['past_due_since']=$a['past_due_since']??(int)$a['paid_through'];
                $a['entitlement']=time()<$a['past_due_since']+7*DAY_IN_SECONDS?'grace':'expired';
            } elseif (in_array($sub['status'],['canceled','unpaid','incomplete_expired','paused'],true)) {
                $a['entitlement']='expired';
            } elseif (empty($a['paid_at'])) { $a['entitlement']='pending'; }
            // A delayed/incomplete renewal never extends a paid entitlement indefinitely.
            elseif (($a['paid_through']??0)<=time()) { $a['entitlement']='expired'; }
            if (($a['entitlement']??'')==='expired') {
                $a['suspended_at']=$a['suspended_at']??time();$a['delete_after']=$a['suspended_at']+30*DAY_IN_SECONDS;
            }
            $this->identity->save($a);
        });
    }
    public function refundFailedProvisioning(int $owner): void {
        $this->store->locked('account:'.$owner,function()use($owner){
            $a=$this->identity->account($owner);
            if (empty($a['provision_failure_verified']) || $a['state']==='ready' || time()<($a['paid_at']??time())+DAY_IN_SECONDS || !empty($a['refund_id'])) return;
            // Caller must reconcile the actual site first. Persist phase so crashes resume cancellation/refund.
            $a['state']='refunding';$this->identity->save($a);
            $this->stripe->cancel($a['stripe_subscription'],'dashless-failed-cancel-'.$a['stripe_subscription']);
            $intent=$this->stripe->invoicePaymentIntent($a['first_invoice']);
            $a['refund_id']=$this->stripe->refund($intent,'dashless-failed-refund-'.$a['first_invoice']);
            $a['entitlement']='expired';$a['state']='refunded';$a['suspended_at']=time();$a['delete_after']=time()+30*DAY_IN_SECONDS;$this->identity->save($a);
            $this->store->audit($owner,'initial_payment_refunded');
        });
    }
}
