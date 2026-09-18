<?php
namespace Dashless\Hub;

/** Auth0 is the only token issuer. No local authorization codes, passwords or refresh tokens. */
final class OAuth {
    public const SCOPES=['blog:read','blog:write','blog:publish'];
    public function __construct(private Store $store) {}
    public function metadata(): array { return Auth0::metadata(); }
    public function protectedMetadata(): array { return ['resource'=>Config::resource(),'authorization_servers'=>[Config::auth0Issuer().'/'],'scopes_supported'=>self::SCOPES]; }
    public function authenticate(string $authorization): array {
        if(!str_starts_with($authorization,'Bearer '))throw new Failure('authentication_required','Connect your Dashless account.',401);
        try {
            $jwt=substr($authorization,7);$claims=Auth0::claims($jwt,Config::auth0Audience());
            if(str_ends_with($claims['sub'],'@clients') || ($claims['gty']??'')==='client-credentials')throw new \RuntimeException('human_required');
            if(!is_string($claims['scope']??null))throw new \RuntimeException('scope');
            $scopes=array_values(array_intersect(self::SCOPES,preg_split('/\\s+/',trim($claims['scope']))));
            if(!$scopes)throw new \RuntimeException('scope');
            $mapping=$this->store->get('auth0_identity',Identity::subjectKey($claims['sub']));
            $owner=$mapping['owner']??0;
            if(!$owner) {
                $profile=Auth0::profile($jwt,$claims['sub']);
                $owner=(new Identity($this->store))->connectAuth0($claims['sub'],$profile['email'],true,(string)($profile['name']??''));
            }
            if(!Identity::verified($owner) || (string)get_user_meta($owner,'dashless_auth0_sub',true)!==$claims['sub'])throw new \RuntimeException('identity');
            $connection=$this->store->get('oauth_epoch',(string)$owner)['data']??[];
            if(!empty($connection['blocked']) || $claims['iat']<=(int)($connection['revoked_before']??0))throw new \RuntimeException('revoked');
            return ['owner'=>$owner,'scopes'=>$scopes,'token_id'=>hash('sha256',$jwt)];
        }catch(\Throwable $e){throw new Failure('invalid_token','Reconnect your Dashless account.',401);}
    }
    public function disconnect(int $owner): void {
        $this->store->locked('disconnect:'.$owner,function()use($owner){
        // Deny immediately, even if the provider is unavailable. Existing preview handoffs also expire.
        $this->store->put('oauth_epoch',(string)$owner,['epoch'=>bin2hex(random_bytes(16)),'blocked'=>true,'revoked_before'=>time()],$owner);
        $this->store->audit($owner,'chatgpt_disconnected');
        Auth0::revokeGrants((string)get_user_meta($owner,'dashless_auth0_sub',true));
        $state=$this->store->get('oauth_epoch',(string)$owner)['data'];$state['blocked']=false;$state['revoked_before']=time();
        $this->store->put('oauth_epoch',(string)$owner,$state,$owner);
        });
    }
}
