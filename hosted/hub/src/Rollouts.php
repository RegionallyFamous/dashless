<?php
namespace Dashless\Hub;

/** Durable, canary-first fleet updates for the customer site plugin. */
final class Rollouts {
    private const MAX_ATTEMPTS=3;
    private const MAX_TARGETS=10000;

    public function __construct(private App $app) {}

    public function create(array $owners,int $cohort=25): string {
        $owners=array_values(array_unique(array_filter(array_map('intval',$owners),fn($owner)=>$owner>0)));
        if(!$owners || count($owners)>self::MAX_TARGETS)throw new Failure('rollout_targets','Select between 1 and '.self::MAX_TARGETS.' explicit account IDs.');
        $targets=[];
        foreach($owners as $owner){
            $a=$this->app->identity->account($owner);
            if(!in_array($a['state']??'', ['ready','suspended'],true) || !in_array($a['entitlement']??'', ['active','grace'],true))throw new Failure('rollout_site','Every rollout target must be an active ready site.');
            if(empty($a['site_id']))throw new Failure('rollout_site','Every rollout target must have a site.');
            $targets[]=['owner'=>$owner,'site_id'=>(int)$a['site_id'],'status'=>'queued','attempts'=>0,'next_attempt'=>0];
        }
        $this->app->provisioner->verifyPackage();
        $cohort=max(1,min(500,$cohort));
        $id=wp_generate_uuid4();
        $data=['rollout_id'=>$id,'version'=>Config::required('site_plugin_version'),'package_url'=>Config::required('site_package_url'),'sha256'=>Config::required('site_package_sha256'),'cohort_size'=>$cohort,'max_failures'=>max(1,(int)ceil(count($targets)*0.02)),'created_at'=>time(),'targets'=>$targets];
        $this->app->store->add('rollout',$id,$data,get_current_user_id(),'active');
        return $id;
    }

    public function createAll(int $cohort=25): string {
        $owners=[];$offset=0;
        do {
            $rows=$this->app->store->rows('account',null,500,$offset);
            foreach($rows as $row){$a=$row['data'];if(in_array($a['state']??'', ['ready','suspended'],true) && in_array($a['entitlement']??'', ['active','grace'],true) && !empty($a['site_id']))$owners[]=(int)$row['owner'];}
            $offset+=count($rows);
        } while(count($rows)===500 && count($owners)<self::MAX_TARGETS);
        if(count($owners)>=self::MAX_TARGETS)throw new Failure('rollout_targets','The fleet exceeds the safe rollout limit; split it into cohorts.');
        return $this->create($owners,$cohort);
    }

    /** Create at most one rollout for a newly published package pointer. */
    public function ensureCurrent(int $cohort=25): ?string {
        $version=(string)Config::get('site_plugin_version','');
        $package=(string)Config::get('site_package_url','');
        $sha=(string)Config::get('site_package_sha256','');
        if($version==='' || $package==='' || $sha==='')return null;
        foreach($this->app->store->rows('rollout',null,500,0) as $row){
            $data=$row['data'];
            if(($data['sha256']??'')===$sha)return (string)($data['rollout_id']??'');
            if(in_array($row['status'],['active','paused'],true))return null;
        }
        return $this->app->store->locked('rollout-current',function()use($cohort,$sha){
            foreach($this->app->store->rows('rollout',null,500,0) as $row){
                $data=$row['data'];
                if(($data['sha256']??'')===$sha)return (string)($data['rollout_id']??'');
                if(in_array($row['status'],['active','paused'],true))return null;
            }
            try{return $this->createAll($cohort);}catch(Failure $e){if($e->slug==='rollout_targets')return null;throw $e;}
        });
    }

    /** Process at most one provider task per pass; WP Cloud serializes tasks globally. */
    public function drain(): array {
        foreach($this->app->store->rows('rollout',null,20,0,'active') as $row){
            try {$this->advance($row['data']['rollout_id']);return $this->summary($row['data']['rollout_id']);}
            catch(\Throwable $e){$this->app->store->audit(0,'rollout_drain_failed',['rollout_id'=>$row['data']['rollout_id'],'code'=>$e instanceof Failure?$e->slug:'internal_error']);return ['error'=>true];}
        }
        return ['active'=>false];
    }

