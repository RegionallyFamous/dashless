<?php
/** Disposable-local-test provider only. Real RSA signatures; no production authentication bypass. */
use Dashless\Hub\{Config,Identity,Store};
use Firebase\JWT\JWT;
final class Auth0Fixture {
    public static string $private='';
    public static array $jwk;
    public static array $profile=['sub'=>'auth0|alice','email'=>'auth0-alice@example.test','email_verified'=>true,'name'=>'Alice'];
    public static array $query=[],$overrides=[],$deleted=[],$grants=[];
    public static bool $revokeFails=false;
    public static function install(): void {
        if(wp_get_environment_type()!=='local')throw new RuntimeException('Disposable local WordPress required.');
        putenv('DASHLESS_AUTH0_ISSUER=https://dashless-fixture.auth0.com');
        putenv('DASHLESS_AUTH0_CLIENT_ID=browser-fixture');putenv('DASHLESS_AUTH0_CLIENT_SECRET=fixture-secret');
        putenv('DASHLESS_AUTH0_MANAGEMENT_CLIENT_ID=management-fixture');putenv('DASHLESS_AUTH0_MANAGEMENT_CLIENT_SECRET=fixture-secret');
        putenv('DASHLESS_AUTH0_MANAGEMENT_AUDIENCE=https://dashless-fixture.auth0.com/api/v2/');
        $key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,self::$private);$details=openssl_pkey_get_details($key);
        self::$jwk=['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>bin2hex(random_bytes(8)),'n'=>JWT::urlsafeB64Encode($details['rsa']['n']),'e'=>JWT::urlsafeB64Encode($details['rsa']['e'])];
        self::clearCache();self::hook();
    }
    public static function clearCache(): void {
        foreach(['discovery','jwks'] as $kind){$name='dashless_auth0_'.$kind.'_'.hash('sha256',Config::auth0Issuer());delete_transient($name);delete_transient($name.'_refresh');}
    }
    public static function hook(): void { add_filter('pre_http_request',[self::class,'http'],5,3); }
    public static function token(array $claims=[]): string {
        return JWT::encode(array_replace(['iss'=>Config::auth0Issuer().'/','aud'=>Config::resource(),'sub'=>self::$profile['sub'],'iat'=>time()-1,'exp'=>time()+600,'scope'=>'blog:read blog:write blog:publish'],$claims),self::$private,'RS256',self::$jwk['kid']);
    }
    public static function access(int $owner,array $claims=[]): string {return self::token(array_replace(['sub'=>(string)get_user_meta($owner,'dashless_auth0_sub',true)],$claims));}
    public static function bind(int $owner,string $subject): void {
        update_user_meta($owner,'dashless_auth0_sub',$subject);update_user_meta($owner,'dashless_auth0_issuer',Config::auth0Issuer().'/');update_user_meta($owner,'dashless_email_verified',true);
        (new Store())->put('auth0_identity',Identity::subjectKey($subject),['subject'=>$subject,'issuer'=>Config::auth0Issuer().'/'],$owner,'linked');
    }
    public static function http($pre,$args,$url) {
        if(!str_starts_with($url,Config::auth0Issuer().'/'))return $pre;
        $path=parse_url($url,PHP_URL_PATH);$status=200;
        if($path==='/.well-known/jwks.json')$body=['keys'=>[self::$jwk]];
        elseif($path==='/.well-known/openid-configuration')$body=['issuer'=>Config::auth0Issuer().'/','authorization_endpoint'=>Config::auth0Issuer().'/authorize','token_endpoint'=>Config::auth0Issuer().'/oauth/token','jwks_uri'=>Config::auth0Issuer().'/.well-known/jwks.json','userinfo_endpoint'=>Config::auth0Issuer().'/userinfo','code_challenge_methods_supported'=>['S256'],'client_id_metadata_document_supported'=>true];
        elseif($path==='/userinfo')$body=self::$profile;
        elseif($path==='/oauth/token') {
            $body=json_decode($args['body'],true);
            if(($body['grant_type']??'')==='client_credentials')$body=['access_token'=>'fixture-management'];
            else {
                if(($body['redirect_uri']??'')!==Identity::callback() || ($body['client_id']??'')!=='browser-fixture' || JWT::urlsafeB64Encode(hash('sha256',$body['code_verifier']??'',true))!==(self::$query['code_challenge']??null))throw new RuntimeException('Code exchange did not carry fixed callback/client and original S256 verifier.');
                $body=['id_token'=>self::token(array_replace(self::$profile,['aud'=>'browser-fixture','nonce'=>self::$query['nonce']],self::$overrides))];
            }
        } elseif($path==='/api/v2/grants') {
            if(self::$revokeFails)return new WP_Error('provider_down','Fixture unavailable');
            $body=self::$grants;
        } elseif(str_starts_with($path,'/api/v2/grants/') && $args['method']==='DELETE') {
            self::$deleted[]=rawurldecode(basename($path));$status=204;$body=[];
        } else throw new RuntimeException('Unexpected Auth0 fixture request.');
        return ['response'=>['code'=>$status],'body'=>json_encode($body),'headers'=>[],'cookies'=>[]];
    }
}
