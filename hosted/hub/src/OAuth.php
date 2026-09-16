<?php
namespace Dashless\Hub;

use League\OAuth2\Server\Entities as E;
use League\OAuth2\Server\Entities\Traits as T;
use League\OAuth2\Server\Repositories as R;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;

final class OAuth {
    public const SCOPES=['blog:read','blog:write','blog:publish'];
    use \League\OAuth2\Server\CryptTrait;
    private OAuthRepository $repository;
    public function __construct(private Store $store) { $this->repository=new OAuthRepository($store); }
    private function server(): \League\OAuth2\Server\AuthorizationServer {
        $r=$this->repository;
        $server=new \League\OAuth2\Server\AuthorizationServer($r,$r,$r,Config::required('oauth_private_key'),Config::required('encryption_key'));
        $grant=new \League\OAuth2\Server\Grant\AuthCodeGrant($r,$r,new \DateInterval('PT5M'));
        $grant->setRefreshTokenTTL(new \DateInterval('P30D'));
        $server->enableGrantType($grant,new \DateInterval('PT15M'));
        $refresh=new \League\OAuth2\Server\Grant\RefreshTokenGrant($r);$refresh->setRefreshTokenTTL(new \DateInterval('P30D'));
        $server->enableGrantType($refresh,new \DateInterval('PT15M'));
        return $server;
    }
    public function metadata(): array {
        $o=Config::origin();
        return ['issuer'=>$o,'authorization_endpoint'=>$o.'/oauth/authorize','token_endpoint'=>$o.'/oauth/token','revocation_endpoint'=>$o.'/oauth/revoke',
            'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],
            'code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['none'],
            'scopes_supported'=>self::SCOPES,'authorization_response_iss_parameter_supported'=>true];
    }
    public function protectedMetadata(): array { return ['resource'=>Config::resource(),'authorization_servers'=>[Config::origin()],'scopes_supported'=>self::SCOPES]; }
    private function resource(array $input): void {
        if (!isset($input['resource']) || !is_string($input['resource']) || !hash_equals(Config::resource(),$input['resource'])) throw OAuthServerException::invalidRequest('resource');
    }
    public function authorization(array $query): \League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface {
        $this->resource($query);
        if (($query['code_challenge_method']??'')!=='S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/',(string)($query['code_challenge']??''))) throw OAuthServerException::invalidRequest('code_challenge');
        if (empty($query['state'])) throw OAuthServerException::invalidRequest('state');
        return $this->server()->validateAuthorizationRequest((new ServerRequest('GET',Config::origin().'/oauth/authorize'))->withQueryParams($query));
    }
    public function approve(array $query,int $owner,bool $approved): \Psr\Http\Message\ResponseInterface {
        $auth=$this->authorization($query);$user=new OAuthUser();$user->setIdentifier((string)$owner);
        $auth->setUser($user);$auth->setAuthorizationApproved($approved);
        try {$response=$this->server()->completeAuthorizationRequest($auth,new Response());}
        catch(OAuthServerException $e){$response=$e->generateHttpResponse(new Response());}
        return $this->withIssuer($response);
    }
    public function withIssuer(\Psr\Http\Message\ResponseInterface $response): \Psr\Http\Message\ResponseInterface {
        $location=$response->getHeaderLine('Location');
        if($location)$response=$response->withHeader('Location',$location.(str_contains($location,'?')?'&':'?').'iss='.rawurlencode(Config::origin()));
        return $response;
    }
    public function token(array $body): \Psr\Http\Message\ResponseInterface {
        $this->resource($body);
        $grant=$body['grant_type']??'';
        if (!in_array($grant,['authorization_code','refresh_token'],true)) throw OAuthServerException::unsupportedGrantType();
        $proof=$body[$grant==='authorization_code'?'code':'refresh_token']??'';
        if (!is_string($proof) || strlen($proof)>16000 || !$proof) throw OAuthServerException::invalidRequest('code');
        // The League handles PKCE and rotation; the database lease closes concurrent redemption races.
        return $this->store->locked('oauth-redeem:'.hash('sha256',$proof),fn()=> $this->server()->respondToAccessTokenRequest((new ServerRequest('POST',Config::origin().'/oauth/token'))->withParsedBody($body),new Response()));
    }
    public function authenticate(string $authorization): array {
        if (!str_starts_with($authorization,'Bearer ')) throw new Failure('authentication_required','Connect your Dashless account.',401);
        $server=new \League\OAuth2\Server\ResourceServer($this->repository,Config::required('oauth_public_key'));
        try {
            $request=$server->validateAuthenticatedRequest((new ServerRequest('POST',Config::resource()))->withHeader('Authorization',$authorization));
            $token=(new \Lcobucci\JWT\Token\Parser(new \Lcobucci\JWT\Encoding\JoseEncoder()))->parse(substr($authorization,7));
            if (!$token->claims()->has('iss') || $token->claims()->get('iss')!==Config::origin() || !$token->isPermittedFor(Config::resource())) throw new \RuntimeException('audience');
            return ['owner'=>(int)$request->getAttribute('oauth_user_id'),'scopes'=>$request->getAttribute('oauth_scopes'),'token_id'=>$request->getAttribute('oauth_access_token_id')];
        } catch(\Throwable $e) { throw new Failure('invalid_token','Reconnect your Dashless account.',401); }
    }
    public function revoke(array $body): void {
        if(!hash_equals(Config::required('oauth_client_id'),(string)($body['client_id']??'')))throw OAuthServerException::invalidClient();
        $value=(string)($body['token']??'');
        if(!$value || strlen($value)>16000)return;
        try {
            if(substr_count($value,'.')===2) {
                $auth=$this->authenticate('Bearer '.$value);$this->disconnect($auth['owner']);
            } else {
                $this->setEncryptionKey(Config::required('encryption_key'));
                $token=json_decode($this->decrypt($value),true,512,JSON_THROW_ON_ERROR);
                if(($token['client_id']??'')!==Config::required('oauth_client_id'))return;
                $record=$this->store->get('oauth_refresh',(string)($token['refresh_token_id']??''));
                if($record && $record['owner']===(int)($token['user_id']??0))$this->disconnect($record['owner']);
            }
        }catch(\Throwable $e){/* RFC 7009: invalid or already revoked token is also a successful response. */}
    }
    public function disconnect(int $owner): void {
        // Epoch invalidates both access and refresh chains without an unbounded token scan.
        $this->store->put('oauth_epoch',(string)$owner,['epoch'=>bin2hex(random_bytes(16))],$owner);
        $this->store->audit($owner,'chatgpt_disconnected');
    }
}

