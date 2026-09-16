<?php
namespace Dashless\Hub;
final class Jobs {
    public function __construct(private Store $store,private Identity $identity,private Cloud $cloud,private Agent $agent) {}
    public function remember(int $owner,array $result,string $kind="build"): void {
        $id=$result['job_id']??'';
        if (!preg_match('/^[a-f0-9-]{36}$/',$id)) throw new Failure('invalid_job','Site returned an invalid job identifier.',503);
        $existing=$this->store->get('job',$id);
        if ($existing && $existing['owner']!==$owner) throw new Failure('job_conflict','Job ownership mismatch.',409);
        $this->store->add('job',$id,['job_id'=>$id,'created_at'=>time(),'attempts'=>0,'kind'=>$kind],$owner,'queued');
    }
    public function owned(int $owner,string $id): array {
        $r=$this->store->get('job',$id);
        if (!$r || $r['owner']!==$owner) throw new Failure('job_missing','Job not found.',404);
        return $r;
    }
    public function tick(int $owner): void {
        $this->store->locked('build:'.$owner,function()use($owner){
            $a=$this->identity->account($owner);
            if (!in_array($a['state'],['ready','suspended'],true)) return;
            $canBuild=$a['state']==='ready' && in_array($a['entitlement']??'',['active','grace'],true);
            $rows=array_merge($this->store->rows('job',$owner,500,0,'running'),$this->store->rows('job',$owner,500,0,'queued'));
            foreach($rows as $row) {
                if ($row['status']!=='running') continue;
                $job=$row['data'];
                // A terminal site record alone is insufficient while a dispatched task may still be executing.
                if (!empty($job['task_id'])) {
                    $task=$this->cloud->taskStatus($job['task_id']);
                    if(empty($task['complete'])) return;
                }
                $remote=$this->agent->call($a,'GET','/jobs/'.$job['job_id']);
                // Explicit site checkpoint only: the prior known native task must have stopped.
                // A queued/running state without this signal is never an automatic retry.
                if(!empty($job['task_id']) && ($remote['status']??'')==='queued' && ($remote['continuation_required']??false)===true) {
                    unset($job['task_id'],$job['needs_reconciliation']);
                    $job['poll_after']=(int)($remote['poll_after']??0);
                    $job['continuations']=(int)($job['continuations']??0)+1;
                    $this->store->put('job',$job['job_id'],$job,$owner,'queued');
                    return; // Dispatch the same UUID on a subsequent tick, not this response.
                }
                if(in_array($remote['status']??'',['succeeded','failed','canceled'],true)) {
                    $job['result']=$remote;$this->store->put('job',$job['job_id'],$job,$owner,$remote['status']);
                } else {
                    // Never start another job while a prior site's execution state is uncertain.
                    $job['needs_reconciliation']=true;$this->store->put('job',$job['job_id'],$job,$owner,'running');return;
                }
            }
            $queued=array_values(array_filter($rows,fn($row)=>$row['status']==='queued'));
            // Finish an explicit checkpointed job before unrelated queued builds.
            usort($queued,fn($left,$right)=>(int)!empty($right['data']['continuations'])<=>(int)!empty($left['data']['continuations']));
            foreach($queued as $row) {
                $j=$row['data'];
                if (($j['poll_after']??0)>time()) return;
                if(!$canBuild && ($j['kind']??'build')!=='export')continue;
                $j['attempts']++;$j['dispatched_at']=time();
                $this->store->put('job',$j['job_id'],$j,$owner,'running');
                try {$j['task_id']=$this->cloud->task((int)$a['site_id'],['dashless','build-job',$j['job_id']]);}
                catch(\Throwable $e) { $j['needs_reconciliation']=true;$this->store->put('job',$j['job_id'],$j,$owner,'running');throw $e; }
                $this->store->put('job',$j['job_id'],$j,$owner,'running');return;
            }
        });
    }
    public function status(int $owner,string $id): array {
        $row=$this->owned($owner,$id);$this->tick($owner);$row=$this->owned($owner,$id);$a=$this->identity->account($owner);
        if(in_array($row['status'],['succeeded','failed','canceled'],true)) return $row['data']['result']??['job_id'=>$id,'status'=>$row['status']];
        return $this->agent->call($a,'GET','/jobs/'.$id);
    }
}
