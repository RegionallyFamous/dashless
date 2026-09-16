<?php
namespace Dashless\Hub;
/** Operator-only, explicit site list. Canary must pass before another site can advance. */
final class Rollouts {
    public function __construct(private App $app) {}
    public function create(array $owners): string {
        $owners=array_values(array_unique(array_map('intval',$owners)));
        if(!$owners || count($owners)>50 || min($owners)<1)throw new Failure('rollout_targets','Select 1–50 explicit account IDs.');
        $this->app->provisioner->verifyPackage();
        $version=Config::required('site_plugin_version');$targets=[];
        foreach($owners as $owner){$a=$this->app->identity->account($owner);if($a['state']!=='ready')throw new Failure('rollout_site','Every rollout target must be ready.');$targets[]=['owner'=>$owner,'site_id'=>$a['site_id'],'status'=>'queued'];}
        $id=wp_generate_uuid4();$this->app->store->add('rollout',$id,['rollout_id'=>$id,'version'=>$version,'package_url'=>Config::required('site_package_url'),'sha256'=>Config::required('site_package_sha256'),'targets'=>$targets],get_current_user_id(),'paused');return $id;
    }
    public function advance(string $id): void {
        $this->app->store->locked('rollout:'.$id,function()use($id){
            $r=$this->app->store->get('rollout',$id);if(!$r)throw new Failure('rollout_missing','Rollout not found.',404);$d=$r['data'];
            if($d['package_url']!==Config::get('site_package_url') || $d['sha256']!==Config::get('site_package_sha256'))throw new Failure('rollout_changed','The configured package changed. Create a new rollout.',409);
            foreach($d['targets'] as &$target){
                if($target['status']==='succeeded')continue;
                $a=$this->app->identity->account($target['owner']);
                if($a['site_id']!==$target['site_id'] || $a['state']!=='ready')throw new Failure('rollout_changed','A target changed. Rollout paused.',409);
                if($target['status']==='queued') {
                    $this->app->provisioner->verifyPackage();$target['status']='dispatching';$this->app->store->put('rollout',$id,$d,$r['owner'],'paused');
                    $task=$this->app->cloud->call('POST','/task-create/'.rawurlencode(Config::required('wpcloud_client')).'/software',['software'=>[Cloud::packageKey($d['package_url'])=>'activate'],'site_run_list'=>[(int)$a['site_id']],'site_count_limit'=>'1','send_webhook_for'=>'none']);
                    if(empty($task['task_id']))throw new Failure('rollout_uncertain','Dispatch uncertain; reconcile the platform task before retrying.',409);
                    $target['task_id']=$task['task_id'];$target['status']='running';
                }elseif($target['status']==='running') {
                    $task=$this->app->cloud->taskStatus((string)$target['task_id']);
                    if(empty($task['complete']))return;
                    if((int)($task['meta']['success_count']??0)!==1 || !empty($task['meta']['failure_count']))throw new Failure('rollout_failed','Platform update failed. Rollout paused.',409);
                    $health=$this->app->agent->call($a,'GET','/capabilities');
                    if(($health['plugin_version']??'')!==$d['version'])throw new Failure('rollout_version','Installed version has not passed verification.',409);
                    $target['status']='succeeded';
                }else throw new Failure('rollout_uncertain','A dispatch needs operator reconciliation. No second update was submitted.',409);
                // One site per operator action; the first target is the canary. Stop automatically on every failure.
                $this->app->store->put('rollout',$id,$d,$r['owner'],'paused');return;
            }
            unset($target);$this->app->store->put('rollout',$id,$d,$r['owner'],'succeeded');
        });
    }
}
