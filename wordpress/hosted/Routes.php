<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

final class Routes {
    private const NS='dashless-hosted/v1';
    private const ID='[a-f0-9-]{36}';
    public function __construct(private App $app) {}
    public static function mimes(): array { return ['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','mp3'=>'audio/mpeg','mp4'=>'video/mp4','pdf'=>'application/pdf']; }
    private function endpoint(string $path,string $method,callable $fn,bool $service=true): void {
        register_rest_route(self::NS,$path,['methods'=>$method,'permission_callback'=>function($r)use($service){try { if ($service) { $this->app->authorize($r); }return true; }catch(Failure $e) { return new \WP_Error($e->slug,$e->getMessage(),['status'=>$e->status]); }},'callback'=>function($r)use($fn){try { $v=$fn($r);return $v instanceof \WP_REST_Response?$v:new \WP_REST_Response($this->app->envelope($v)); }catch(Failure $e) { return new \WP_REST_Response($this->app->envelope(['code'=>$e->slug,'message'=>$e->getMessage()]),$e->status); }catch(\Throwable $e) { return new \WP_REST_Response($this->app->envelope(['code'=>'operation_failed','message'=>'This operation could not be completed. Retry the same request.']),500); }}]);
    }
    public function register(): void {
        add_filter('rest_post_dispatch',function($response,$server,$request){
            if (str_starts_with($request->get_route(),'/'.self::NS)) {
                foreach (Support::noCache() as $key=>$value) { $response->header($key,$value); }
                if ($response->is_error()) { $data=$response->get_data();$response->set_data($this->app->envelope($data)); }
            }return $response;
        },10,3);
        add_filter('rest_pre_serve_request',function($served,$result,$request){
            if (!str_starts_with($request->get_route(),'/'.self::NS)) { return $served; }
            $d=$result->get_data();if (is_array($d) && isset($d['__dashless_body'])) { echo $d['__dashless_body'];return true; }
            if (is_array($d) && isset($d['__dashless_export'])) { $this->app->vault()->stream($d['__dashless_export']);return true; }
            return $served;
        },10,3);
        $this->endpoint('/builder','POST',fn($r)=>(new RemoteRuntime($this->app))->configure($r->get_json_params()??[]));
        $this->endpoint('/capabilities','GET',fn()=>$this->app->capabilities());
        $this->endpoint('/health','GET',fn()=>['ready'=>$this->app->runtime->ready() && (bool)$this->app->store->get('release','active'),'release_id'=>$this->app->store->get('release','active')['data']['release_id']??null,'runtime_status'=>$this->app->runtime->ready()?'verified':'not_installed','quota'=>$this->app->quota()]);
        $this->endpoint('/diagnostics/queue','GET',function(){
            $rows=[];foreach($this->app->store->rows('job') as $job){$rows[]=array_intersect_key($job,array_flip(['job_id','owner','kind','status','attempts','created_at','started_at','lease_expires','continuation_required','poll_after']));}
            return ['jobs'=>$rows,'quota'=>$this->app->quota()];
        });
        $this->endpoint('/entitlement','POST',fn($r)=>$this->app->entitlement($r->get_json_params()??[]));
        $this->endpoint('/jobs','POST',function($r){$this->app->active();$a=$r->get_json_params()??[];
            if (($a['kind']??'')!=='initial_release') { throw new Failure('invalid_job','Unsupported provisioning job.',400); }
            Support::uuid((string)($a['job_id']??''));
            return new \WP_REST_Response($this->app->envelope($this->app->store->once(0,'initial_release',(string)($a['idempotency_key']??''),$a,fn()=>$this->app->jobs->enqueue('initial_release',[],0,$a['job_id']))),202);
        });
        $this->endpoint('/jobs/(?P<id>'.self::ID.')','GET',fn($r)=>$this->app->jobs->public($this->app->jobs->get($r['id'])));
        $this->endpoint('/tools/(?P<name>[a-z_]+)','POST',fn($r)=>$this->app->tool($r['name'],$r->get_json_params()??[]));
        $this->endpoint('/approvals','POST',fn($r)=>$this->app->approve($r->get_json_params()??[]));
        $this->endpoint('/approvals/rollback','POST',fn($r)=>$this->app->approve($r->get_json_params()??[],true));
        $this->endpoint('/previews/(?P<id>'.self::ID.')/browser','POST',fn($r)=>$this->ticket('preview',$r['id'],$r->get_json_params()??[]));
        $this->endpoint('/previews/(?P<id>'.self::ID.')/open','GET',fn($r)=>$this->openPreview($r),false);
        $this->endpoint('/previews/(?P<id>'.self::ID.')/files/(?P<path>.*)','GET',fn($r)=>$this->previewFile($r),false);
        $this->endpoint('/exports/(?P<id>'.self::ID.')/download','POST',fn($r)=>$this->ticket('export',$r['id'],$r->get_json_params()??[]));
        $this->endpoint('/exports/(?P<id>'.self::ID.')/file','GET',fn($r)=>$this->exportFile($r),false);
        $this->endpoint('/uploads/(?P<id>'.self::ID.')/content','PUT,POST',fn($r)=>$this->upload($r));
        $this->endpoint('/media/(?P<id>\d+)','GET',fn($r)=>$this->media($r));
        $this->endpoint('/diagnostics/uncached-page','GET',function(){
            $this->app->active();$active=$this->app->store->get('release','active')['data']??null;
            if ($active && empty($active['verified'])) { $active=$active['fallback']??null; }
            if ($active) { $body=$this->artifact($active['candidate'],'index.html',''); }
            else {
                // Operator adoption probe: render the full current local-workflow homepage, not a synthetic health response.
                $release=get_option(DASHLESS_WPCLOUD_OPTION,[]);$dir=dashless_wpcloud_releases_directory().'/'.($release['id']??'');$file=dashless_wpcloud_resolve_file($dir,'/');
                if (!$file) { throw new Failure('page_missing','There is no public homepage to measure.',409); }
                $body=(string)file_get_contents($file);
            }
            $r=$this->binary($body,'text/html; charset=UTF-8');$r->header('X-Dashless-Cache','bypass');$r->header('X-Dashless-Page-SHA256',hash('sha256',$body));return $r;
        });
    }
    private function ticket(string $kind,string $id,array $a): array {
        $this->app->active($kind==='export');$owner=$this->app->owner($a['account_id']??($this->app->config()['account_id']??null));
        $r=$this->app->store->owned($kind,$id,$owner);
        if ($kind==='preview' && $r['expires']<time()) { throw new Failure('preview_expired','This preview expired.',410); }
        if ($kind==='export' && empty($r['complete'])) { throw new Failure('export_pending','The export is not ready.',409); }
        $token=bin2hex(random_bytes(32));$until=time()+max(1,min(60,(int)($a['ttl']??60)));
        $this->app->store->put('ticket',hash('sha256',$token),['kind'=>$kind,'id'=>$id,'owner'=>$owner,'expires'=>$until,'used'=>false],$owner);
        $url=rest_url(self::NS.'/'.($kind==='preview'?'previews':'exports').'/'.$id.'/'.($kind==='preview'?'open':'file'));
        return ['url'=>add_query_arg('ticket',$token,$url),'expires_at'=>gmdate('c',$until)];
    }
    private function consumeTicket(string $kind,string $id,string $token): array {
        $this->app->active($kind==='export');
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) { throw new Failure('unauthorized','An authorized browser handoff is required.',401); }
        return $this->app->store->transaction(function()use($kind,$id,$token){
            $key=hash('sha256',$token);$r=$this->app->store->get('ticket',$key);$d=$r['data']??[];
            if (!$r || $d['kind']!==$kind || $d['id']!==$id || $d['used'] || $d['expires']<=time()) { throw new Failure('ticket_expired','Reopen this artifact from your account.',401); }
            $this->app->owner($d['owner']);$d['used']=true;$this->app->store->put('ticket',$key,$d,$d['owner']);return $d;
        });
    }
    private function cookie(string $id): string { return '__Secure-dashless-preview-'.$id; }
    private function openPreview(\WP_REST_Request $r): \WP_REST_Response {
        $d=$this->consumeTicket('preview',$r['id'],(string)$r->get_param('ticket'));
        $secret=bin2hex(random_bytes(32));$this->app->store->put('session',hash('sha256',$secret),['preview_id'=>$r['id'],'owner'=>$d['owner'],'expires'=>time()+900],$d['owner']);
        $path=wp_parse_url(rest_url(self::NS.'/previews/'.$r['id'].'/'),PHP_URL_PATH);
        $response=new \WP_REST_Response(null,303);$response->header('Location',rest_url(self::NS.'/previews/'.$r['id'].'/files/index.html'));
        $response->header('Set-Cookie',$this->cookie($r['id']).'='.$secret.'; Path='.$path.'; Max-Age=900; Secure; HttpOnly; SameSite=None');return $response;
    }
    private function previewFile(\WP_REST_Request $r): \WP_REST_Response {
        $this->app->active();$token=isset($_COOKIE[$this->cookie($r['id'])])?sanitize_text_field(wp_unslash($_COOKIE[$this->cookie($r['id'])])):'';
        $s=preg_match('/^[a-f0-9]{64}$/D',$token)?$this->app->store->get('session',hash('sha256',$token)):null;
        if (!$s || $s['data']['expires']<=time() || $s['data']['preview_id']!==$r['id']) { throw new Failure('unauthorized','Open this private preview from your account.',401); }
        $owner=$this->app->owner($s['owner']);$p=$this->app->store->owned('preview',$r['id'],$owner);
        if ($p['expires']<time()) { throw new Failure('preview_expired','This preview expired.',410); }
        $path=$this->resolve($p['candidate'],(string)$r['path']);$base=wp_parse_url(rest_url(self::NS.'/previews/'.$r['id'].'/files'),PHP_URL_PATH);
        return $this->binary($this->artifact($p['candidate'],$path,$base),$this->mime($path));
    }
    private function exportFile(\WP_REST_Request $r): \WP_REST_Response {
        $d=$this->consumeTicket('export',$r['id'],(string)$r->get_param('ticket'));$e=$this->app->store->owned('export',$r['id'],$d['owner']);
        if (empty($e['complete'])) { throw new Failure('export_pending','The export is not ready.',409); }
        $tar=($e['format']??'zip')==='tar';$response=new \WP_REST_Response(['__dashless_export'=>$e['key']]);$response->header('Content-Type',$tar?'application/x-tar':'application/zip');$response->header('Content-Disposition','attachment; filename="blog-export.'.($tar?'tar':'zip').'"');$response->header('Content-Length',(string)$e['bytes']);return $response;
    }
    private function upload(\WP_REST_Request $r): array {
        $this->app->active();$owner=$this->app->owner(filter_var($r->get_header('x-dashless-account-id'),FILTER_VALIDATE_INT)?:null);
        return $this->app->store->transaction(function()use($r,$owner){
            $u=$this->app->store->owned('upload',$r['id'],$owner);$body=$r->get_body();$hash=hash('sha256',$body);
            if ($u['complete']) { if (!hash_equals($u['sha256'],$hash)) { throw new Failure('upload_conflict','This upload already completed with different data.'); }return ['media_id'=>$u['media_id'],'saved'=>true]; }
            if ($u['expires']<=time() || strlen($body)>$u['max_bytes'] || $body==='') { throw new Failure('upload_invalid','The upload expired or exceeds its size limit.',400); }
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($body);$expected=wp_check_filetype($u['filename'],self::mimes())['type'];
            if (!$expected || $mime!==$expected || str_contains(substr($body,0,4096),'<?php')) { throw new Failure('unsupported_media','The file content does not match an accepted media type.',400); }
            $key='media:'.$r['id'];$workspace=$this->app->runtime->workspace();$file=$workspace.'/upload';
            try { Support::write($file,$body);$meta=$this->app->vault()->putFile($key,$file);$dims=str_starts_with($mime,'image/')?getimagesize($file):false; }
            finally { Runtime::removeWorkspace($workspace); }
            $id=wp_insert_attachment(['post_title'=>pathinfo($u['filename'],PATHINFO_FILENAME),'post_status'=>'inherit','post_mime_type'=>$mime,'post_author'=>$this->app->config()['service_user']],false,0,true);
            if (is_wp_error($id)) { throw new Failure('upload_failed','WordPress could not save this media.',503); }
            update_post_meta($id,'_dashless_private_media',['key'=>$key,'filename'=>$u['filename']]+$meta);update_post_meta($id,'_wp_attachment_image_alt',$u['alt_text']);
            if ($dims) { wp_update_attachment_metadata($id,['width'=>$dims[0],'height'=>$dims[1],'file'=>$u['filename'],'sizes'=>[]]); }
            $u['complete']=true;$u['expires']=0;$u['sha256']=$hash;$u['media_id']=$id;$this->app->store->put('upload',$r['id'],$u,$owner);return ['media_id'=>$id,'saved'=>true];
        });
    }
    private function media(\WP_REST_Request $r): \WP_REST_Response {
        $this->app->active(true);$id=(int)$r['id'];$m=$this->app->content->media($id);$private=get_post_meta($id,'_dashless_private_media',true);
        if (!$private) { throw new Failure('media_missing','This media has no private upload.',404); }
        $meta=json_decode($this->app->vault()->get($private['key'].':meta'),true);$bytes='';for ($i=0;$i<$meta['chunks'];$i++) { $bytes.=$this->app->vault()->get($private['key'].':'.$i); }
        return $this->binary($bytes,$m['mime_type']);
    }
    public function resolve(string $candidate,string $path): string {
        $path=ltrim($path,'/');if ($path==='') { $path='index.html'; }elseif (str_ends_with($path,'/')) { $path.='index.html'; }
        Support::relative($path);$manifest=$this->app->store->get('candidate',$candidate)['data']['manifest']??null;
        foreach ($manifest['files']??[] as $entry) { if ($entry['path']===$path) { return $path; } }
        throw new Failure('artifact_missing','The requested page or asset was not found.',404);
    }
    public function artifact(string $candidate,string $path,string $base): string {
        $path=$this->resolve($candidate,$path);$m=json_decode($this->app->vault()->get('candidate:'.$candidate.':'.$path.':meta'),true);$data='';
        for ($i=0;$i<$m['chunks'];$i++) { $data.=$this->app->vault()->get('candidate:'.$candidate.':'.$path.':'.$i); }
        if (!hash_equals($m['sha256'],hash('sha256',$data))) { throw new Failure('artifact_invalid','Artifact integrity verification failed.',503); }
        if (preg_match('/\.(html|css|xml|txt|json)$/',$path)) { $data=str_replace('/__DASHLESS_BASE__',$base,$data); }
        return $data;
    }
    private function mime(string $path): string { return match(strtolower(pathinfo($path,PATHINFO_EXTENSION))) {'html'=>'text/html; charset=UTF-8','css'=>'text/css; charset=UTF-8','xml'=>'application/xml; charset=UTF-8','txt'=>'text/plain; charset=UTF-8','svg'=>'image/svg+xml','js'=>'text/javascript; charset=UTF-8','json'=>'application/json; charset=UTF-8',default=>wp_check_filetype($path,self::mimes())['type']?:'application/octet-stream'}; }
    private function csp(string $body,string $mime,bool $preview): string {
        $hashes=[];
        if (str_starts_with($mime,'text/html')) {
            preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/si',$body,$scripts,PREG_SET_ORDER);
            foreach ($scripts as $script) { if (!preg_match('/\bsrc\s*=|application\/(?:ld\+)?json/i',$script[1])) { $hashes[]="'sha256-".base64_encode(hash('sha256',$script[2],true))."'"; } }
        }
        $scriptPolicy="script-src 'self' ".implode(' ',array_unique($hashes))."; ";
        return $scriptPolicy."default-src 'none'; connect-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; media-src 'self'; font-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors ".($preview?"'self' ".$this->app->config()['hub']:"'none'");
    }
    private function binary(string $body,string $mime): \WP_REST_Response {
        $r=new \WP_REST_Response(['__dashless_body'=>$body]);$r->header('Content-Type',$mime);$r->header('Content-Security-Policy',$this->csp($body,$mime,true));return $r;
    }
    public function publicPage(): void {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
        try { $this->app->active(); }
        catch(Failure $e) { foreach (Support::noCache() as $k=>$v) { header($k.': '.$v); }status_header(503);echo 'This blog is temporarily unavailable.';exit; }
        $active=$this->app->store->get('release','active')['data']??null;
        if ($active && empty($active['verified'])) {
            $verify=isset($_SERVER['HTTP_X_DASHLESS_VERIFY'])?sanitize_text_field(wp_unslash($_SERVER['HTTP_X_DASHLESS_VERIFY'])):'';
            if (!$verify || !hash_equals($active['verification_hash'],hash('sha256',$verify))) { $active=$active['fallback']??null; }
        }
        if (!$active) { return; } // Adoption keeps the current local release online until an approved hosted deploy.
        $uri=isset($_SERVER['REQUEST_URI'])?esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])):'/';$path=(string)wp_parse_url($uri,PHP_URL_PATH);
        try { $file=$this->resolve($active['candidate'],$path);$status=200; }
        catch(Failure $e) { $file='404.html';$status=404; }
        try { $body=$this->artifact($active['candidate'],$file,''); }
        catch(Failure $e) { status_header(503);echo 'This page is temporarily unavailable.';exit; }
        foreach (Support::noCache() as $k=>$v) { if ($k!=='X-Robots-Tag') { header($k.': '.$v); } }
        status_header($status);header('Content-Type: '.$this->mime($file));header('X-Dashless-Release: '.$active['release_id']);header('X-Dashless-Content-Generation: '.$active['generation']);header('ETag: "'.hash('sha256',$body).'"');header('Referrer-Policy: strict-origin-when-cross-origin');header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Content-Security-Policy: '.$this->csp($body,$this->mime($file),false));
        $method=isset($_SERVER['REQUEST_METHOD'])?sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])):'GET';if ($method!=='HEAD') { echo $body; }exit;
    }
}
