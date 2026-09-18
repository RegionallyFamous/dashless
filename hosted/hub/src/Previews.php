<?php
namespace Dashless\Hub;
/** Human-only browser fallback. OAuth tools cannot call these cookie+nonce routes to mint approvals. */
final class Previews {
    public function __construct(private App $app) {}
    private function account(int $owner): array {
        $a=$this->app->identity->account($owner);
        if($a['state']!=='ready' || !in_array($a['entitlement']??'',['active','grace'],true))throw new Failure('site_inactive','This blog cannot publish right now.',403);
        return $a;
    }
    public function approve(int $owner,string $preview,string $handoff=''): array {
        if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/',$preview))throw new Failure('preview_invalid','Invalid preview.');
        return $this->app->store->locked('approval:'.$owner.':'.$preview,function()use($owner,$preview,$handoff){
            $a=$this->account($owner);if($handoff!=='')Chatgpt::verifyHandoff($this->app->store,$a,'publish',$preview,$handoff);
            Chatgpt::capabilities($this->app->agent,$a,['approvals','private_previews']);$key='browser-publish:'.$owner.':'.$preview;
            // Site binds approval to its immutable preview snapshot and atomically consumes it at publication.
            $approval=$this->app->agent->call($a,'POST','/approvals',['preview_id'=>$preview,'actor'=>['account_id'=>$owner,'source'=>'authenticated_browser'],'idempotency_key'=>$key]);
            if(empty($approval['approval_id']))throw new Failure('approval_failed','Approval was not recorded.',503);
            $result=$this->app->agent->call($a,'POST','/tools/publish_previewed',['actor'=>['account_id'=>$owner],'arguments'=>['preview_id'=>$preview,'approval_id'=>$approval['approval_id'],'client_key'=>$key],'idempotency_key'=>$key]);
            if(!empty($result['job_id'])){$this->app->jobs->remember($owner,$result);$this->app->store->put('consent','published:'.$owner.':'.$preview,['site_id'=>$a['site_id'],'job_id'=>$result['job_id']],$owner,'complete',time()+DAY_IN_SECONDS);$this->app->kick();}
            return Chatgpt::modelData($result);
        });
    }
    public function rollback(int $owner,string $release,string $handoff): array {
        $a=$this->account($owner);$ticket=Chatgpt::verifyHandoff($this->app->store,$a,'rollback',$release,$handoff);
        $releases=$this->app->agent->call($a,'POST','/tools/get_release',['arguments'=>[],'actor'=>['account_id'=>$owner]]);
        $binding=Chatgpt::rollbackBinding($releases,$release);if($binding!==$ticket['binding'])throw new Failure('stale_rollback','The releases changed. Open a new rollback review.',409);
        Chatgpt::capabilities($this->app->agent,$a,['rollback_approval_v1','jobs']);
        return $this->app->store->locked('rollback:'.$owner.':'.$release,function()use($owner,$release,$a,$binding){
            $key='browser-rollback:'.hash('sha256',wp_json_encode([$owner,$a['site_id'],$binding]));
            $approval=$this->app->agent->call($a,'POST','/approvals/rollback',['kind'=>'rollback','release_id'=>$release,'actor'=>['account_id'=>$owner,'source'=>'authenticated_browser'],'idempotency_key'=>$key]);
            if(empty($approval['approval_id']))throw new Failure('approval_failed','Approval was not recorded.',503);
            $result=$this->app->agent->call($a,'POST','/tools/rollback_release',['actor'=>['account_id'=>$owner],'arguments'=>['release_id'=>$release,'approval_id'=>$approval['approval_id'],'client_key'=>$key],'idempotency_key'=>$key]);
            if(!empty($result['job_id'])){$this->app->jobs->remember($owner,$result);$this->app->kick();}
            return Chatgpt::modelData($result);
        });
    }
    public function screen(): string {
        try {
            if(!is_user_logged_in() || !Identity::verified(get_current_user_id()))return '<p>Sign in to view your private preview.</p><a class="dl-button" href="'.esc_url(add_query_arg('return',Identity::returnPath((string)wp_unslash($_SERVER['REQUEST_URI']??'')),Config::origin().'/auth/login')).'">Sign in →</a>';
            nocache_headers();
            if(!headers_sent()){header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');}
            $handoff=(string)wp_unslash($_GET['handoff']??'');
            if(isset($_GET['release'])) {
                $release=(string)wp_unslash($_GET['release']);$a=$this->account(get_current_user_id());
                Chatgpt::verifyHandoff($this->app->store,$a,'rollback',$release,$handoff);
                Chatgpt::capabilities($this->app->agent,$a,['rollback_approval_v1']);
                $releases=$this->app->agent->call($a,'POST','/tools/get_release',['arguments'=>[],'actor'=>['account_id'=>get_current_user_id()]]);
                $ticket=Chatgpt::verifyHandoff($this->app->store,$a,'rollback',$release,$handoff);
                if(Chatgpt::rollbackBinding($releases,$release)!==$ticket['binding'])throw new Failure('stale_rollback','The releases changed. Open a new rollback review.',409);
                return '<section class="dl-panel"><h2>Review this rollback.</h2><p>Restore exactly release <strong>'.esc_html($release).'</strong>. This changes the public site. The site validates that this target is still eligible.</p><form data-dl-form="preview/rollback"><input type="hidden" name="release_id" value="'.esc_attr($release).'"><input type="hidden" name="handoff" value="'.esc_attr($handoff).'"><label><input type="checkbox" required> I want to restore this exact release.</label><button class="dl-button" type="submit">Restore this release</button><p class="dl-form-status" role="status"></p></form></section>';
            }
            $id=(string)wp_unslash($_GET['preview']??'');
            if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/',$id))throw new Failure('preview_missing','Open a preview from your Dashless conversation.');
            $a=$this->account(get_current_user_id());if($handoff!=='')Chatgpt::verifyHandoff($this->app->store,$a,'publish',$id,$handoff);$preview=$this->app->agent->call($a,'POST','/previews/'.$id.'/browser',['account_id'=>get_current_user_id(),'ttl'=>60]);
            $url=(string)($preview['url']??'');
            if(parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_HOST)!==$a['domain'] || !str_starts_with((string)parse_url($url,PHP_URL_PATH),'/wp-json/dashless-hosted/v1/previews/'))throw new Failure('preview_invalid','Preview destination failed validation.',503);
            return '<section class="dl-panel"><h2>Review your preview.</h2><p>Publishing applies this exact preview. If its content, design, or assets change, create a new preview first.</p><iframe class="dl-preview-frame" title="Private blog preview" src="'.esc_url($url).'" sandbox="allow-same-origin" referrerpolicy="no-referrer"></iframe><form data-dl-form="preview/approve"><input type="hidden" name="preview_id" value="'.esc_attr($id).'"><input type="hidden" name="handoff" value="'.esc_attr($handoff).'"><label><input type="checkbox" required> I have reviewed this preview and want to publish it.</label><button class="dl-button" type="submit">Publish this preview →</button><p class="dl-form-status" role="status"></p></form></section>';
        }catch(Failure $e){return '<section class="dl-panel"><h2>Preview unavailable</h2><p>'.esc_html($e->getMessage()).'</p></section>';}
        catch(\Throwable $e){
            // A private review must never take down the entire WordPress page. Keep the
            // public response generic, but leave a short operator breadcrumb in PHP logs.
            error_log('Dashless preview recovery: '.get_class($e).' code '.(string)$e->getCode());
            return '<section class="dl-panel"><h2>Preview needs a quick recovery</h2><p>We could not open this preview safely. Return to ChatGPT and create a fresh preview, or try this link again in a moment.</p></section>';
        }
    }
}
