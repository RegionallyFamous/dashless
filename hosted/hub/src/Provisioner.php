<?php
namespace Dashless\Hub;
final class Provisioner {
    public function __construct(private Store $store,private Identity $identity,private Cloud $cloud,private Agent $agent) {}
    public function tick(int $owner): array {
        return $this->store->locked('account:'.$owner,function()use($owner){
            $a=$this->identity->account($owner);
            if (($a['entitlement']??'')!=='active' || !in_array($a['state'],['provisioning','provision_error'],true)) return $a;
            try {
                if (empty($a['provision_id'])) {
                    $a['provision_id']=wp_generate_uuid4();$a['site_secret']=Crypto::seal(bin2hex(random_bytes(32)));$a['admin_secret']=Crypto::seal(wp_generate_password(64,true,true));
                    $a['step']='create';$this->identity->save($a);
                }
                if (empty($a['site_id'])) {
                    $found=$this->cloud->find($a['domain']);
                    if ($found) {
                        if (empty($a['create_sent'])) throw new Failure('domain_exists','This hostname already has a site; operator review is required.',409);
                        $proof=$this->cloud->provenance($found['site_id']);
                        if(($proof['dashless']['operation']??'')!==$a['provision_id'] || (int)($proof['dashless']['account']??0)!==$owner)throw new Failure('site_provenance_mismatch','Recovered site does not match this provisioning operation.',409);
                        $a['site_id']=$found['site_id'];$this->confirm($a,'create',['site_id'=>$found['site_id'],'recovered'=>true]);$a['step']='bootstrap';$this->identity->save($a);
                    } elseif (!empty($a['create_sent'])) {
                        throw new Failure('creation_uncertain','Site creation is unconfirmed. Operator reconciliation is required; no duplicate will be created.',409);
                    } else {
                        $this->verifyPackage();
                        $a['create_sent']=time();$this->identity->save($a);
                        $created=$this->cloud->create($a);
                        if (empty($created['atomic_site_id']) || ($created['domain_name']??'')!==$a['domain']) throw new Failure('creation_uncertain','Site creation needs reconciliation.',503);
                        $a['site_id']=(int)$created['atomic_site_id'];$a['cloud_job_id']=$created['job_id']??null;$this->confirm($a,'create',['site_id'=>$a['site_id'],'cloud_job_id'=>$a['cloud_job_id']]);$a['step']='bootstrap';$this->identity->save($a);
                        return $a;
                    }
                }
                if ($a['step']==='bootstrap') {
                    if (empty($a['bootstrap_task'])) {
                        $a['bootstrap_task']=$this->cloud->task($a['site_id'],['dashless','bootstrap','--operation='.$a['provision_id'],'--site-id='.$a['site_id'],'--hub='.Config::origin(),'--credential-hash='.hash('sha256',Crypto::open($a['site_secret'])),'--package-sha256='.Config::required('site_package_sha256'),'--build-driver='.(Config::get('builder_url')?'railway':'native')]);
                        $this->identity->save($a);return $a;
                    }
                    $this->assertCompleted($a['bootstrap_task']);
                    $this->confirm($a,'bootstrap',['task_id'=>$a['bootstrap_task']]);
                    if (Config::get('builder_url')) {
                        $this->agent->call($a,'POST','/builder',['url'=>Config::required('builder_url'),'token'=>hash_hmac('sha256','dashless-builder-v1:'.$a['site_id'],Config::required('builder_master_key'))]);
                    }
                    $caps=$this->agent->call($a,'GET','/capabilities');
                    foreach(['jobs','private_previews','approvals','exports','design','runtime'] as $cap) if(empty($caps['capabilities'][$cap])) throw new Failure('capability_missing','The site plugin is missing required hosted capabilities.',409);
                    $this->confirm($a,'capabilities',['plugin_version'=>$caps['plugin_version']??null,'runtime_sha256'=>$caps['runtime_sha256']??null]);$a['step']='initial_build';$this->identity->save($a);
                }
                if ($a['step']==='initial_build') {
                    $id=$a['initial_job']??wp_generate_uuid4();$a['initial_job']=$id;$this->identity->save($a);
                    $job=$this->agent->call($a,'POST','/jobs',['job_id'=>$id,'kind'=>'initial_release','idempotency_key'=>$a['provision_id']]);
                    if (empty($a['initial_task'])) {$a['initial_task']=$this->cloud->task($a['site_id'],['dashless','build-job',$id]);$this->identity->save($a);return $a;}
                    $this->assertCompleted($a['initial_task']);
                    $job=$this->agent->call($a,'GET','/jobs/'.$id);
                    if (($job['status']??'')==='queued' && !empty($job['continuation_required'])) { unset($a['initial_task']);$this->identity->save($a);return $a; }
                    if (($job['status']??'')!=='succeeded' || empty($job['release_id'])) throw new Failure('initial_build_failed','The initial build did not complete.',409);
                    $a['release_id']=$job['release_id'];$this->confirm($a,'initial_build',['job_id'=>$id,'release_id'=>$a['release_id']]);$a['step']='verify';$this->identity->save($a);
                }
                if ($a['step']==='verify') {
                    if (!$this->agent->publicHealth($a,$a['release_id'])) throw new Failure('public_verification_failed','Waiting for your secure public blog address to respond.',503);
                    $this->confirm($a,'verify',['release_id'=>$a['release_id']]);
                    $a['state']='ready';$a['step']='ready';$a['ready_at']=time();unset($a['admin_secret'],$a['last_error']);
                    $this->store->audit($owner,'site_ready',['site_id'=>$a['site_id']]);
                }
            } catch(Failure $e) {
                if ($e->slug!=='task_pending') {$a['state']='provision_error';$a['last_error']=['code'=>$e->slug,'message'=>$e->getMessage(),'time'=>time()];}
            } catch(\Throwable $e) {
                // Unexpected provider or crypto failures must still become a durable,
                // operator-resumable state instead of leaving the account falsely busy.
                $a['state']='provision_error';$a['last_error']=['code'=>'provisioning_failed','message'=>'Provisioning needs operator review.','time'=>time()];
                $this->store->audit($owner,'provisioning_exception',['type'=>get_class($e)]);
            }
            $this->identity->save($a);return $a;
        },180);
    }
    private function assertCompleted(string $id): void {
        $task=$this->cloud->taskStatus($id);
        if (empty($task['complete'])) throw new Failure('task_pending','WP Cloud is still processing this operation.',409);
        if ((int)($task['meta']['failure_count']??0)>0 || (int)($task['meta']['success_count']??0)!==1) throw new Failure('task_failed','WP Cloud task failed; operator review is required.',409);
    }
    private function confirm(array &$account,string $phase,array $remote): void {
        $account['phase_state'][$phase]=['confirmed_at'=>time(),'retry_token'=>wp_generate_uuid4(),'remote'=>$remote];
    }
    public function verifyPackage(): void {
        $url=Config::required('site_package_url');$expected=Config::required('site_package_sha256');
        if (!preg_match('/^[a-f0-9]{64}$/',$expected) || parse_url($url,PHP_URL_HOST)!==parse_url(Config::origin(),PHP_URL_HOST) || parse_url($url,PHP_URL_SCHEME)!=='https') throw new Failure('package_invalid','A pinned Hub-hosted site package is required.',503);
        $r=wp_remote_get($url,['timeout'=>15,'redirection'=>0,'limit_response_size'=>50*1024*1024]);
        if(is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200 || !hash_equals($expected,hash('sha256',wp_remote_retrieve_body($r)))) throw new Failure('package_invalid','The site package failed integrity verification.',503);
    }
}
