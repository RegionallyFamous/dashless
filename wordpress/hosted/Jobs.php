<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

final class Jobs {
    public function __construct(private App $app) {}
    public function get(string $id): array {
        $r=$this->app->store->get('job',Support::uuid($id));if (!$r) { throw new Failure('job_missing','The job was not found.',404); }return $r['data'];
    }
    private function save(array $job): void { $this->app->store->put('job',$job['job_id'],$job,$job['owner']); }
    public function public(array $j): array {
        $keys=['job_id','kind','status','attempts','created_at','started_at','finished_at','peak_memory_bytes','memory_method','runtime_sha256','release_id','preview_id','export_id','saved','preview_ready','published_in_wordpress','deployed','publicly_verified','previous_release_preserved','error','snapshot_generation','snapshot_counts','full_snapshot','html_pages','continuation_required','poll_after','build_driver','progress'];
        $out=array_intersect_key($j,array_flip($keys));if (in_array($j['status'],['queued','running'],true)) { $out['next_action']='get_job'; }
        $thing=$j['kind']==='export'?'download':($j['kind']==='preview'?'preview':'blog update');
        $out['message']=match($j['status']) {'queued'=>!empty($j['continuation_required'])?'Your download is being prepared. Your progress has been saved.':"Your $thing is waiting.",'running'=>"Preparing your $thing.",'succeeded'=>$j['kind']==='export'?'Your download is ready.':(!empty($j['publicly_verified'])?'Your blog is live.':'Your preview is ready.'),'failed'=>$j['error']['message']??'We could not finish this request. Please try again.',default=>'This request was canceled.'};
        if ($j['status']==='running' && ($j['lease_expires']??PHP_INT_MAX)<time()) { $out['needs_reconciliation']=true;$out['message']='This is taking longer than expected. Your saved work is safe. Please contact support.'; }
        return $out;
    }
    public function retry(string $id): array {
        return $this->app->store->transaction(function()use($id){$j=$this->get($id);if ($j['status']==='failed') { $j['status']='queued';unset($j['error'],$j['remote_build_id']);$this->save($j); }return $this->public($j);});
    }
    public function enqueue(string $kind,array $args,int $owner,?string $id=null): array {
        $id=$id??wp_generate_uuid4();$old=$this->app->store->get('job',$id);
        if ($old) { if ($old['owner']!==$owner || $old['data']['kind']!==$kind || $old['data']['arguments']!==$args) { throw new Failure('job_conflict','This job identifier already belongs to another request.'); }return $this->public($old['data']); }
        $job=['job_id'=>$id,'site_id'=>$this->app->siteId(),'owner'=>$owner,'kind'=>$kind,'arguments'=>$args,'status'=>'queued','attempts'=>0,'created_at'=>gmdate('c'),'saved'=>false,'preview_ready'=>false,'published_in_wordpress'=>false,'deployed'=>false,'publicly_verified'=>false,'previous_release_preserved'=>true];
        if (!$this->app->store->add('job',$id,$job,$owner)) { throw new Failure('job_conflict','Retry the same job request.'); }return $this->public($job);
    }
    private function checkDeadline(float $deadline): void { if (microtime(true)>$deadline) { throw new Failure('build_deadline','The operation exceeded its time limit. Retry after checking the job.'); } }
    public function run(string $id): array {
        $job=$this->get($id);if (in_array($job['status'],['succeeded','failed','canceled'],true)) { return $this->public($job); }
        $this->app->active($job['kind']==='export');
        $lease=$this->app->store->lock('build',260);
        if (!$lease) { return $this->public($job); }
        $workspace=null;$deadline=microtime(true)+235;
        if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true);pcntl_signal(SIGALRM,fn()=>throw new Failure('build_deadline','The operation exceeded its time limit.'));pcntl_alarm(238); }
        try {
            $job=$this->get($id);
            if (in_array($job['status'],['succeeded','failed','canceled'],true)) { return $this->public($job); }
            // A supervisor has its own deadline and kills children when the CLI parent disappears.
            // An expired lease therefore cannot overlap a still-authorized old build.
            $job['status']='running';$job['continuation_required']=false;$job['attempts']++;$job['started_at']=gmdate('c');$job['lease_owner']=$lease;$job['lease_expires']=time()+260;$job['runtime_sha256']=$this->app->runtime->digest();if ($job['kind']==='export') { $job['export_id']=$job['export_id']??wp_generate_uuid4(); }$this->save($job);
            $workspace=$this->app->runtime->workspace();
            if ($job['kind']==='export') {
                if (!(new Export($this->app))->step($job,$workspace,$deadline)) { $job['status']='queued';$job['continuation_required']=true;$this->save($job);return $this->public($job); }
            }
            elseif ($job['kind']==='rollback') { $this->rollback($job,$deadline); }
            elseif ($job['kind']==='publish') { $this->publish($job,$workspace,$deadline); }
            else { if (!$this->build($job,$workspace,$deadline)) { $job['status']='queued';$job['continuation_required']=true;$job['poll_after']=time()+10;$this->save($job);return $this->public($job); } }
            $job['status']='succeeded';$job['finished_at']=gmdate('c');$this->save($job);
        } catch(Failure $e) {
            $job['status']='failed';$job['error']=['code'=>$e->slug,'message'=>$e->getMessage()];$job['finished_at']=gmdate('c');$this->save($job);
        } catch(\Throwable $e) {
            do_action('dashless_hosted_job_exception',$e);
            $job['status']='failed';$job['error']=['code'=>'job_failed','message'=>'The operation failed. The previous public release is preserved.'];$job['finished_at']=gmdate('c');$this->save($job);
        } finally {
            if (function_exists('pcntl_alarm')) { pcntl_alarm(0); }
            if ($workspace) { Runtime::removeWorkspace($workspace); }
            $this->app->store->unlock('build',$lease);
        }
        return $this->public($job);
    }
    private function build(array &$job,string $work,float $deadline): bool {
        $snapshot=$this->app->content->snapshot($job['arguments'],$job['owner'],$work);
        if ($job['kind']==='initial_release' && ($snapshot['items'] || get_option(DASHLESS_WPCLOUD_OPTION,[]))) { throw new Failure('initial_site_not_empty','An initial release is allowed only on a fresh empty site.'); }
        $job['snapshot_generation']=$snapshot['generation'];$job['snapshot_counts']=['posts'=>count(array_filter($snapshot['items'],fn($p)=>$p['post_type']==='post')),'pages'=>count(array_filter($snapshot['items'],fn($p)=>$p['post_type']==='page')),'media'=>count($snapshot['media'])];$this->save($job);
        Support::write($work.'/snapshot.json',wp_json_encode($snapshot));
        $job['remote_build_id']=$job['remote_build_id']??wp_generate_uuid4();$this->save($job);
        $result=$this->app->runtime->run($work,$deadline,function($metrics)use(&$job){$job=array_replace($job,$metrics);$this->save($job);},$job['remote_build_id']);
        if (!empty($result['pending'])) return false;
        if (($result['quality']['passed']??false)!==true || ($result['frontend_contract']??0)!==1) { throw new Failure('frontend_quality_failed','The frontend did not pass its publication contract.'); }
        if ($this->app->content->generation()!==$snapshot['generation'] || $this->app->content->design()!==$snapshot['design']) { throw new Failure('snapshot_changed','Content changed during the build. Create a new preview.'); }
        $this->checkDeadline($deadline);$this->app->active();
        $candidate=wp_generate_uuid4();$release=gmdate('Ymd\THis').'000Z-'.bin2hex(random_bytes(3));
        $manifest=['version'=>1,'release_id'=>$release,'public_host'=>wp_parse_url(home_url(),PHP_URL_HOST),'content_generation'=>$snapshot['generation'],'files'=>$result['files']];
        $entries=dashless_wpcloud_transfer_entries($manifest,$release);if (is_wp_error($entries)) { throw new Failure('manifest_invalid','The candidate failed the release manifest checks.'); }
        foreach ($entries as $entry) {
            $this->checkDeadline($deadline);$file=dashless_wpcloud_reusable_file($work.'/source/dist',$entry);
            if (!$file) { throw new Failure('manifest_invalid','A candidate asset failed integrity verification.'); }
            $this->app->vault()->putFile('candidate:'.$candidate.':'.$entry['path'],$file);
        }
        $record=['candidate'=>$candidate,'manifest'=>$manifest,'manifest_hash'=>Support::hash($manifest),'binding'=>$this->app->content->binding($snapshot,$candidate)];
        $this->app->store->put('candidate',$candidate,$record,$job['owner']);
        $this->app->vault()->put('snapshot:'.$candidate,wp_json_encode($snapshot));
        $job['full_snapshot']=true;$job['html_pages']=$result['html_pages'];
        if ($job['kind']==='initial_release') { $this->activate($job,$record,$deadline);return true; }
        $id=wp_generate_uuid4();$this->app->store->put('preview',$id,['preview_id'=>$id,'candidate'=>$candidate,'manifest_hash'=>$record['manifest_hash'],'binding'=>$record['binding'],'arguments'=>$job['arguments'],'expires'=>time()+DAY_IN_SECONDS],$job['owner']);
        $job['preview_id']=$id;$job['preview_ready']=true;$job['saved']=true;return true;
    }
    private function publish(array &$job,string $work,float $deadline): void {
        $p=$this->app->store->owned('preview',$job['arguments']['preview_id'],$job['owner']);$candidate=$this->app->store->owned('candidate',$p['candidate'],$job['owner']);
        if (!hash_equals($p['manifest_hash'],$candidate['manifest_hash'])) { throw new Failure('stale_approval','The approved candidate changed.'); }
        if (empty($job['publication_saved'])) {
            $this->app->content->assertCurrent($p,true,$work);$this->checkDeadline($deadline);
            $snapshot=json_decode($this->app->vault()->get('snapshot:'.$p['candidate']),true);
            $this->app->store->transaction(function()use(&$job,$snapshot,$p){
                $this->app->active();$this->app->content->assertCurrent($p);
                if ($snapshot['target']) { $t=$snapshot['target']['after'];$this->app->content->save($t['post_type'],$t,$t['id'],'publish'); }
                $job['publication_saved']=true;$job['published_in_wordpress']=true;$job['saved']=true;$job['published_generation']=$this->app->content->generation();$this->save($job);
            });
        }
        if ($this->app->content->generation()!==$job['published_generation']) { throw new Failure('content_changed_after_save','WordPress was saved, but changed before deployment. Create a new preview.'); }
        $job['preview_ready']=true;$candidate['binding']['generation']=$job['published_generation'];
        $this->activate($job,$candidate,$deadline);
    }
    private function activate(array &$job,array $candidate,float $deadline): void {
        $this->checkDeadline($deadline);$this->app->active();
        $manifest=$candidate['manifest'];$routes=new Routes($this->app);$expected=$routes->artifact($candidate['candidate'],'index.html','');
        $release=['release_id'=>$manifest['release_id'],'candidate'=>$candidate['candidate'],'manifest_hash'=>$candidate['manifest_hash'],'generation'=>$candidate['binding']['generation'],'verified'=>false];
        $old=$this->app->store->get('release','active')['data']??null;
        if ($old && empty($old['verified'])) { $old=$old['fallback']??null; }
        $verification=bin2hex(random_bytes(32));$release['verification_hash']=hash('sha256',$verification);$release['fallback']=$old;
        $job['activation_previous']=$old;$job['activation_candidate']=$release;$this->save($job);
        $this->app->store->transaction(function()use($release){$this->app->active();$this->app->store->put('release','active',$release);});Support::purge();
        try {
            $verified=false;
            for ($attempt=0;$attempt<3;$attempt++) {
                $this->checkDeadline($deadline);$r=wp_remote_get(add_query_arg('dashless_verify',wp_generate_uuid4(),home_url('/')),['timeout'=>min(10,max(1,(int)($deadline-microtime(true)))),'redirection'=>0,'headers'=>['Cache-Control'=>'no-cache','X-Dashless-Verify'=>$verification]]);
                if (!is_wp_error($r) && wp_remote_retrieve_response_code($r)===200 && hash_equals($release['release_id'],(string)wp_remote_retrieve_header($r,'x-dashless-release')) && hash_equals(hash('sha256',$expected),hash('sha256',wp_remote_retrieve_body($r)))) { $verified=true;break; }
            }
            if (!$verified) { throw new Failure('public_verification_failed','WordPress was saved, but the public release could not be verified. The previous release was restored.'); }
            $release['verified']=true;unset($release['verification_hash'],$release['fallback']);$this->app->store->transaction(function()use($old,$release){$this->app->active();if ($old) { $this->app->store->put('release','previous',$old); }$this->app->store->put('release','active',$release);});
            $this->checkDeadline($deadline);
            $public=wp_remote_get(add_query_arg('dashless_verify',wp_generate_uuid4(),home_url('/')),['timeout'=>min(10,max(1,(int)($deadline-microtime(true)))),'redirection'=>0,'headers'=>['Cache-Control'=>'no-cache']]);
            if (is_wp_error($public) || wp_remote_retrieve_response_code($public)!==200 || wp_remote_retrieve_header($public,'x-dashless-release')!==$release['release_id'] || !hash_equals(hash('sha256',$expected),hash('sha256',wp_remote_retrieve_body($public)))) { throw new Failure('public_verification_failed','The public release could not be verified. The previous release was restored.'); }
            $job['deployed']=true;$job['publicly_verified']=true;$job['release_id']=$release['release_id'];$job['previous_release_preserved']=false;
        } catch(\Throwable $e) {
            $this->app->store->put('release','active',$old??[]);Support::purge();$job['deployed']=false;$job['publicly_verified']=false;$job['previous_release_preserved']=true;throw $e;
        }
    }
    private function rollback(array &$job,float $deadline): void {
        $approval=$this->app->store->owned('approval',$job['arguments']['approval_id'],$job['owner']);$previous=$this->app->store->get('release','previous')['data']??null;$active=$this->app->store->get('release','active')['data']??null;
        if (!$previous || !$previous['verified'] || $previous['release_id']!==$approval['binding']['release_id'] || $previous['manifest_hash']!==$approval['binding']['manifest_hash'] || ($active['release_id']??null)!==$approval['binding']['active'] || $this->app->content->generation()!==$approval['binding']['generation']) { throw new Failure('stale_approval','The rollback state changed. Request another human approval.'); }
        $candidate=$this->app->store->get('candidate',$previous['candidate'])['data'];$candidate['binding']['generation']=$this->app->content->generation();$this->activate($job,$candidate,$deadline);
    }
}
