<?php
namespace Dashless\Hub;
final class Routes {
    public function __construct(private App $app) {}
    public function register(): void {
        add_action('rest_api_init',function(){
            $public=fn()=>true;$private=fn()=>is_user_logged_in() && Identity::verified(get_current_user_id());
            $this->route('/account','GET',$private,fn()=>Screens::publicAccount($this->app->identity->account(get_current_user_id())));
            $this->route('/domain','GET',$private,fn()=>$this->app->domains->status(get_current_user_id()));
            $this->route('/domain','POST',$private,fn($r)=>$this->app->domains->start(get_current_user_id(),(string)$r['domain']));
            $this->route('/domain/verify','POST',$private,fn()=>$this->app->domains->verify(get_current_user_id()));
            $this->route('/domain/remove','POST',$private,fn()=>$this->app->domains->remove(get_current_user_id()));
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
            // Machine-only: the signed, short-lived Auth0 request is validated before wp_mail.
            $this->route('/auth0/email','POST',$public,fn($r)=>(new AuthMail($this->app->store))->deliver($r->get_body(),(string)$r->get_header('x-dashless-mail-time'),(string)$r->get_header('x-dashless-mail-signature')));
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
            if(is_user_logged_in())try{$a=$this->app->identity->account(get_current_user_id());$slug=$a['slug']??null;if(is_string($slug) && ($a['domain']??'')===Identity::slug($slug).'.'.Config::domain())$frame='https://'.$a['domain'];}catch(\Throwable $e){}
            header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'; connect-src 'self'; frame-src ".$frame);
        },-90);
    }
    public function browserPermission($r): bool {
        // WordPress application passwords and OAuth are not browser user events.
        $owner=get_current_user_id();$cookie=wp_validate_auth_cookie('', 'logged_in');
        return $owner>0 && (int)$cookie===$owner && Identity::verified($owner)
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
    private function json(array $body,int $status=200): never { nocache_headers();header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');wp_send_json($body,$status); }
    public function native(): void {
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
        if(in_array($path,['/auth/wordpress/start','/auth/wordpress/callback'],true))$this->json(['error'=>'signin_retired','error_description'=>'Please use the sign-in button on Dashless.'],410);
        if(in_array($path,['/auth/login','/auth/callback','/auth/logout'],true)) {
            nocache_headers();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
            try {
                if($method!=='GET')throw new Failure('method_not_allowed','Use the sign-in button on Dashless.',405);
                if($path==='/auth/logout') {
                    if(!wp_verify_nonce((string)wp_unslash($_GET['_wpnonce']??''),'dashless_logout'))throw new Failure('logout_expired','Please return to your account and try signing out again.',403);
                    wp_logout();
                    wp_redirect(Config::auth0Issuer().'/v2/logout?'.http_build_query(['client_id'=>Config::required('auth0_client_id'),'returnTo'=>Config::origin().'/']),303);exit;
                }
                $options=['expires'=>time()+600,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'];
                if($path==='/auth/login') {
                    $browser=bin2hex(random_bytes(32));
                    $url=$this->app->identity->begin((string)wp_unslash($_GET['return']??'/account/'),$browser);
                    setcookie('dashless_auth0_login',$browser,$options);
                    wp_redirect($url,303);exit;
                }
                $result=$this->app->identity->complete((string)wp_unslash($_GET['state']??''),(string)($_COOKIE['dashless_auth0_login']??''),(string)wp_unslash($_GET['code']??''),(string)wp_unslash($_GET['error']??''));
                $options['expires']=time()-3600;setcookie('dashless_auth0_login','',$options);
                wp_safe_redirect(Config::origin().$result['return'],303);exit;
            } catch(Failure $e) {wp_die(esc_html($e->getMessage()).' <a href="'.esc_url(Config::origin().'/sign-in/').'">Return to sign in</a>','Dashless sign-in',['response'=>$e->status]);}
        }
        if(!in_array($path,['/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/mcp','/.well-known/oauth-protected-resource/mcp/','/.well-known/oauth-authorization-server','/.well-known/oauth-authorization-server/mcp','/.well-known/oauth-authorization-server/mcp/','/oauth/authorize','/oauth/token','/oauth/revoke','/mcp'],true))return;
        try {
            if(str_starts_with($path,'/.well-known/')) {
                if($method!=='GET')$this->json(['error'=>'method_not_allowed'],405);
                $this->json(str_contains($path,'protected-resource')?$this->app->oauth->protectedMetadata():$this->app->oauth->metadata());
            }
            if(str_starts_with($path,'/oauth/'))$this->json(['error'=>'connection_retired','error_description'=>'Reconnect Dashless in ChatGPT to use the new sign-in.'],410);
            if($path==='/mcp') {
                // Discovery clients probe GET before a tool call. Authenticate before
                // rejecting an unsupported stream so they receive the OAuth challenge.
                if($method==='GET')$this->app->oauth->authenticate($_SERVER['HTTP_AUTHORIZATION']??'');
                if($method!=='POST'){header('Allow: POST');$this->json(['error'=>'method_not_allowed'],405);}
                if(!empty($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN']!==Config::origin())throw new Failure('origin_invalid','Request origin not allowed.',403);
                // OAuth discovery may send an empty octet-stream POST. Challenge
                // unauthenticated probes before enforcing JSON transport headers.
                if(empty($_SERVER['HTTP_AUTHORIZATION']) && !str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))throw new Failure('authentication_required','Connect your Dashless account.',401);
                if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))$this->json(['error'=>'unsupported_media_type'],415);
                // Dashless returns JSON for every request. Accept clients that request
                // JSON alone as well as clients that advertise streaming support.
                $accept=strtolower($_SERVER['HTTP_ACCEPT']??'');
                if($accept!=='' && !str_contains($accept,'application/json'))$this->json(['error'=>'not_acceptable'],406);
                $version=$_SERVER['HTTP_MCP_PROTOCOL_VERSION']??'2025-03-26';
                if(!in_array($version,Chatgpt::PROTOCOLS,true))$this->json(['error'=>'unsupported_protocol_version'],400);
                $raw=file_get_contents('php://input',false,null,0,1048577);
                if(strlen($raw)>1048576)throw new Failure('request_too_large','Request too large.',413);
                $message=json_decode($raw,true);
                if(json_last_error()!==JSON_ERROR_NONE)$this->json(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Invalid JSON request']],400);
                if(!is_object(json_decode($raw)))$this->json(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Expected one JSON-RPC object']],400);
                $auth=['owner'=>0,'scopes'=>[]];
                // Discovery calls stay public even when a client includes a bearer
                // token. OpenAI's scanner sends its OAuth token on tools/list, but
                // listing capabilities must not depend on user scopes.
                if(!in_array($message['method']??'',['initialize','ping','tools/list','resources/list','resources/templates/list','resources/read','notifications/initialized'],true)) {
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
        }catch(Failure $e){if($e->status===401)header('WWW-Authenticate: '.Chatgpt::challenge());$this->json(['error'=>$e->slug,'error_description'=>$e->getMessage()],$e->status);}
        catch(\Throwable $e){$this->json(['error'=>'service_unavailable','error_description'=>'This connection is not ready.'],503);}
    }
}
