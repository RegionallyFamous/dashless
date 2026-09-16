<?php
namespace Dashless\Hub;
final class Identity {
    public function __construct(private Store $store) {}
    public function request(string $email, string $ip, string $return="/account/"): void {
        $email=strtolower(trim($email));
        $this->store->rate('magic-ip:'.$ip,10,900);
        if (!is_email($email)) throw new Failure('invalid_email','Enter a valid email address.');
        $this->store->rate('magic-email:'.$email,3,900);
        if (get_option('dashless_hub_signup_paused',false) && !get_user_by('email',$email)) return;
        $return=self::returnPath($return);
        $token=bin2hex(random_bytes(32));
        $this->store->add('magic',hash('sha256',$token),['email'=>$email,'return'=>$return],0,'unused',time()+900);
        $url=Config::origin().'/sign-in/?token='.rawurlencode($token).'&return='.rawurlencode($return);
        $sent=wp_mail($email,'Your Dashless sign-in link',"Sign in to Dashless:\n\n$url\n\nThis link expires in 15 minutes and works once. If you did not request it, ignore this email.");
        if (!$sent) { $this->store->remove('magic',hash('sha256',$token)); throw new Failure('email_unavailable','We could not send your sign-in email. Please try again later.',503); }
    }
    public static function returnPath(string $path): string { return str_starts_with($path,'/oauth/authorize?') && !preg_match('/[\r\n]/',$path) ? $path : '/account/'; }
    public function consume(string $token): int {
        if (!preg_match('/^[a-f0-9]{64}$/',$token)) throw new Failure('invalid_link','This sign-in link is invalid or expired.',401);
        if(defined('COOKIE_DOMAIN') && COOKIE_DOMAIN)throw new Failure('cookie_configuration','Sign-in is unavailable until host-only cookies are configured.',503);
        $row=$this->store->consume('magic',hash('sha256',$token));
        if (!$row) throw new Failure('invalid_link','This sign-in link is invalid or expired. Request a new link.',401);
        $email=$row['data']['email'];
        return $this->store->locked('identity:'.$email,function()use($email){
            $user=get_user_by('email',$email);
            if (!$user) {
                if (get_option('dashless_hub_signup_paused',false)) throw new Failure('signup_paused','New accounts are temporarily paused.',503);
                $id=wp_insert_user(['user_login'=>'dl_'.substr(hash('sha256',$email),0,24),'user_email'=>$email,'user_pass'=>wp_generate_password(64,true,true),'role'=>'subscriber']);
                if (is_wp_error($id)) throw new Failure('account_failed','Your account could not be created.',503);
                $user=get_user_by('id',$id);
            }
            update_user_meta($user->ID,'dashless_email_verified',true);
            $this->store->add('account',(string)$user->ID,['user_id'=>(int)$user->ID,'state'=>'new','created_at'=>time()],$user->ID,'new');
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID,true,is_ssl());
            $this->store->audit($user->ID,'signed_in');
            return (int)$user->ID;
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
