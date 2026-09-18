<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

final class App {
    private static ?self $instance=null;
    public Store $store;
    public Content $content;
    public Jobs $jobs;
    public Runtime $runtime;
    public function __construct() { $this->store=new Store();$this->content=new Content($this);$this->runtime=new Runtime($this);$this->jobs=new Jobs($this); }
    public static function boot(): self {
        if (self::$instance) { return self::$instance; }
        $app=self::$instance=new self();
        add_action('rest_api_init',fn()=>(new Routes($app))->register());
        foreach (['save_post','deleted_post','set_object_terms','created_term','edited_term','delete_term','added_post_meta','updated_post_meta','deleted_post_meta'] as $hook) { add_action($hook,fn()=>$app->content->changed()); }
        if ($app->config()) {
            add_action('dashless_hosted_cleanup',function()use($app){$result=$app->cleanupExpired();update_option('dashless_hosted_last_cleanup',['time'=>time()]+$result,false);});
            if (!wp_next_scheduled('dashless_hosted_cleanup')) wp_schedule_event(time()+300,'hourly','dashless_hosted_cleanup');
            if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
            // All public requests pass entitlement checks; static candidates stay encrypted.
            add_action('template_redirect',fn()=>(new Routes($app))->publicPage(),-20000);
            add_filter('rest_pre_dispatch',function($result,$server,$request)use($app){
                if (str_starts_with($request->get_route(),'/dashless-hosted/v1')) { return $result; }
                if (current_user_can('manage_options') || !empty($app->config()['adopted'])) { return $result; }
                return new \WP_Error('dashless_private','Use the authenticated hosted service.',['status'=>403]);
            },-100,3);
            add_filter('wp_sitemaps_enabled','__return_false');
            add_filter('xmlrpc_enabled','__return_false');
            remove_action('publish_future_post','check_and_publish_future_post');
        }
        if (defined('WP_CLI') && WP_CLI) { Cli::register($app); }
        return $app;
    }
    public function config(): array { return get_option('dashless_hosted_config',[]); }
    public function siteId(): int { return (int)($this->config()['site_id']??0); }
    public function vault(): Vault { return new Vault(); }
    public function cleanupExpired(int $limit=100): array {
        $removed=['records'=>0,'vault_blobs'=>0,'media'=>0];
        // Cleanup can delete hundreds of encrypted chunks; keep the lease longer
        // than the cron interval so a slow run cannot be overlapped after 5 minutes.
        $lease=$this->store->lock('maintenance',900);
        if (!$lease) { return $removed+['locked'=>true]; }
        try {
        foreach (['ticket','session','approval','upload','preview','export','idempotency'] as $kind) {
            foreach ($this->store->expired($kind,time(),$limit) as $row) {
                $d=$row['data']; $keys=[];
                if ($kind==='preview' && !empty($d['candidate'])) {
                    $c=$this->store->get('candidate',$d['candidate'])['data']??[];
                    $active=$this->store->get('release','active')['data']??[];$previous=$this->store->get('release','previous')['data']??[];
                    $protected=in_array($d['candidate'],[$active['candidate']??null,$previous['candidate']??null],true);
                    if (!$protected) { foreach (($c['manifest']['files']??[]) as $f) { $base='candidate:'.$d['candidate'].':'.$f['path']; try { $meta=json_decode($this->vault()->get($base.':meta'),true)?:[]; } catch (\Throwable $e) { $meta=[]; } for($i=0;$i<(int)($meta['chunks']??0);$i++)$keys[]=$base.':'.$i; $keys[]=$base.':meta'; } $keys[]='snapshot:'.$d['candidate']; $this->store->deleteKey('candidate',$d['candidate']); }
                } elseif ($kind==='export' && !empty($d['export_id'])) {
                    $keys[]='export-plan:'.$d['export_id']; for($i=0;$i<(int)($d['chunks']??0);$i++)$keys[]='export:'.$d['export_id'].':'.$i; $keys[]='export:'.$d['export_id'].':meta';
                } elseif ($kind==='upload' && !empty($d['upload_id'])) {
                    if (!empty($d['complete'])) {
                        // A completed upload owns durable media. Remove the expiry marker so
                        // cleanup does not rescan it every hour or delete customer content.
                        $d['expires']=0;$this->store->put('upload',$d['upload_id'],$d,$row['owner'],0);continue;
                    }
                    try { $meta=json_decode($this->vault()->get('media:'.$d['upload_id'].':meta'),true)?:[]; } catch (\Throwable $e) { $meta=[]; }
                    for($i=0;$i<(int)($meta['chunks']??0);$i++)$keys[]='media:'.$d['upload_id'].':'.$i; $keys[]='media:'.$d['upload_id'].':meta';
                    $removed['media']++;
                }
                $removed['vault_blobs']+=$this->vault()->deleteKeys($keys); $this->store->deleteRecord($row['id']); $removed['records']++;
            }
        }
        // Keep terminal job receipts for support and idempotent polling, then prune them
        // after the documented retention window. Active work is never touched.
        $cutoff=time()-30*DAY_IN_SECONDS;
        foreach ($this->store->rows('job') as $job) {
            if (!in_array($job['status']??'', ['succeeded','failed','canceled'],true)) continue;
            $when=strtotime((string)($job['finished_at']??$job['created_at']??''));
            if ($when>0 && $when<=$cutoff && !empty($job['job_id'])) { $this->store->deleteKey('job',$job['job_id']);$removed['records']++; }
        }
        return $removed;
        } finally { $this->store->unlock('maintenance',$lease); }
    }
    public function envelope(array $data): array { return ['contract_version'=>1,'site_id'=>$this->siteId()]+$data; }
    public function active(bool $export=false): void {
        $c=$this->config();
        if (!$c) { throw new Failure('not_bootstrapped','This site has not been initialized.',503); }
        $e=$this->store->get('entitlement','current')['data']??[];
        if (!empty($e['active'])) { return; }
        if ($export && ($e['retain_until']??0)>time()) { return; }
        throw new Failure($export?'retention_expired':'site_inactive',$export?'The recovery period has ended.':'This blog is temporarily unavailable.',403);
    }
    public function authorize(\WP_REST_Request $r): void {
        $c=$this->config();$header=(string)$r->get_header('authorization');
        if ($r->get_header('x-dashless-contract')!=='1' || !preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$m) || !$c) { throw new Failure('unauthorized','Authentication is required.',401); }
        $hash=hash('sha256',$m[1]);$ok=hash_equals($c['credential_hash'],$hash);
        if (!$ok && ($c['previous_until']??0)>time()) { $ok=hash_equals($c['previous_hash'],$hash); }
        if (!$ok) { throw new Failure('unauthorized','Authentication is required.',401); }
    }
    public function owner(mixed $value): int {
        if (!is_int($value) || $value<1) { throw new Failure('invalid_actor','An authenticated account is required.',403); }
        $record=$this->store->get('owner','primary');$owner=$record['owner']??0;
        // A unique durable claim, not a cached option, arbitrates the initial account binding.
        if (!$record) {
            $owner=$this->store->transaction(function()use($value){
                $claim=$this->store->get('owner','primary');$c=$this->config();
                if (!$claim) { $bound=(int)($c['account_id']??0)?:$value;$this->store->add('owner','primary',[],$bound);$claim=$this->store->get('owner','primary'); }
                if ($claim['owner']!==$value) { throw new Failure('wrong_account','This account does not own this site.',403); }
                if (empty($c['account_id'])) { $c['account_id']=$claim['owner'];update_option('dashless_hosted_config',$c,false); }
                return $claim['owner'];
            });
        }
        if ($owner!==$value) { throw new Failure('wrong_account','This account does not own this site.',403); }
        return $value;
    }
    public function capabilities(): array {
        $ready=$this->runtime->ready();
        return ['plugin_version'=>Support::VERSION,'capabilities'=>['jobs'=>true,'private_previews'=>true,'approvals'=>true,'exports'=>true,'design'=>true,'theme_catalog_v1'=>true,'runtime'=>$ready,'chatgpt_upload_v1'=>true,'rollback_approval_v1'=>true],'runtime_sha256'=>$this->runtime->digest(),'runtime_status'=>$ready?'verified':'not_installed'];
    }
    public function quota(): array {
        $rows=$this->store->rows('job'); $mine=array_values(array_filter($rows,fn($j)=>(int)($j['owner']??0)===(int)($this->config()['account_id']??0)));
        return ['limits'=>Support::QUOTAS,'usage'=>['queued_jobs'=>count(array_filter($mine,fn($j)=>in_array($j['status']??'', ['queued','running'],true))),'retained_jobs'=>count($mine),'previews'=>$this->store->count('preview',(int)($this->config()['account_id']??0),true),'private_storage_bytes'=>$this->vault()->bytes()]];
    }
    public function entitlement(array $a): array {
        if (!is_bool($a['active']??null)) { throw new Failure('invalid_entitlement','Expected an active boolean.',400); }
        return $this->store->transaction(function()use($a){
            $old=$this->store->get('entitlement','current')['data']??[];
            $until=$a['retain_until']??(empty($old['active'])&&isset($old['retain_until'])?$old['retain_until']:time()+30*DAY_IN_SECONDS);
            if (is_string($until)) { $until=strtotime($until); }
            $e=['active'=>$a['active'],'retain_until'=>$a['active']?0:min(time()+30*DAY_IN_SECONDS,(int)$until)];
            $this->store->put('entitlement','current',$e);Support::purge();return $e;
        });
    }
    public function tool(string $name,array $body): array {
        $owner=$this->owner($body['actor']['account_id']??null);$a=$body['arguments']??[];
        $schemas=Support::json(is_file(__DIR__.'/tools.v1.json')?__DIR__.'/tools.v1.json':dirname(__DIR__,2).'/hosted/hub/contracts/tools.v1.json');$schema=null;
        foreach ($schemas as $tool) { if ($tool['name']===$name) { $schema=$tool;break; } }
        if (!$schema || !is_array($a) || is_wp_error(rest_validate_value_from_schema($a,$schema['inputSchema'],'arguments'))) { throw new Failure('invalid_arguments','Arguments do not match a supported tool.',400); }
        $this->active(in_array($name,['get_status','get_job','export_site'],true));
        $invoke=function()use($name,$a,$owner){
            if ($name==='get_status') { $active=$this->store->get('release','active')['data']??[];return ['ready'=>$this->runtime->ready(),'generation'=>$this->content->generation(),'entitlement'=>$this->store->get('entitlement','current')['data'],'quota'=>$this->quota(),'storage'=>['last_cleanup'=>get_option('dashless_hosted_last_cleanup',[])],'queue'=>['active_jobs'=>count(array_filter($this->store->rows('job'),fn($j)=>in_array($j['status']??'', ['queued','running'],true)))],'last_release'=>array_intersect_key($active,array_flip(['release_id','generation','verified']))]; }
            if ($name==='get_job') { return $this->jobs->public($this->store->owned('job',$a['job_id'],$owner)); }
            if ($name==='get_release') {
                $safe=fn($r)=>$r?array_intersect_key($r,array_flip(['release_id','manifest_hash','generation','verified'])):null;
                $active=$this->store->get('release','active')['data']??null;if ($active && empty($active['verified'])) { $active=$active['fallback']??null; }
                return ['active'=>$safe($active),'previous'=>$safe($this->store->get('release','previous')['data']??null)];
            }
            if ($name==='create_preview') { $this->content->target($a,$owner);return $this->jobs->enqueue('preview',$a,$owner); }
            if ($name==='export_site') { return $this->jobs->enqueue('export',$a,$owner); }
            if ($name==='request_publication_approval') {
                $p=$this->store->owned('preview',$a['preview_id'],$owner);$this->content->assertCurrent($p);
                return ['preview_id'=>$a['preview_id'],'requires_human_approval'=>true,'preview_ready'=>true];
            }
            if (in_array($name,['publish_previewed','rollback_release'],true)) { return $this->consumeApproval($name,$a,$owner); }
            if ($name==='create_media_upload') {
                $filename=sanitize_file_name($a['filename']);$type=wp_check_filetype($filename,Routes::mimes());
                if (!$filename || !$type['type']) { throw new Failure('unsupported_media','Use a JPEG, PNG, WebP, GIF, MP3, MP4 or PDF file.',400); }
                $max=Support::QUOTAS['upload_bytes'];if($this->vault()->bytes()>=Support::QUOTAS['private_storage_bytes'])throw new Failure('storage_quota','Your private media storage is full. Remove an upload or wait for cleanup before adding another file.',429);
                $id=wp_generate_uuid4();$r=['upload_id'=>$id,'filename'=>$filename,'alt_text'=>sanitize_text_field($a['alt_text']),'expires'=>time()+900,'complete'=>false,'max_bytes'=>$max];$this->store->put('upload',$id,$r,$owner);
                return ['upload_id'=>$id,'expires_at'=>gmdate('c',$r['expires']),'max_bytes'=>$r['max_bytes'],'accepted_mime_types'=>array_values(Routes::mimes()),'transfer'=>'hub_binary_relay'];
            }
            return $this->content->tool($name,$a,$owner);
        };
        if ($schema['annotations']['readOnlyHint']) { return $invoke(); }
        $key=$body['idempotency_key']??$a['client_key']??null;
        return $key?$this->store->once($owner,$name,$key,$a,$invoke):$this->store->transaction($invoke);
    }
    public function approve(array $body,bool $rollback=false): array {
        $this->active();$owner=$this->owner($body['actor']['account_id']??null);
        if (($body['actor']['source']??'')!=='authenticated_browser') { throw new Failure('human_approval_required','Approval must come from the authenticated browser.',403); }
        return $this->store->once($owner,$rollback?'rollback_approval':'approval',(string)($body['idempotency_key']??''),$body,function()use($body,$owner,$rollback){
            if ($rollback) {
                $previous=$this->store->get('release','previous')['data']??null;
                if (!$previous || ($body['release_id']??'')!==$previous['release_id']) { throw new Failure('rollback_missing','That verified rollback target is unavailable.'); }
                $binding=['site_id'=>$this->siteId(),'account_id'=>$owner,'release_id'=>$previous['release_id'],'manifest_hash'=>$previous['manifest_hash'],'active'=>$this->store->get('release','active')['data']['release_id']??null,'generation'=>$this->content->generation()];
            } else {
                $p=$this->store->owned('preview',(string)($body['preview_id']??''),$owner);$this->content->assertCurrent($p);
                if ($p['expires']<time()) { throw new Failure('preview_expired','Create a new preview before publishing.'); }
                $binding=$p['binding']+['preview_id'=>$p['preview_id'],'manifest_hash'=>$p['manifest_hash']];
            }
            $id=wp_generate_uuid4();$this->store->put('approval',$id,['approval_id'=>$id,'kind'=>$rollback?'rollback':'publish','binding'=>$binding,'expires'=>time()+900,'consumed_by'=>null],$owner);
            return ['approval_id'=>$id,'expires_at'=>gmdate('c',time()+900)];
        });
    }
    private function consumeApproval(string $name,array $a,int $owner): array {
        $r=$this->store->owned('approval',$a['approval_id'],$owner);$kind=$name==='rollback_release'?'rollback':'publish';
        if ($r['kind']!==$kind || $r['expires']<time() || $r['consumed_by']) { throw new Failure('approval_unavailable','This approval expired or has already been used.'); }
        if ($kind==='publish') {
            $p=$this->store->owned('preview',$a['preview_id'],$owner);$this->content->assertCurrent($p);
            if ($r['binding']!==$p['binding']+['preview_id'=>$p['preview_id'],'manifest_hash'=>$p['manifest_hash']]) { throw new Failure('stale_approval','This approval does not match the preview.'); }
        } else {
            $previous=$this->store->get('release','previous')['data']??[];
            if ($r['binding']['release_id']!==$a['release_id'] || $previous['release_id']!==$a['release_id'] || $r['binding']['manifest_hash']!==$previous['manifest_hash'] || $r['binding']['active']!==($this->store->get('release','active')['data']['release_id']??null)) { throw new Failure('stale_approval','The rollback target changed.'); }
        }
        $job=$this->jobs->enqueue($kind,$a,$owner);$r['consumed_by']=$job['job_id'];$this->store->put('approval',$a['approval_id'],$r,$owner);return $job;
    }
}
