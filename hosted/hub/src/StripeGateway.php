<?php
namespace Dashless\Hub;
class StripeGateway {
    private function client(): \Stripe\StripeClient {
        return new \Stripe\StripeClient(['api_key'=>Config::required('stripe_secret_key'),'stripe_version'=>\Stripe\Util\ApiVersion::CURRENT]);
    }
    public function price(): array { return $this->client()->prices->retrieve(Config::required('stripe_price_id'),[])->toArray(); }
    public function customer(array $account,string $email): string {
        return $this->client()->customers->create(['email'=>$email,'metadata'=>['dashless_user'=>(string)$account['user_id']]],['idempotency_key'=>'dashless-customer-'.$account['user_id']])->id;
    }
    public function checkout(array $account,string $attempt): array {
        return $this->client()->checkout->sessions->create([
            'mode'=>'subscription','customer'=>$account['stripe_customer'],
            'client_reference_id'=>(string)$account['user_id'],
            'line_items'=>[['price'=>Config::required('stripe_price_id'),'quantity'=>1]],
            'payment_method_types'=>['card'],
            'success_url'=>Config::origin().'/account/?checkout=complete',
            'cancel_url'=>Config::origin().'/account/?checkout=canceled',
            'expires_at'=>$account['checkout_expires'],
            'subscription_data'=>['metadata'=>['dashless_user'=>(string)$account['user_id'],'dashless_slug'=>$account['slug'],'dashless_attempt'=>$attempt]],
            'metadata'=>['dashless_user'=>(string)$account['user_id'],'dashless_attempt'=>$attempt],
        ],['idempotency_key'=>'dashless-checkout-'.$attempt])->toArray();
    }
    public function session(string $id): array { return $this->client()->checkout->sessions->retrieve($id,[])->toArray(); }
    public function subscription(string $id): array { return $this->client()->subscriptions->retrieve($id,['expand'=>['latest_invoice']])->toArray(); }
    public function portal(string $customer): string { return $this->client()->billingPortal->sessions->create(['customer'=>$customer,'return_url'=>Config::origin().'/account/'])->url; }
    public function event(string $raw,string $signature): array { return \Stripe\Webhook::constructEvent($raw,$signature,Config::required('stripe_webhook_secret'),300)->toArray(); }
    public function cancel(string $subscription,string $key): void { $this->client()->subscriptions->cancel($subscription,[],['idempotency_key'=>$key]); }
    public function refund(string $paymentIntent,string $key): string { return $this->client()->refunds->create(['payment_intent'=>$paymentIntent,'reason'=>'requested_by_customer'],['idempotency_key'=>$key])->id; }
    public function invoicePaymentIntent(string $invoice): string {
        $payments=$this->client()->invoicePayments->all(['invoice'=>$invoice,'status'=>'paid','limit'=>100])->toArray();
        foreach($payments['data']??[] as $p) if (($p['payment']['type']??'')==='payment_intent') return (string)$p['payment']['payment_intent'];
        throw new Failure('refund_review_required','Payment needs operator review before a refund.',409);
    }
}
