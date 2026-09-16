<?php
namespace Dashless\Hub;
final class Identity {
    public function __construct(private Store $store) {}
    public static function verified(int $owner): bool {
        return $owner>0 && (bool)get_user_meta($owner,'dashless_wpcom_id',true) && (bool)get_user_meta($owner,'dashless_email_verified',true);
    }
    public static function configured(): bool { return (bool)Config::get('wpcom_client_id') && (bool)Config::get('wpcom_client_secret'); }
    public static function callback(): string { return Config::origin().'/auth/wordpress/callback'; }
    public function begin(string $return,string $browser): string {
        Config::required('wpcom_client_secret');
        if (!preg_match('/^[a-f0-9]{64}$/D',$browser)) throw new Failure('signin_invalid','Please start sign-in again.',400);
        $this->store->rate('wpcom-start:'.($_SERVER['REMOTE_ADDR']??'unknown'),20,900);
        $state=bin2hex(random_bytes(32));
        $this->store->add('wpcom_state',hash('sha256',$state),['browser'=>hash('sha256',$browser),'return'=>self::returnPath($return)],0,'unused',time()+600);
        return 'https://public-api.wordpress.com/oauth2/authenticate?'.http_build_query(['client_id'=>Config::required('wpcom_client_id'),'redirect_uri'=>self::callback(),'response_type'=>'code','scope'=>'auth','state'=>$state]);
    }
    public function complete(string $state,string $browser,string $code,string $error=''): array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$state) || !preg_match('/^[a-f0-9]{64}$/D',$browser)) throw new Failure('signin_invalid','Please start sign-in again.',403);
        $row=$this->store->get('wpcom_state',hash('sha256',$state));
        if (!$row || !hash_equals($row['data']['browser'],hash('sha256',$browser))) throw new Failure('signin_invalid','Please finish sign-in in the browser where you started.',403);
        $row=$this->store->consume('wpcom_state',hash('sha256',$state));
        if (!$row) throw new Failure('signin_expired','This sign-in has expired or was already used. Please start again.',403);
        if ($error!=='') throw new Failure('signin_canceled','WordPress.com sign-in was canceled. You can try again.',400);
        if ($code==='' || strlen($code)>4096) throw new Failure('signin_invalid','WordPress.com did not return a valid sign-in code.',400);
        $response=wp_remote_post('https://public-api.wordpress.com/oauth2/token',['timeout'=>15,'redirection'=>0,'limit_response_size'=>65536,'body'=>['client_id'=>Config::required('wpcom_client_id'),'client_secret'=>Config::required('wpcom_client_secret'),'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>self::callback()]]);
        $token=$this->providerResponse($response)['access_token']??'';
        if (!is_string($token) || $token==='' || preg_match('/[\r\n]/',$token)) throw new Failure('signin_unavailable','WordPress.com sign-in is temporarily unavailable. Please try again.',503);
        $response=wp_remote_get('https://public-api.wordpress.com/rest/v1.1/me',['timeout'=>15,'redirection'=>0,'limit_response_size'=>65536,'headers'=>['Authorization'=>'Bearer '.$token]]);
        $profile=$this->providerResponse($response);
        // Provider tokens are used only to identify the owner, never persisted or sent to ChatGPT.
        $id=$this->connect($profile);
        wp_set_current_user($id);wp_set_auth_cookie($id,true,is_ssl());
        $this->store->audit($id,'signed_in_wpcom');
        return ['user_id'=>$id,'return'=>$row['data']['return']];
    }
    private function providerResponse($response): array {
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) throw new Failure('signin_unavailable','WordPress.com sign-in is temporarily unavailable. Please try again.',503);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if (!is_array($data)) throw new Failure('signin_unavailable','WordPress.com returned an invalid response. Please try again.',503);
        return $data;
    }
    private function connect(array $profile): int {
        $external=(string)($profile['ID']??'');$email=strtolower(trim((string)($profile['email']??'')));
        if (!preg_match('/^[1-9][0-9]{0,19}$/D',$external) || !is_email($email) || ($profile['email_verified']??false)!==true) throw new Failure('email_unverified','Verify your email address in WordPress.com, then sign in again.',403);
        if(defined('COOKIE_DOMAIN') && COOKIE_DOMAIN)throw new Failure('cookie_configuration','Sign-in is unavailable until host-only cookies are configured.',503);
        return $this->store->locked('identity:wpcom:'.$external,function()use($external,$email,$profile){
            $mapping=$this->store->get('wpcom_identity',$external);
            if ($mapping) {
                $id=$mapping['owner'];$user=get_user_by('id',$id);
                if (!$user || (string)get_user_meta($id,'dashless_wpcom_id',true)!==$external) throw new Failure('identity_mismatch','Contact support to restore your account connection.',409);
                // An email change cannot transfer account ownership to another WordPress.com ID.
                if ($user->user_email!==$email && !email_exists($email)) wp_update_user(['ID'=>$id,'user_email'=>$email]);
            } else {
                if (get_option('dashless_hub_signup_paused',false)) throw new Failure('signup_paused','New accounts are temporarily paused.',503);
                $login='dl_wpcom_'.$external;
                // Recover only an exact provider-ID-bound partial creation, never an email match.
                $prior=get_user_by('login',$login);
                if ($prior) {
                    if ((string)get_user_meta($prior->ID,'dashless_wpcom_id',true)!==$external || user_can($prior,'manage_options')) throw new Failure('identity_mismatch','Contact support to restore your account connection.',409);
                    $id=(int)$prior->ID;
                } else {
                if ($existing=email_exists($email)) {
                    $this->store->put('wpcom_pending',$external,['email'=>$email,'wpcom_id'=>$external],(int)$existing,'pending',time()+3600);
                    throw new Failure('account_link_required','An account already uses this email. Contact support to link it securely to WordPress.com.',409);
                }
                    $id=wp_insert_user(['user_login'=>$login,'user_email'=>$email,'user_pass'=>wp_generate_password(64,true,true),'role'=>'subscriber','display_name'=>sanitize_text_field((string)($profile['display_name']??''))]);
                    if (is_wp_error($id)) throw new Failure('account_failed','Your account could not be created.',503);
                    update_user_meta($id,'dashless_wpcom_id',$external);
                }
                $this->store->add('wpcom_identity',$external,['provider'=>'wordpress.com'],$id,'linked');
            }
            update_user_meta($id,'dashless_email_verified',true);
            $this->store->add('account',(string)$id,['user_id'=>(int)$id,'state'=>'new','created_at'=>time()],$id,'new');
            return (int)$id;
        });
    }
    public static function returnPath(string $path): string { return str_starts_with($path,'/oauth/authorize?') && !preg_match('/[\r\n]/',$path) ? $path : '/account/'; }
    public function linkExisting(int $owner,string $external): void {
        $this->store->locked('identity:wpcom:'.$external,function()use($owner,$external){
            $pending=$this->store->get('wpcom_pending',$external);$user=get_user_by('id',$owner);
            if (!$user || !$pending || $pending['expires']<=time() || $pending['owner']!==$owner || strtolower($user->user_email)!==$pending['data']['email']) throw new Failure('link_unverified','A recent verified provider sign-in matching this account is required.',409);
            if ($this->store->get('wpcom_identity',$external) || get_user_meta($owner,'dashless_wpcom_id',true)) throw new Failure('link_conflict','An identity is already linked.',409);
            update_user_meta($owner,'dashless_wpcom_id',$external);
            $this->store->add('wpcom_identity',$external,['provider'=>'wordpress.com'],$owner,'linked');
            $this->store->remove('wpcom_pending',$external);
            $this->store->audit($owner,'wpcom_linked_by_operator');
        });
    }
    public function account(int $user): array {
        $row=$this->store->get('account',(string)$user);
        if (!$row || $row['owner']!==$user) throw new Failure('account_missing','Sign in to your Dashless account.',401);
        return $row['data'];
    }
    public function save(array $account): void { $this->store->put('account',(string)$account['user_id'],$account,(int)$account['user_id'],$account['state']); }
    public static function slug(string $value): string {
        $slug=strtolower(trim($value));
        $reserved=['www','api','app','admin','mail','email','support','help','status','billing','checkout','login','signin','signup','account','oauth','mcp','assets','static','cdn','preview','previews','blog','dashless','ftp','sftp','smtp','imap','pop','autodiscover','webmail','ns1','ns2','test','staging','dev'];
        if (!preg_match('/^[a-z][a-z0-9-]{1,38}[a-z0-9]$/',$slug) || str_contains($slug,'--') || in_array($slug,$reserved,true)) throw new Failure('invalid_address','Use 3–40 letters, numbers, or single hyphens. Choose a different name if reserved.');
        return $slug;
    }
}
