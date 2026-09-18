<?php
namespace Dashless\Hub;

use Firebase\JWT\{JWT,JWK};

/** Auth0 owns credentials and token issuance; WordPress stores only the local owner binding. */
final class Auth0 {
    public static function revokeGrants(string $subject): void {
        if($subject==='')throw new Failure('disconnect_failed','ChatGPT is blocked. Contact support to finish disconnecting it.',503);
        try {
            $deadline=microtime(true)+35;
            $tokens=self::request('/oauth/token',['grant_type'=>'client_credentials','client_id'=>Config::required('auth0_management_client_id'),'client_secret'=>Config::required('auth0_management_client_secret'),'audience'=>Config::auth0ManagementAudience()]);
            $token=$tokens['access_token']??'';
            if(!is_string($token) || $token==='' || preg_match('/[\r\n]/',$token))throw new \RuntimeException('token');
            $headers=['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'];
            // Collect before deleting so pagination cannot skip a grant as pages shift.
            $ids=[];$finished=false;
            for($page=0;$page<5;$page++) {
                if(microtime(true)>$deadline)throw new \RuntimeException('deadline');
                $r=wp_remote_get(Config::auth0Issuer().'/api/v2/grants?'.http_build_query(['user_id'=>$subject,'audience'=>Config::auth0Audience(),'page'=>$page,'per_page'=>100]),['timeout'=>10,'redirection'=>0,'limit_response_size'=>262144,'headers'=>$headers]);
                $grants=is_wp_error($r)?null:json_decode(wp_remote_retrieve_body($r),true);
                if(is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200 || !is_array($grants) || !array_is_list($grants))throw new \RuntimeException('grants');
                foreach($grants as $grant)if(($grant['user_id']??null)===$subject && ($grant['audience']??null)===Config::auth0Audience() && is_string($grant['id']??null))$ids[]=$grant['id'];
                if(count($grants)<100){$finished=true;break;}
            }
            if(!$finished || count($ids)>20)throw new \RuntimeException('grant_limit');
            foreach($ids as $id) {
                if(microtime(true)>$deadline)throw new \RuntimeException('deadline');
                $r=wp_remote_request(Config::auth0Issuer().'/api/v2/grants/'.rawurlencode($id),['method'=>'DELETE','timeout'=>10,'redirection'=>0,'limit_response_size'=>65536,'headers'=>$headers]);
                if(is_wp_error($r) || !in_array(wp_remote_retrieve_response_code($r),[204,404],true))throw new \RuntimeException('revoke');
            }
        }catch(\Throwable $e){throw new Failure('disconnect_failed','ChatGPT is blocked from your blog. Please try disconnecting again to finish removing its connection.',503);}
    }
    public static function request(string $path, array $body=[]): array {
        $response=wp_remote_request(Config::auth0Issuer().$path,[
            'method'=>$body?'POST':'GET','timeout'=>15,'redirection'=>0,'limit_response_size'=>131072,
            'headers'=>['Accept'=>'application/json']+($body?['Content-Type'=>'application/json']:[]),
            'body'=>$body?wp_json_encode($body):null,
        ]);
        if(is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200)throw new Failure('signin_unavailable','Sign-in is temporarily unavailable. Please try again.',503);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if(!is_array($data))throw new Failure('signin_unavailable','Sign-in is temporarily unavailable. Please try again.',503);
        return $data;
    }
    public static function metadata(): array {
        $key='dashless_auth0_discovery_'.hash('sha256',Config::auth0Issuer());
        $data=get_transient($key);
        if(!is_array($data)) {
            $data=self::request('/.well-known/openid-configuration');
            if(($data['issuer']??null)!==Config::auth0Issuer().'/' || !in_array('S256',$data['code_challenge_methods_supported']??[],true))throw new Failure('signin_configuration','Sign-in needs a configuration update.',503);
            foreach(['authorization_endpoint'=>'/authorize','token_endpoint'=>'/oauth/token','jwks_uri'=>'/.well-known/jwks.json','userinfo_endpoint'=>'/userinfo'] as $name=>$path) {
                if(($data[$name]??null)!==Config::auth0Issuer().$path)throw new Failure('signin_configuration','Sign-in needs a configuration update.',503);
            }
            set_transient($key,$data,600);
        }
        return $data;
    }
    public static function claims(string $token,string $audience,?string $nonce=null): array {
        if(strlen($token)>16000 || substr_count($token,'.')!==2)throw new Failure('invalid_token','Please sign in again.',401);
        try {
            // The untrusted header selects a key only. It never selects a URL or algorithm.
            $header=json_decode(JWT::urlsafeB64Decode(explode('.',$token)[0]),true,16,JSON_THROW_ON_ERROR);
            if(($header['alg']??null)!=='RS256' || !is_string($header['kid']??null) || strlen($header['kid'])>200 || isset($header['crit']))throw new \RuntimeException('header');
            $cache='dashless_auth0_jwks_'.hash('sha256',Config::auth0Issuer());
            $keys=get_transient($cache);
            $known=is_array($keys) && in_array($header['kid'],array_column($keys['keys']??[],'kid'),true);
            if(!$known) {
                // Cache misses are bounded; unknown keys must not turn every forged token into a remote request.
                $gate=$cache.'_refresh';
                if(get_transient($gate))throw new \RuntimeException('unknown_key');
                set_transient($gate,1,10);
                $keys=self::request('/.well-known/jwks.json');
                $keys['keys']=array_values(array_filter($keys['keys']??[],fn($k)=>is_array($k) && ($k['kty']??'')==='RSA' && ($k['use']??'sig')==='sig' && ($k['alg']??'RS256')==='RS256'));
                set_transient($cache,$keys,600);
            }
            $claims=(array)JWT::decode($token,JWK::parseKeySet($keys,'RS256'));
            $aud=$claims['aud']??[];$aud=is_array($aud)?$aud:[$aud];
            if(($claims['iss']??null)!==Config::auth0Issuer().'/' || !in_array($audience,$aud,true)
                || !is_int($claims['exp']??null) || $claims['exp']<=time()
                || !is_int($claims['iat']??null) || $claims['iat']>time()
                || !is_string($claims['sub']??null) || !preg_match('/^[^\x00-\x20\x7f]{1,255}$/D',$claims['sub']))throw new \RuntimeException('claims');
            if($nonce!==null && (!is_string($claims['nonce']??null) || !hash_equals($nonce,$claims['nonce'])
                || (count($aud)>1 && ($claims['azp']??null)!==$audience)
                || (isset($claims['azp']) && $claims['azp']!==$audience)))throw new \RuntimeException('nonce');
            return $claims;
        }catch(\Throwable $e){throw new Failure('invalid_token','Please sign in again.',401);}
    }
    public static function profile(string $token,string $subject): array {
        $r=wp_remote_get(Config::auth0Issuer().'/userinfo',['timeout'=>15,'redirection'=>0,'limit_response_size'=>65536,'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json']]);
        $data=is_wp_error($r)?null:json_decode(wp_remote_retrieve_body($r),true);
        if(is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200 || !is_array($data) || ($data['sub']??null)!==$subject || ($data['email_verified']??null)!==true || !is_email($data['email']??''))throw new Failure('email_unverified','Please sign in with a verified email address.',401);
        return $data;
    }
}
