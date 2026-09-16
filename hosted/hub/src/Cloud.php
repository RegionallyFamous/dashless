<?php
namespace Dashless\Hub;
class Cloud {
    public function call(string $method,string $path,array $data=[]): mixed {
        $response=wp_remote_request('https://atomic-api.wordpress.com/api/v1.0/'.ltrim($path,'/'),[
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
        return $this->call('POST','/create-site/'.rawurlencode(Config::required('wpcloud_client')),[
            'domain_name'=>$a['domain'],'admin_user'=>'dashless_operator','admin_email'=>Config::required('support_email'),
            'admin_pass'=>Crypto::open($a['admin_secret']),'php_version'=>'8.3','space_quota'=>'25G',
            'software'=>[self::packageKey($url)=>'activate-locked'],
            'meta'=>['default_php_conns'=>'2','burst_php_conns'=>'0','php_memory_limit'=>'512','_data'=>wp_json_encode(['dashless'=>['account'=>$a['user_id'],'operation'=>$a['provision_id']]])],
        ]);
    }
    public function task(int $site,array $args): string {
        if ($site<1 || ($args[0]??'')!=='dashless' || !in_array($args[1]??'',['bootstrap','build-job','rotate-credential'],true)) throw new Failure('invalid_task','Invalid site task.',400);
        $r=$this->call('POST','/task-create/'.rawurlencode(Config::required('wpcloud_client')).'/run-wp-cli-command',[
            'args'=>$args,'site_run_list'=>[$site],'cli_mode'=>'full','site_count_limit'=>'1','send_webhook_for'=>'none',
        ]);
        if (empty($r['task_id'])) throw new Failure('task_uncertain','Task dispatch needs reconciliation.',503);
        return (string)$r['task_id'];
    }
    public function taskStatus(string $id): array { return $this->call('GET','/task-get/'.rawurlencode($id)); }
    public function suspend(string $domain,bool $suspended): void {
        if ($suspended) $this->call('POST','/site-meta/'.rawurlencode($domain).'/suspended/update',['value'=>'503']);
        else $this->call('GET','/site-meta/'.rawurlencode($domain).'/suspended/remove');
    }
    public function delete(string $domain): array { return $this->call('POST','/delete-site/domain/'.rawurlencode($domain)); }
}
