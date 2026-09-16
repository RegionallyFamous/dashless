<?php
namespace Dashless\Hub;
final class Routes {
    public function __construct(private App $app) {}
    public function register(): void {
        add_action('rest_api_init',function(){
            $public=fn()=>true;$private=fn()=>is_user_logged_in() && (bool)get_user_meta(get_current_user_id(),'dashless_email_verified',true);
            $this->route('/auth/request','POST',$public,function($r){$this->sameOrigin($r);$this->app->identity->request((string)$r['email'],(string)($_SERVER['REMOTE_ADDR']??'unknown'),(string)($r['return']??'/account/'));return ['sent'=>true];});
            $this->route('/account','GET',$private,fn()=>Screens::publicAccount($this->app->identity->account(get_current_user_id())));
            $this->route('/checkout','POST',$private,fn($r)=>$this->app->billing->checkout(get_current_user_id(),(string)$r['slug']));
            $browser=function($r){return $this->browserPermission($r);};
            $this->route('/preview/approve','POST',$browser,fn($r)=>(new Previews($this->app))->approve(get_current_user_id(),(string)$r['preview_id'],(string)($r['handoff']??'')));
            $this->route('/preview/rollback','POST',$browser,fn($r)=>(new Previews($this->app))->rollback(get_current_user_id(),(string)$r['release_id'],(string)($r['handoff']??'')));
            $this->route('/recover','POST',$private,fn()=>$this->app->billing->recover(get_current_user_id()));
            $this->route('/billing/portal','POST',$private,fn()=>$this->app->billing->portal(get_current_user_id()));
            $this->route('/progress','POST',$private,function(){ $this->app->store->rate('progress:'.get_current_user_id(),6,60);$this->app->kick();return ['queued'=>true]; });
            $this->route('/exports/(?P<id>[a-f0-9-]{36})','GET',$private,function($r){
                $owner=get_current_user_id();$job=$this->app->jobs->owned($owner,$r['id']);
                if(($job['data']['kind']??'')!=='export' || $job['status']!=='succeeded')throw new Failure('export_unavailable','This export is not ready.',409);
                $a=$this->app->identity->account($owner);
                $exportId=Chatgpt::uuid((string)($job['data']['result']['export_id']??$r['id']));
                $download=$this->app->agent->call($a,'POST','/exports/'.$exportId.'/download',['account_id'=>$owner,'ttl'=>60]);
                $url=(string)($download['url']??'');
                if(parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_HOST)!==$a['domain'] || !str_starts_with((string)parse_url($url,PHP_URL_PATH),'/wp-json/dashless-hosted/v1/exports/'))throw new Failure('download_invalid','Export destination failed validation.',503);
                return ['url'=>$url];
            });
            $this->route('/jobs/(?P<id>[a-f0-9-]{36})','GET',$private,fn($r)=>$this->app->jobs->status(get_current_user_id(),$r['id']));
            $this->route('/disconnect','POST',$private,function(){$this->app->oauth->disconnect(get_current_user_id());return ['disconnected'=>true];});
            $this->route('/export','POST',$private,function(){
                $a=$this->app->identity->account(get_current_user_id());
                if(empty($a['site_id']) || $a['state']==='deleted')throw new Failure('site_missing','No site is available to export.',404);
                $result=$this->app->agent->call($a,'POST','/tools/export_site',['arguments'=>['client_key'=>'export-'.wp_generate_uuid4()],'actor'=>['account_id'=>get_current_user_id()]]);
                if(!empty($result['job_id'])){$this->app->jobs->remember(get_current_user_id(),$result,'export');$this->app->kick();}
                return $result;
            });
            $this->route('/stripe/webhook','POST',$public,function($r){$id=$this->app->billing->receive($r->get_body(),(string)$r->get_header('stripe-signature'));$this->app->kick();return ['received'=>true];});
            // Callback contents never authorize state transitions. They only request native-task reconciliation.
            $this->route('/platform/webhook','POST',$public,function($r){
                $secret=Config::required('platform_webhook_secret');
                if(!hash_equals($secret,(string)$r->get_header('x-dashless-webhook-secret')))throw new Failure('invalid_callback','Invalid callback.',401);
                $this->app->store->rate('platform-callback',6,60);$this->app->kick();return ['received'=>true];
            });
        });
        add_action('init',[$this,'native'],0);
        add_action('template_redirect',function(){
            if(!is_page('preview'))return;
            nocache_headers();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
            $frame="'none'";
            if(is_user_logged_in())try{$a=$this->app->identity->account(get_current_user_id());if(($a['domain']??'')===Identity::slug($a['slug']).'.'.Config::domain())$frame='https://'.$a['domain'];}catch(Failure $e){}
            header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'; connect-src 'self'; frame-src ".$frame);
        },-90);
        add_action('admin_post_nopriv_dashless_magic',[$this,'magic']);add_action('admin_post_dashless_magic',[$this,'magic']);
    }
    public function browserPermission($r): bool {
        // WordPress application passwords and OAuth are not browser user events.
        $owner=get_current_user_id();$cookie=wp_validate_auth_cookie('', 'logged_in');
        return $owner>0 && (int)$cookie===$owner && (bool)get_user_meta($owner,'dashless_email_verified',true)
            && wp_verify_nonce((string)$r->get_header('x-wp-nonce'),'wp_rest')!==false
            && (!$r->get_header('origin') || $r->get_header('origin')===Config::origin());
    }
    private function route(string $path,string $method,callable $permission,callable $callback): void {
        register_rest_route('dashless-hub/v1',$path,['methods'=>$method,'permission_callback'=>$permission,'callback'=>function($r)use($callback){
            nocache_headers();
            try{return new \WP_REST_Response($callback($r),200,['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);}
            catch(Failure $e){return new \WP_Error($e->slug,$e->getMessage(),['status'=>$e->status]);}
            catch(\Throwable $e){$this->app->store->audit(get_current_user_id(),'request_failed',['type'=>get_class($e)]);return new \WP_Error('service_unavailable','The service could not complete this request. Try again later.',['status'=>503]);}
        }]);
    }
    private function sameOrigin($r): void {
        $origin=$r->get_header('origin');
        if($origin && $origin!==Config::origin())throw new Failure('origin_invalid','Request origin not allowed.',403);
    }
    public function magic(): void {
        nocache_headers();header('Referrer-Policy: no-referrer');
        if($_SERVER['REQUEST_METHOD']!=='POST')wp_die('Use the sign-in form.',405);
        if(!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce']??'')),'dashless_magic'))wp_die('Please reopen your sign-in link.',403);
        try {
            $this->app->identity->consume((string)wp_unslash($_POST['token']??''));
            $target=(string)wp_unslash($_POST['return']??'/account/');
            $target=Identity::returnPath($target);
            wp_safe_redirect(Config::origin().$target,303);exit;
        }catch(Failure $e){wp_die(esc_html($e->getMessage()),'Sign-in link', ['response'=>$e->status]);}
    }
    private function json(array $body,int $status=200): never { nocache_headers();header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');wp_send_json($body,$status); }
    private function emit(\Psr\Http\Message\ResponseInterface $response): never {
        nocache_headers();status_header($response->getStatusCode());
        foreach($response->getHeaders() as $name=>$values)foreach($values as $v)header($name.': '.$v,false);
        header('Cache-Control: no-store');echo $response->getBody();exit;
    }
    public function native(): void {
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!in_array($path,['/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/mcp','/.well-known/oauth-authorization-server','/oauth/authorize','/oauth/token','/oauth/revoke','/mcp'],true))return;
        try {
            if(str_starts_with($path,'/.well-known/')) {
                if($method!=='GET')$this->json(['error'=>'method_not_allowed'],405);
                $this->json(str_contains($path,'protected-resource')?$this->app->oauth->protectedMetadata():$this->app->oauth->metadata());
            }
            if($path==='/oauth/authorize') {
                $query=wp_unslash($_GET);$this->app->oauth->authorization($query);
                if(!is_user_logged_in() || !get_user_meta(get_current_user_id(),'dashless_email_verified',true)) {
                    wp_safe_redirect(Config::origin().'/sign-in/?return='.rawurlencode('/oauth/authorize?'.http_build_query($query)),303);exit;
                }
                if($method==='POST') {
                    if(!wp_verify_nonce((string)wp_unslash($_POST['_wpnonce']??''),'dashless_consent'))throw new Failure('invalid_consent','Reopen the connection request.',403);
                    $this->emit($this->app->oauth->approve($query,get_current_user_id(),($_POST['decision']??'')==='allow'));
                }
                if($method!=='GET')$this->json(['error'=>'method_not_allowed'],405);
                Screens::consent($query);exit;
            }
            if($path==='/oauth/token') {
                if($method!=='POST')$this->json(['error'=>'method_not_allowed'],405);
                $this->app->store->rate('oauth-token:'.($_SERVER['REMOTE_ADDR']??''),60,60);
                $this->emit($this->app->oauth->token(wp_unslash($_POST)));
            }
            if($path==='/oauth/revoke') {
                if($method!=='POST')$this->json(['error'=>'method_not_allowed'],405);
                $this->app->store->rate('revoke:'.($_SERVER['REMOTE_ADDR']??''),30,60);$this->app->oauth->revoke(wp_unslash($_POST));$this->json([]);
            }
            if($path==='/mcp') {
                if($method!=='POST'){header('Allow: POST');$this->json(['error'=>'method_not_allowed'],405);}
                if(!empty($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN']!==Config::origin())throw new Failure('origin_invalid','Request origin not allowed.',403);
                if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))$this->json(['error'=>'unsupported_media_type'],415);
                $accept=strtolower($_SERVER['HTTP_ACCEPT']??'');
                if(!str_contains($accept,'application/json') || !str_contains($accept,'text/event-stream'))$this->json(['error'=>'not_acceptable'],406);
                $version=$_SERVER['HTTP_MCP_PROTOCOL_VERSION']??'2025-03-26';
                if(!in_array($version,Chatgpt::PROTOCOLS,true))$this->json(['error'=>'unsupported_protocol_version'],400);
                $raw=file_get_contents('php://input',false,null,0,1048577);
                if(strlen($raw)>1048576)throw new Failure('request_too_large','Request too large.',413);
                $message=json_decode($raw,true);
                if(json_last_error()!==JSON_ERROR_NONE)$this->json(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Invalid JSON request']],400);
                if(!is_object(json_decode($raw)))$this->json(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Expected one JSON-RPC object']],400);
                $auth=['owner'=>0,'scopes'=>[]];
                if(!in_array($message['method']??'',['initialize','ping','tools/list','resources/list','resources/templates/list','resources/read','notifications/initialized'],true) || !empty($_SERVER['HTTP_AUTHORIZATION'])) {
                    try {$auth=$this->app->oauth->authenticate($_SERVER['HTTP_AUTHORIZATION']??'');}
                    catch(Failure $e) {
                        $challenge=Chatgpt::challenge();header('WWW-Authenticate: '.$challenge);
                        if(($message['method']??'')==='tools/call')$this->json(['jsonrpc'=>'2.0','id'=>$message['id']??null,'result'=>['content'=>[['type'=>'text','text'=>'Connect your Dashless account to continue.']],'isError'=>true,'_meta'=>['mcp/www_authenticate'=>[$challenge]]]],401);
                        throw $e;
                    }
                }
                $result=$this->app->mcp->handle($message,$auth);
                if($result===null){status_header(202);exit;}$this->json($result);
            }
        }catch(\League\OAuth2\Server\Exception\OAuthServerException $e){$response=$e->generateHttpResponse(new \Nyholm\Psr7\Response());$this->emit($path==='/oauth/authorize'?$this->app->oauth->withIssuer($response):$response);}
        catch(Failure $e){if($e->status===401)header('WWW-Authenticate: Bearer resource_metadata="'.Config::origin().'/.well-known/oauth-protected-resource"');$this->json(['error'=>$e->slug,'error_description'=>$e->getMessage()],$e->status);}
        catch(\Throwable $e){$this->json(['error'=>'service_unavailable','error_description'=>'This connection is not ready.'],503);}
    }
}