    public function advance(string $id): void {
        $this->app->store->locked('rollout:'.$id,function()use($id){
            $row=$this->app->store->get('rollout',$id);if(!$row)throw new Failure('rollout_missing','Rollout not found.',404);
            if($row['status']!=='active')return;
            $data=$row['data'];
            if($data['package_url']!==Config::get('site_package_url') || $data['sha256']!==Config::get('site_package_sha256'))throw new Failure('rollout_changed','The configured package changed. Create a new rollout.',409);
            foreach($data['targets'] as &$target){
                if($target['status']==='succeeded')continue;
                if($target['status']==='uncertain'){$data['status']='paused';break;}
                if($target['status']==='failed'){
                    if(($data['failed_count']??0)>($data['max_failures']??1)){$data['status']='paused';break;}
                    $target['status']='queued';$target['attempts']=0;$target['next_attempt']=time()+3600;break;
                }
                $account=$this->app->identity->account((int)$target['owner']);
                if((int)($account['site_id']??0)!==(int)$target['site_id'] || !in_array($account['state']??'', ['ready','suspended'],true)){$data['status']='paused';$data['last_error']='rollout_changed';break;}
                if($target['status']==='running'){$this->finishTask($data,$target,$account);break;}
                if(($target['next_attempt']??0)>time())break;
                if(($target['status']??'')!=='queued'){$data['status']='paused';break;}
                $this->app->provisioner->verifyPackage();
                $target['status']='dispatching';$target['attempts']=(int)($target['attempts']??0)+1;$target['started_at']=time();
                $this->app->store->put('rollout',$id,$data,0,'active');
                try {
                    $task=$this->app->cloud->call('POST','/task-create/'.rawurlencode(Config::required('wpcloud_client')).'/software',['software'=>[Cloud::packageKey($data['package_url'])=>'activate'],'site_run_list'=>[(int)$account['site_id']],'site_count_limit'=>'1','send_webhook_for'=>'none']);
                    if(empty($task['task_id']))throw new Failure('rollout_uncertain','Dispatch uncertain; reconcile the platform task before retrying.',409);
                    $target['task_id']=(string)$task['task_id'];$target['status']='running';
                } catch(Failure $e) {
                    if($e->slug==='rollout_uncertain'){$target['status']='uncertain';$data['status']='paused';$data['last_error']=$e->slug;}
                    else $this->retryOrPause($data,$target,$e->slug);
                } catch(\Throwable $e) {$this->retryOrPause($data,$target,'rollout_dispatch_failed');}
                break;
            }
            unset($target);
            if(!array_filter($data['targets'],fn($target)=>$target['status']!=='succeeded') && $data['status']==='active')$data['status']='succeeded';
            $this->app->store->put('rollout',$id,$data,0,$data['status']);
        });
    }

    public function resume(string $id): void {
        $this->app->store->locked('rollout:'.$id,function()use($id){
            $row=$this->app->store->get('rollout',$id);if(!$row)throw new Failure('rollout_missing','Rollout not found.',404);
            $data=$row['data'];if($data['status']==='succeeded')return;
            foreach($data['targets'] as &$target)if($target['status']==='failed'){$target['status']='queued';$target['next_attempt']=0;}
            unset($target);$data['status']='active';$this->app->store->put('rollout',$id,$data,0,'active');
        });
    }

    public function pause(string $id): void {$this->app->store->locked('rollout:'.$id,function()use($id){$row=$this->app->store->get('rollout',$id);if(!$row)throw new Failure('rollout_missing','Rollout not found.',404);$data=$row['data'];$data['status']='paused';$this->app->store->put('rollout',$id,$data,0,'paused');});}

    public function summary(string $id): array {
        $row=$this->app->store->get('rollout',$id);if(!$row)throw new Failure('rollout_missing','Rollout not found.',404);$targets=$row['data']['targets'];$counts=[];foreach($targets as $target)$counts[$target['status']]=($counts[$target['status']]??0)+1;return ['rollout_id'=>$id,'status'=>$row['data']['status'],'version'=>$row['data']['version'],'counts'=>$counts,'last_error'=>$row['data']['last_error']??null];
    }

    private function finishTask(array &$data,array &$target,array $account): void {
        $task=$this->app->cloud->taskStatus((string)$target['task_id']);if(empty($task['complete']))return;
        if((int)($task['meta']['success_count']??0)!==1 || !empty($task['meta']['failure_count'])){$this->retryOrPause($data,$target,'rollout_task_failed');return;}
        try {$health=$this->app->agent->call($account,'GET','/capabilities');if(($health['plugin_version']??'')!==$data['version'])throw new Failure('rollout_version','Installed version has not passed verification.',409);$target['status']='succeeded';$target['completed_at']=time();}
        catch(Failure $e){$this->retryOrPause($data,$target,$e->slug);}
        catch(\Throwable $e){$this->retryOrPause($data,$target,'rollout_health_failed');}
    }

    private function retryOrPause(array &$data,array &$target,string $error): void {
        $target['last_error']=$error;
        if((int)($target['attempts']??0)<self::MAX_ATTEMPTS){$target['status']='queued';$target['next_attempt']=time()+min(3600,60*2**max(0,(int)$target['attempts']-1));return;}
        $target['status']='failed';$data['failed_count']=((int)($data['failed_count']??0))+1;$data['last_error']=$error;if($data['failed_count']>($data['max_failures']??1))$data['status']='paused';
    }
}
