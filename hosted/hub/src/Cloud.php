<?php
namespace Dashless\Hub;
class Cloud {
    private const API_BASE='https://atomic-api.wordpress.com/api/v1.0/';
    public function call(string $method,string $path,array $data=[]): mixed {
        $response=wp_remote_request(self::API_BASE.ltrim($path,'/'),[
            'method'=>$method,'timeout'=>20,'redirection'=>0,
            'headers'=>['auth'=>Config::required('wpcloud_api_key'),'Accept'=>'application/json','Content-Type'=>'application/x-www-form-urlencoded'],
            'body'=>$method==='GET'?null:http_build_query($data,'','&',PHP_QUERY_RFC3986),
        ]);
        if (is_wp_error($response)) throw new Failure('cloud_unavailable','WP Cloud did not confirm the request. Reconciliation is required.',503);
        $code=wp_remote_retrieve_response_code($response);
        if ($code===404) throw new Failure('cloud_not_found','WP Cloud resource not found.',404);
        if ($code<200 || $code>=300) throw new Failure('cloud_request_failed','WP Cloud request failed (HTTP '.$code.').',503);
        $body=json_decode(wp_remote_retrieve_body($response),true);
        if (!is_array($body) || !array_key_exists('data',$body)) throw new Failure('cloud_response_invalid','Unexpected WP Cloud response.',503);
        return $body['data'];
    }
    public function find(string $domain): ?array {
        try { $s=$this->call('GET','/get-site/'.rawurlencode($domain)); }
        catch(Failure $e) { if($e->slug==='cloud_not_found')return null;throw $e; }
        if (($s['domain_name']??'')!==$domain || empty($s['atomic_site_id'])) throw new Failure('cloud_site_mismatch','Site identity could not be verified.',409);
        return ['site_id'=>(int)$s['atomic_site_id'],'domain'=>$s['domain_name']];
    }
    public function provenance(int $id): array {
        $data=$this->call('GET','/site-meta/'.$id.'/_data/get');
        return is_string($data)?(json_decode($data,true)??[]):(is_array($data)?$data:[]);
    }
    public static function packageKey(string $url): string { return str_replace('.', '%2E', rawurlencode('plugin://'.$url)); }
    public function create(array $a): array {
        $url=Config::required('site_package_url');
        if (parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_HOST)!==parse_url(Config::origin(),PHP_URL_HOST)) throw new Failure('package_origin','The pinned site package must be hosted on the Hub.',503);
        $preflight=$this->preflight((string)$a['domain']);
        $php=$preflight['php_version']??'8.3';
        return $this->call('POST','/create-site/'.rawurlencode(Config::required('wpcloud_client')),[
            'domain_name'=>$a['domain'],'admin_user'=>'dashless_operator','admin_email'=>Config::required('support_email'),
            'admin_pass'=>Crypto::open($a['admin_secret']),'php_version'=>$php,'space_quota'=>'25G',
            'software'=>[self::packageKey($url)=>'activate-locked'],
            'meta'=>['default_php_conns'=>'2','burst_php_conns'=>'0','php_memory_limit'=>'512','_data'=>wp_json_encode(['dashless'=>['account'=>$a['user_id'],'operation'=>$a['provision_id']]])],
        ]);
    }
    /** Validate a hostname and select a supported PHP version before creation. */
    public function preflight(string $domain): array {
        $eligible=$this->call('GET','/check-can-host-domain/'.rawurlencode(Config::required('wpcloud_client')).'/'.rawurlencode($domain));
        $allowed=$eligible['allowed']??$eligible['can_host']??$eligible['eligible']??$eligible;
        if ($allowed===false || $allowed==='false' || $allowed===0 || $allowed==='0') throw new Failure('domain_unavailable','WP Cloud cannot host this hostname.',409);
        $versions=$this->availablePhpVersions();
        $preferred='8.3';
        if ($versions && !in_array($preferred,$versions,true)) {
            usort($versions,fn($a,$b)=>version_compare($b,$a));
            $preferred=$versions[0];
        }
        return ['domain'=>$domain,'eligible'=>true,'php_version'=>$preferred,'php_versions'=>$versions];
    }
    public function availablePhpVersions(): array {
        $data=$this->call('GET','/get-php-versions/'.rawurlencode(Config::required('wpcloud_client')));
        $values=$data['versions']??$data;
        return array_values(array_filter(array_map('strval',(array)$values),fn($v)=>preg_match('/^\d+\.\d+$/',$v)));
    }
    public function task(int $site,array $args): string {
        if ($site<1 || ($args[0]??'')!=='dashless' || !in_array($args[1]??'',['bootstrap','build-job','rotate-credential'],true)) throw new Failure('invalid_task','Invalid site task.',400);
        $r=$this->call('POST','/task-create/'.rawurlencode(Config::required('wpcloud_client')).'/run-wp-cli-command',[
            'args'=>$args,'site_run_list'=>[$site],'cli_mode'=>'full','site_count_limit'=>'1','send_webhook_for'=>'none',
        ]);
        if (empty($r['task_id'])) throw new Failure('task_uncertain','Task dispatch needs reconciliation.',503);
        return (string)$r['task_id'];
    }
    public function taskStatus(string $id): array { return $this->call('POST','/task-get/'.rawurlencode($id)); }
    public function jobStatus(string $id): array { return (array)$this->call('GET','/job-status/'.rawurlencode($id)); }
    public function interruptTask(string $id): array { return $this->call('POST','/task-interrupt/'.rawurlencode($id)); }
    public function failedWebhooks(): array { return (array)$this->call('GET','/webhook/failures/'.rawurlencode(Config::required('wpcloud_client'))); }
    public function ensureCron(string $site,string $schedule,string $command): array {
        $entries=(array)$this->call('GET','/crontab/'.rawurlencode($site).'/list');
        foreach($entries as $entry) if(($entry['command']??'')===$command) return ['created'=>false,'cron_id'=>$entry['cron_id']??null];
        $created=$this->call('POST','/crontab/'.rawurlencode($site).'/add',['schedule'=>$schedule,'command'=>$command]);
        return ['created'=>true,'cron_id'=>$created['cron_id']??null];
    }
    public function backups(string $site): array { return (array)$this->call('GET','/site-backups-list/domain/'.rawurlencode($site)); }
    public function requestBackup(int $site,string $type='fs'): array {
        if(!in_array($type,['fs','db'],true))throw new Failure('backup_type','Unsupported backup type.',400);
        return (array)$this->call('POST','/on-demand-backup/create/'.rawurlencode((string)$site).'/'.$type);
    }
    /** Request a filesystem backup once and report when a recent on-demand copy exists. */
    public function backupBeforeDelete(int $site,?int $requestedAt=null): array {
        $backups=$this->backups((string)$site);
        $cutoff=max(0,(int)$requestedAt-60);
        foreach($backups as $backup) {
            $kind=(string)($backup['type']??'');$time=strtotime((string)($backup['backup_timestamp']??''));
            if(in_array($kind,['ondemand-fs','ondemand'],true) && $time!==false && $time>=$cutoff)return ['ready'=>true,'backup'=>$backup];
        }
        if(!$requestedAt)return ['ready'=>false,'requested_at'=>time(),'request'=>$this->requestBackup($site,'fs')];
        return ['ready'=>false,'requested_at'=>$requestedAt];
    }
    public function metrics(string $type,string $key,array $data): array { return (array)$this->call('POST','/metrics/'.rawurlencode($type).'/'.rawurlencode($key),$data); }
    public function errorLogs(string $site,int $start,int $end): array { return (array)$this->call('POST','/site-error-logs/'.rawurlencode($site),['start'=>$start,'end'=>$end,'page_size'=>100,'sort_order'=>'desc']); }
    public function webLogs(string $site,int $start,int $end): array { return (array)$this->call('POST','/site-logs/'.rawurlencode($site),['start'=>$start,'end'=>$end,'page_size'=>100,'sort_order'=>'desc']); }
    public function edgeCache(string $site): array { return (array)$this->call('GET','/edge-cache/'.rawurlencode($site)); }
    public function purgeEdgeCache(string $site,array $uris=[]): array { return (array)$this->call('POST','/edge-cache/'.rawurlencode($site).'/purge',$uris?['purge_uris'=>$uris]:[]); }
    /** Compact, non-secret operational snapshot suitable for the Hub health option. */
    public function diagnostics(string $site): array {
        $end=time();$start=$end-3600;
        $metrics=$this->metrics('site',$site,['start'=>$start,'end'=>$end,'metric'=>['php_workers_average','php_request_limited_percentage','requests'],'dimension'=>'http_host']);
        $periods=$metrics['periods']??[];$latest=end($periods)?:[];
        $errors=$this->errorLogs($site,$start,$end);$web=$this->webLogs($site,$start,$end);
        $statuses=[];foreach((array)($web['logs']??[]) as $row){$code=(string)($row['status']??'');if($code!=='')$statuses[$code]=($statuses[$code]??0)+1;}
        return ['window'=>['start'=>$start,'end'=>$end],'metrics_latest'=>$latest,'php_errors'=>(int)($errors['total_results']??count($errors['logs']??[])),'web_requests'=>(int)($web['total_results']??count($web['logs']??[])),'http_statuses'=>$statuses,'edge_cache'=>$this->edgeCache($site),'webhook_failures'=>count($this->failedWebhooks())];
    }
    public function suspend(string $domain,bool $suspended): void {
        if ($suspended) $this->call('POST','/site-meta/'.rawurlencode($domain).'/suspended/update',['value'=>'503']);
        else $this->call('GET','/site-meta/'.rawurlencode($domain).'/suspended/remove');
    }
    public function delete(string $domain): array { return $this->call('POST','/delete-site/domain/'.rawurlencode($domain)); }
    public function routingIps(string $domain): array {
        $data=$this->call('GET','/get-ips/'.rawurlencode(Config::required('wpcloud_client')).'/'.rawurlencode($domain));
        $ips=$data['ips']??$data['ip_addresses']??$data;
        return array_values(array_filter((array)$ips,fn($ip)=>is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP)));
    }
    public function aliasAdd(string $primary,string $domain): array { return $this->call('GET','/site-alias/domain/'.rawurlencode($primary).'/add/'.rawurlencode($domain)); }
    public function aliasRemove(string $primary,string $domain): array { return $this->call('GET','/site-alias/domain/'.rawurlencode($primary).'/remove/'.rawurlencode($domain)); }
    public function setCanonicalizeAliases(string $domain,bool $enabled): mixed {
        if ($enabled) return $this->call('GET','/site-meta/'.rawurlencode($domain).'/canonicalize_aliases/remove');
        return $this->call('POST','/site-meta/'.rawurlencode($domain).'/canonicalize_aliases/update',['value'=>'false']);
    }
}