final class OAuthClient implements E\ClientEntityInterface { use T\EntityTrait,T\ClientTrait; public function __construct() {$this->setIdentifier(Config::required('oauth_client_id'));$this->name='ChatGPT';$this->redirectUri=Config::required('oauth_redirect_uri');$this->isConfidential=false;} }
final class OAuthUser implements E\UserEntityInterface { use T\EntityTrait; }
final class OAuthScope implements E\ScopeEntityInterface { use T\EntityTrait,T\ScopeTrait; }
final class OAuthCode implements E\AuthCodeEntityInterface { use T\EntityTrait,T\TokenEntityTrait,T\AuthCodeTrait; }
final class OAuthRefresh implements E\RefreshTokenEntityInterface { use T\EntityTrait,T\RefreshTokenTrait; }
final class OAuthAccess implements E\AccessTokenEntityInterface {
    use T\EntityTrait,T\TokenEntityTrait,T\AccessTokenTrait;
    public function toString(): string {
        $this->initJwtConfiguration();
        return $this->jwtConfiguration->builder()->issuedBy(Config::origin())->permittedFor(Config::resource())
            ->identifiedBy($this->getIdentifier())->issuedAt(new \DateTimeImmutable())->canOnlyBeUsedAfter(new \DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())->relatedTo($this->getUserIdentifier())
            ->withClaim('client_id',$this->getClient()->getIdentifier())->withClaim('scopes',$this->getScopes())
            ->getToken($this->jwtConfiguration->signer(),$this->jwtConfiguration->signingKey())->toString();
    }
}
final class OAuthRepository implements R\ClientRepositoryInterface,R\AccessTokenRepositoryInterface,R\AuthCodeRepositoryInterface,R\RefreshTokenRepositoryInterface,R\ScopeRepositoryInterface {
    public function __construct(private Store $store) {}
    public function getClientEntity(string $clientIdentifier): ?E\ClientEntityInterface { return hash_equals(Config::required('oauth_client_id'),$clientIdentifier)?new OAuthClient():null; }
    public function validateClient(string $clientIdentifier,?string $clientSecret,?string $grantType): bool { return $this->getClientEntity($clientIdentifier)!==null && in_array($grantType,['authorization_code','refresh_token'],true); }
    public function getScopeEntityByIdentifier(string $identifier): ?E\ScopeEntityInterface { if(!in_array($identifier,OAuth::SCOPES,true))return null;$s=new OAuthScope();$s->setIdentifier($identifier);return $s; }
    public function finalizeScopes(array $scopes,string $grantType,E\ClientEntityInterface $clientEntity,?string $userIdentifier=null,?string $authCodeId=null): array { return $scopes; }
    public function getNewToken(E\ClientEntityInterface $clientEntity,array $scopes,?string $userIdentifier=null): E\AccessTokenEntityInterface {
        $t=new OAuthAccess();$t->setClient($clientEntity);$t->setUserIdentifier($userIdentifier);foreach($scopes as $s)$t->addScope($s);return $t;
    }
    public function getNewAuthCode(): E\AuthCodeEntityInterface { return new OAuthCode(); }
    public function getNewRefreshToken(): ?E\RefreshTokenEntityInterface { return new OAuthRefresh(); }
    private function epoch(int $owner): string { return $this->store->get('oauth_epoch',(string)$owner)['data']['epoch']??'initial'; }
    private function persist(string $kind,string $id,int $owner,int $expires,array $extra=[]): void {
        if(!$this->store->add($kind,$id,['epoch'=>$this->epoch($owner),'resource'=>Config::resource()]+$extra,$owner,'valid',$expires)) throw new \League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException();
    }
    private function revoked(string $kind,string $id): bool {
        $r=$this->store->get($kind,$id);return !$r || $r['status']!=='valid' || $r['expires']<=time() || $r['data']['epoch']!==$this->epoch($r['owner']) || $r['data']['resource']!==Config::resource();
    }
    private function revoke(string $kind,string $id): void { $this->store->remove($kind,$id); }
    public function persistNewAccessToken(E\AccessTokenEntityInterface $e): void { $this->persist('oauth_access',$e->getIdentifier(),(int)$e->getUserIdentifier(),$e->getExpiryDateTime()->getTimestamp()); }
    public function revokeAccessToken(string $tokenId): void { $this->revoke('oauth_access',$tokenId); }
    public function isAccessTokenRevoked(string $tokenId): bool { return $this->revoked('oauth_access',$tokenId); }
    public function persistNewAuthCode(E\AuthCodeEntityInterface $e): void { $this->persist('oauth_code',$e->getIdentifier(),(int)$e->getUserIdentifier(),$e->getExpiryDateTime()->getTimestamp()); }
    public function revokeAuthCode(string $codeId): void { $this->revoke('oauth_code',$codeId); }
    public function isAuthCodeRevoked(string $codeId): bool { return $this->revoked('oauth_code',$codeId); }
    public function persistNewRefreshToken(E\RefreshTokenEntityInterface $e): void { $this->persist('oauth_refresh',$e->getIdentifier(),(int)$e->getAccessToken()->getUserIdentifier(),$e->getExpiryDateTime()->getTimestamp()); }
    public function revokeRefreshToken(string $tokenId): void { $this->revoke('oauth_refresh',$tokenId); }
    public function isRefreshTokenRevoked(string $tokenId): bool { return $this->revoked('oauth_refresh',$tokenId); }
}
