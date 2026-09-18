<?php
namespace Dashless\Hub;
final class Identity {
    public function __construct(private Store $store) {}
    public static function verified(int $owner): bool {
        if($owner<=0 || !self::configured())return false;
        return (bool)get_user_meta($owner,'dashless_auth0_sub',true)
            && (string)get_user_meta($owner,'dashless_auth0_issuer',true)===Config::auth0Issuer().'/'
            && get_user_meta($owner,'dashless_email_verified',true)==='1';
    }
    public static function configured(): bool {
        try {Config::auth0Issuer();return (bool)Config::get('auth0_client_id') && (bool)Config::get('auth0_client_secret');}
        catch(Failure $e){return false;}
    }
    public static function callback(): string { return Config::origin().'/auth/callback'; }
    public static function subjectKey(string $subject): string { return hash('sha256',Config::auth0Issuer().'/'."\0".$subject); }
    public function begin(string $return,string $browser): string {
        if(!self::configured())throw new Failure('signin_configuration','Sign-in is temporarily unavailable. Please try again later.',503);
        if(!preg_match('/^[a-f0-9]{64}$/D',$browser))throw new Failure('signin_invalid','Please start sign-in again.',400);
        $this->store->rate('auth0-start:'.($_SERVER['REMOTE_ADDR']??'unknown'),20,900);
        $state=bin2hex(random_bytes(32));$nonce=bin2hex(random_bytes(32));$verifier=bin2hex(random_bytes(32));
        $this->store->add('auth0_state',hash('sha256',$state),['browser'=>hash('sha256',$browser),'return'=>self::returnPath($return),'nonce'=>$nonce,'verifier'=>$verifier],0,'unused',time()+600);
        return Config::auth0Issuer().'/authorize?'.http_build_query(['client_id'=>Config::required('auth0_client_id'),'redirect_uri'=>self::callback(),'response_type'=>'code','response_mode'=>'query','scope'=>'openid profile email','state'=>$state,'nonce'=>$nonce,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256']);
    }
    public function complete(string $state,string $browser,string $code,string $error=''): array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$state) || !preg_match('/^[a-f0-9]{64}$/D',$browser))throw new Failure('signin_invalid','Please start sign-in again.',403);
        $id=hash('sha256',$state);$row=$this->store->get('auth0_state',$id);
        if(!$row || !hash_equals($row['data']['browser'],hash('sha256',$browser)))throw new Failure('signin_invalid','Please finish sign-in in the browser where you started.',403);
        $row=$this->store->consume('auth0_state',$id);
        if(!$row)throw new Failure('signin_expired','This sign-in has expired or was already used. Please start again.',403);
        if($error!=='')throw new Failure('signin_canceled','Sign-in was canceled. You can try again.',400);
        if($code==='' || strlen($code)>4096)throw new Failure('signin_invalid','Please start sign-in again.',400);
        $tokens=Auth0::request('/oauth/token',['grant_type'=>'authorization_code','client_id'=>Config::required('auth0_client_id'),'client_secret'=>Config::required('auth0_client_secret'),'code'=>$code,'redirect_uri'=>self::callback(),'code_verifier'=>$row['data']['verifier']]);
        $claims=Auth0::claims((string)($tokens['id_token']??''),Config::required('auth0_client_id'),$row['data']['nonce']);
        if(defined('COOKIE_DOMAIN') && COOKIE_DOMAIN)throw new Failure('cookie_configuration','Sign-in is temporarily unavailable.',503);
        $owner=$this->connectAuth0($claims['sub'],(string)($claims['email']??''),($claims['email_verified']??null)===true,(string)($claims['name']??''));
        wp_set_current_user($owner);wp_set_auth_cookie($owner,true,is_ssl());
        $this->store->audit($owner,'signed_in_auth0');
        return ['user_id'=>$owner,'return'=>$row['data']['return']];
    }
    public function connectAuth0(string $subject,string $email,bool $verified,string $name=''): int {
        $email=strtolower(trim($email));
        if(!preg_match('/^[^\\x00-\\x20\\x7f]{1,255}$/D',$subject) || !is_email($email) || !$verified)throw new Failure('email_unverified','Please sign in with a verified email address.',403);
        $key=self::subjectKey($subject);
        return $this->store->locked('identity:auth0:'.$key,function()use($key,$subject,$email,$name){
            return $this->store->locked('identity:email:'.hash('sha256',$email),function()use($key,$subject,$email,$name){
            $mapping=$this->store->get('auth0_identity',$key);
            if($mapping) {
                $id=$mapping['owner'];$user=get_user_by('id',$id);
                if(!$user || !self::verified($id) || (string)get_user_meta($id,'dashless_auth0_sub',true)!==$subject)throw new Failure('identity_mismatch','Contact support to restore your account connection.',409);
                if($user->user_email!==$email && !email_exists($email))wp_update_user(['ID'=>$id,'user_email'=>$email]);
            } else {
                // Never attach a new provider identity to an existing blog just because emails match.
                if($existing=email_exists($email)) {
                    $this->store->put('auth0_pending',$key,['email'=>$email,'subject'=>$subject,'issuer'=>Config::auth0Issuer().'/'],(int)$existing,'pending',time()+3600);
                    throw new Failure('account_link_required','Your blog is safe. Contact support to connect your new sign-in to your existing account.',409);
                }
                if(get_option('dashless_hub_signup_paused',false))throw new Failure('signup_paused','New accounts are temporarily paused.',503);
                $id=wp_insert_user(['user_login'=>'dl_auth0_'.substr($key,0,48),'user_email'=>$email,'user_pass'=>wp_generate_password(64,true,true),'role'=>'subscriber','display_name'=>sanitize_text_field($name)]);
                if(is_wp_error($id))throw new Failure('account_failed','Your account could not be created.',503);
                $this->bind((int)$id,$subject,$key);
            }
            $this->store->add('account',(string)$id,['user_id'=>(int)$id,'state'=>'new','created_at'=>time()],(int)$id,'new');
            return (int)$id;
            });
        });
    }
    private function bind(int $owner,string $subject,string $key): void {
        if(!$this->store->add('auth0_identity',$key,['subject'=>$subject,'issuer'=>Config::auth0Issuer().'/'],$owner,'linked'))throw new Failure('link_conflict','This sign-in is already connected.',409);
        update_user_meta($owner,'dashless_auth0_sub',$subject);update_user_meta($owner,'dashless_auth0_issuer',Config::auth0Issuer().'/');update_user_meta($owner,'dashless_email_verified',true);
    }
    public function linkExisting(int $owner,string $subject): void {
        $key=self::subjectKey($subject);
        $this->store->locked('identity:auth0:'.$key,function()use($owner,$subject,$key){
            $this->store->locked('identity:owner:'.$owner,function()use($owner,$subject,$key){
            $pending=$this->store->get('auth0_pending',$key);$user=get_user_by('id',$owner);
            if(!$user || !$pending || $pending['expires']<=time() || $pending['owner']!==$owner || strtolower($user->user_email)!==$pending['data']['email'] || $pending['data']['issuer']!==Config::auth0Issuer().'/')throw new Failure('link_unverified','A recent verified sign-in for this account is required.',409);
            $prior=(string)get_user_meta($owner,'dashless_auth0_sub',true);
            // A pre-release binding without an issuer can be repaired only after a fresh verified attempt and operator confirmation.
            if($this->store->get('auth0_identity',$key) || ($prior!=='' && ($prior!==$subject || get_user_meta($owner,'dashless_auth0_issuer',true))))throw new Failure('link_conflict','This account already has a sign-in connection.',409);
            $this->bind($owner,$subject,$key);$this->store->remove('auth0_pending',$key);
            \WP_Session_Tokens::get_instance($owner)->destroy_all();
            $this->store->put('oauth_epoch',(string)$owner,['epoch'=>bin2hex(random_bytes(16))],$owner);
            $this->store->audit($owner,'auth0_linked_by_operator');
            });
        });
    }
    public static function returnPath(string $path): string {
        // Preserve private review handoffs without accepting arbitrary redirect destinations.
        if(str_starts_with($path,'/preview/?') && !str_contains($path,"\r") && !str_contains($path,"\n") && !str_contains($path,chr(92)))return $path;
        return '/#account';
    }
    public static function logoutUrl(): string {return wp_nonce_url(Config::origin().'/auth/logout','dashless_logout');}
    public function account(int $user): array {
        $row=$this->store->get('account',(string)$user);
        if(!$row || $row['owner']!==$user)throw new Failure('account_missing','Sign in to your Dashless account.',401);
        return $row['data'];
    }
    public function save(array $account): void { $this->store->put('account',(string)$account['user_id'],$account,(int)$account['user_id'],$account['state']); }
    public static function slug(string $value): string {
        $slug=strtolower(trim($value));
        $reserved=['www','api','app','admin','mail','email','support','help','status','billing','checkout','login','signin','signup','account','oauth','mcp','assets','static','cdn','preview','previews','blog','dashless','ftp','sftp','smtp','imap','pop','autodiscover','webmail','ns1','ns2','test','staging','dev'];
        if(!preg_match('/^[a-z][a-z0-9-]{1,38}[a-z0-9]$/',$slug) || str_contains($slug,'--') || in_array($slug,$reserved,true))throw new Failure('invalid_address','Use 3–40 letters, numbers, or single hyphens. Choose a different name if reserved.');
        return $slug;
    }
}
