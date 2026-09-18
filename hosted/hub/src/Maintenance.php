<?php
namespace Dashless\Hub;
final class Maintenance {
    public function __construct(private Store $store,private Identity $identity,private Billing $billing,private Provisioner $provisioner,private Jobs $jobs,private Cloud $cloud,private Agent $agent,private Rollouts $rollouts) {}
    public function pending(): bool {
        // Drain newly paid setups and normal builds within bounded native tasks. Failed/uncertain operations wait for review/hourly recovery.
        foreach(['provisioning'] as $state)if($this->store->rows('account',null,1,0,$state))return true;
        if($this->store->rows('stripe_event',null,1,0,'pending'))return true;
        if($this->store->rows('rollout',null,1,0,'active'))return true;
        foreach($this->store->rows('job',null,500,0,'running') as $r)if(empty($r['data']['needs_reconciliation']))return true;
        return false;
    }
    public function run(): array {
        $counts=['events'=>0,'accounts'=>0,'rollouts'=>0,'errors'=>0,'provider_cron'=>0,'cloud_diagnostics'=>0];$deadline=microtime(true)+30;
        $this->ensureProviderCron($counts);
        $this->refreshCloudDiagnostics($counts);
        if(microtime(true)<$deadline){$this->rollouts->ensureCurrent();$rollout=$this->rollouts->drain();if(!empty($rollout['rollout_id']))$counts['rollouts']=1;}
        foreach($this->store->rows('stripe_event',null,100,0,'pending') as $r) {
            if(microtime(true)>$deadline)break;
            if($r['status']!=='pending')continue;
            try {$this->billing->process($r['data']['event_id']);$counts['events']++;}catch(\Throwable $e){$counts['errors']++;$d=$r['data'];$d['attempts']=($d['attempts']??0)+1;$d['last_error']=$e instanceof Failure?$e->slug:'reconciliation_failed';$this->store->put('stripe_event',$d['event_id'],$d,0,$d['attempts']>=5?'failed':'pending');}
        }
        // Rotate through accounts rather than starving newer accounts behind the first page.
        $offset=(int)get_option('dashless_hub_maintenance_offset',0);
        $accounts=$this->store->rows('account',null,25,$offset);
        $processed=0;
        foreach($accounts as $r) {
            if(microtime(true)>$deadline)break;
            $processed++;
            try {$this->advance($r['owner']);(new Notices($this->store,$this->identity))->send($r['owner']);$counts['accounts']++;}
            catch(\Throwable $e) {$counts['errors']++;$this->store->audit($r['owner'],'reconciliation_failed',['code'=>$e instanceof Failure?$e->slug:'internal_error']);}
        }
        update_option('dashless_hub_maintenance_offset',($processed<count($accounts) || count($accounts)===25)?$offset+$processed:0,false);
        $this->store->prune();$health=['time'=>time(),'pending'=>$this->pending(),'counts'=>$counts,'failed_events'=>count($this->store->rows('stripe_event',null,500,0,'failed')),'running_jobs'=>count($this->store->rows('job',null,500,0,'running'))];update_option('dashless_hub_last_maintenance',$health,false);update_option('dashless_hub_health',$health,false);
        return $counts;
    }
    public function advance(int $owner): void {
        $a=$this->identity->account($owner);
        if($a['state']==='refunding'){$this->billing->refundFailedProvisioning($owner);return;}
        if(($a['billing_checked_at']??0)<time()-60)$a=$this->billing->refresh($owner);
        if(($a['entitlement']??'')==='active' && in_array($a['state'],['provisioning','provision_error'],true)) $a=$this->provisioner->tick($owner);
        if(!empty($a['site_id'])) {
            if(($a['entitlement']??'')==='expired' && !empty($a['delete_after']) && $a['delete_after']<=time()){$this->purge($owner);return;}
            if(($a['entitlement']??'')==='expired' && !in_array($a['state'],['deleted','refunding'],true)) {
                $this->store->locked('account:'.$owner,function()use($owner){
                    $a=$this->identity->account($owner);
                    if(($a['entitlement']??'')!=='expired')return;
                    $this->agent->call($a,'POST','/entitlement',['active'=>false,'retain_until'=>$a['delete_after']??time()+30*DAY_IN_SECONDS]);
                    if($a['state']!=='refunded')$a['state']='suspended';$this->identity->save($a);
                    // Plugin must retain authenticated export access while denying public HTML and clearing its caches.
                });
                if(!empty($a['delete_after']) && $a['delete_after']<=time()) $this->purge($owner);
            } elseif(in_array($a['entitlement']??'',['active','grace'],true) && $a['state']==='suspended') {
                $this->store->locked('account:'.$owner,function()use($owner){
                    $a=$this->identity->account($owner);
                    if(!in_array($a['entitlement']??'',['active','grace'],true) || $a['state']!=='suspended')return;
                    $this->agent->call($a,'POST','/entitlement',['active'=>true]);
                    $a['state']='ready';$this->identity->save($a);
                });
            }
            if(in_array($a['state'],['ready','suspended'],true))$this->jobs->tick($owner);
        }
        if(in_array($a['state'],['provisioning','provision_error','refunding'],true) && !empty($a['paid_at']) && time()>$a['paid_at']+DAY_IN_SECONDS) {
            // Positive site/task checks are required; network failure is not proof that provision failed.
            if(!empty($a['bootstrap_task']) && empty($this->cloud->taskStatus($a['bootstrap_task'])['complete'])) return;
            if(!empty($a['initial_task']) && empty($this->cloud->taskStatus($a['initial_task'])['complete'])) return;
            $found=$this->cloud->find($a['domain']);
            if($found) {
                $health=$this->agent->call($a,'GET','/health');
                if(!empty($health['ready']))return;
            }
            $a['provision_failure_verified']=time();$this->identity->save($a);$this->billing->refundFailedProvisioning($owner);
        }
    }
    private function purge(int $owner): void {
        $a=$this->billing->refresh($owner); // Fresh Stripe truth before every destructive attempt.
        if(($a['entitlement']??'')!=='expired' || ($a['delete_after']??PHP_INT_MAX)>time())return;
        $this->store->locked('account:'.$owner,function()use($owner){
            $a=$this->identity->account($owner);
            if(($a['entitlement']??'')!=='expired' || ($a['delete_after']??PHP_INT_MAX)>time())return;
            if(!empty($a['recovery_checkout']) && (empty($a['checkout_session']) || ($a['checkout_expires']??0)>time()))return;
            $site=$this->cloud->find($a['domain']);
            if($site && $site['site_id']!==(int)$a['site_id'])throw new Failure('site_mismatch','Deletion blocked by a site identity mismatch.',409);
            if($site) {
                // Preserve a provider-side filesystem snapshot before deletion. The
                // request is idempotent and deletion remains blocked until the
                // completed on-demand backup is visible in the backup inventory.
                $backup=$this->cloud->backupBeforeDelete((int)$a['site_id'],isset($a['delete_backup_requested_at'])?(int)$a['delete_backup_requested_at']:null);
                if(empty($backup['ready'])) {
                    if(!empty($backup['requested_at'])) {$a['delete_backup_requested_at']=(int)$backup['requested_at'];$this->identity->save($a);}
                    return;
                }
                if(!empty($backup['backup'])) {$a['delete_backup']=['id'=>$backup['backup']['atomic_backup_id']??null,'type'=>$backup['backup']['type']??null,'timestamp'=>$backup['backup']['backup_timestamp']??null];$this->identity->save($a);}
                if(empty($a['delete_sent'])) {$a['delete_sent']=time();$this->identity->save($a);$a['delete_job']=$this->cloud->delete($a['domain']);$this->identity->save($a);}
                return; // Verify absence on a later pass, never claim completion from an accepted request.
            }
            $a['state']='deleted';$a['deleted_at']=time();unset($a['site_secret'],$a['admin_secret']);$this->identity->save($a);
            // Keep the name tombstoned; reassignment must not make old links point to a different customer.
            $this->store->audit($owner,'site_deleted');
        });
    }
    private function ensureProviderCron(array &$counts): void {
        $site=(int)Config::get('hub_site_id',0);if($site<1)return;
        try {
            $result=$this->cloud->ensureCron((string)$site,'2h','wp dashless-hub reconcile --once');
            if(!empty($result['created']))$counts['provider_cron']=1;
        } catch(\Throwable $e) {
            $counts['errors']++;$this->store->audit(0,'provider_cron_failed',['code'=>$e instanceof Failure?$e->slug:'internal_error']);
        }
    }
    private function refreshCloudDiagnostics(array &$counts): void {
        $site=(int)Config::get('hub_site_id',0);if($site<1)return;
        try {
            update_option('dashless_hub_cloud_health',$this->cloud->diagnostics((string)$site),false);$counts['cloud_diagnostics']=1;
        } catch(\Throwable $e) {
            // Provider observability must never prevent billing or provisioning.
            $counts['errors']++;$this->store->audit(0,'cloud_diagnostics_failed',['code'=>$e instanceof Failure?$e->slug:'internal_error']);
        }
    }
}
