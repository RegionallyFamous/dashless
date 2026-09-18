<?php
namespace Dashless\Hub;

/** Delivers Auth0's messages through the existing host. Never creates or verifies login codes. */
final class AuthMail {
    public const LIMIT = 131072;
    private const TYPES = ['verify_email','verify_email_by_code','reset_email','reset_email_by_code','welcome_email','verification_code','mfa_oob_code','enrollment_email','blocked_account','stolen_credentials','try_provider_configuration_email','organization_invitation'];

    public function __construct(private Store $store) {}

    public function deliver(string $raw, string $timestamp, string $signature): array {
        $secret=Config::required('auth0_email_secret');
        if(!preg_match('/^[a-f0-9]{64}$/D',$secret))throw new Failure('email_configuration','Sign-in email is being connected.',503);
        if(strlen($raw)>self::LIMIT)throw new Failure('request_too_large','Message is too large.',413);
        if(!preg_match('/^[0-9]{10}$/D',$timestamp) || abs(time()-(int)$timestamp)>300
            || !preg_match('/^[a-f0-9]{64}$/D',$signature)
            || !hash_equals(hash_hmac('sha256',$timestamp."\n".$raw,$secret),$signature)) {
            throw new Failure('email_unauthorized','This request is not authorized.',401);
        }
        $data=json_decode($raw,true,16);
        $keys=['tenant','client_id','message_type','to','subject','html','text'];
        if(!is_array($data) || array_keys($data)!==$keys)throw new Failure('email_invalid','Invalid message.',400);
        foreach($keys as $key)if(!is_string($data[$key]))throw new Failure('email_invalid','Invalid message.',400);
        $audience=Config::required('auth0_management_audience');
        if(!preg_match('~^https://[a-z0-9.-]+/api/v2/$~D',$audience))throw new Failure('email_configuration','Sign-in email is being connected.',503);
        $tenant=explode('.',(string)parse_url($audience,PHP_URL_HOST))[0];
        $clients=array_filter([(string)Config::get('auth0_client_id'),(string)Config::get('auth0_chatgpt_client_id')]);
        $test=$data['message_type']==='try_provider_configuration_email';
        if($data['tenant']!==$tenant || ($test
            ? strtolower($data['to'])!==strtolower((string)get_option('admin_email'))
            : !in_array($data['client_id'],$clients,true)))throw new Failure('email_unauthorized','This request is not authorized.',403);
        if(!in_array($data['message_type'],self::TYPES,true) || !is_email($data['to'])
            || strlen($data['to'])>254 || preg_match('/[\x00-\x20\x7f,;]/',$data['to'])
            || $data['subject']==='' || strlen($data['subject'])>300 || preg_match('/[\x00-\x1f\x7f]/',$data['subject'])
            || ($data['html']==='' && $data['text']===''))throw new Failure('email_invalid','Invalid message.',400);
        $host=(string)parse_url(Config::origin(),PHP_URL_HOST);
        $from=(string)Config::get('auth0_email_from','signin@'.$host);
        if(!is_email($from) || strcasecmp(substr(strrchr($from,'@'),1),$host)!==0)throw new Failure('email_configuration','Sign-in email is being connected.',503);

        // Only a keyed digest and acceptance status are saved, never the email address, body or code.
        $id=hash_hmac('sha256',$raw,$secret);
        return $this->store->locked('auth0-mail:'.$id,function()use($id,$data,$from,$secret){
            $prior=$this->store->get('auth0_mail',$id);
            if($prior && $prior['status']==='accepted' && $prior['expires']>time())return ['accepted'=>true];
            $this->store->rate('auth0-mail:recipient:'.hash_hmac('sha256',strtolower($data['to']),$secret),10,600);
            $this->store->rate('auth0-mail:global',60,60);
            $html=$data['html']!=='';
            $headers=['From: Dashless <'.$from.'>','Content-Type: '.($html?'text/html':'text/plain').'; charset=UTF-8'];
            if(!wp_mail($data['to'],$data['subject'],$html?$data['html']:$data['text'],$headers))throw new Failure('email_unavailable','Sign-in email could not be sent. Please try again.',503);
            $this->store->put('auth0_mail',$id,[],0,'accepted',time()+DAY_IN_SECONDS);
            // Acceptance by the mail transport is not proof that the message reached an inbox.
            return ['accepted'=>true];
        });
    }
}
