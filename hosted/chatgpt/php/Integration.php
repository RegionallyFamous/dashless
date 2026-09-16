<?php
namespace Dashless\Hub;

/** ChatGPT adapter. The site contract and the local Codex server remain independent. */
final class Chatgpt {
    public const URI='ui://dashless/workflow-v1.html';
    public const PROTOCOLS=['2025-11-25','2025-06-18','2025-03-26'];
    public static function root(): string { return dirname(__DIR__); }
    public static function extraTools(): array { return json_decode(file_get_contents(self::root().'/tools.json'),true,512,JSON_THROW_ON_ERROR); }
    public static function descriptor(array $tool): array {
        $scope=$tool['scope'];unset($tool['scope']);
        if($tool['name']==='create_media_upload')$tool['description']='Prepare an owner-bound upload session. Use import_chatgpt_file for a user-selected ChatGPT image; this tool alone does not transfer or publish bytes.';
        $tool['title']??=ucwords(str_replace('_',' ',$tool['name']));
        $tool['securitySchemes']=[['type'=>'oauth2','scopes'=>[$scope]]];
        $tool['_meta']['securitySchemes']=$tool['securitySchemes'];
        $tool['_meta']['ui']['visibility']=['model','app'];
        $tool['_meta']['openai/widgetAccessible']=true;
        // The contract has heterogeneous editorial results, intentionally preserved as an open object.
        $tool['outputSchema']=['type'=>'object','additionalProperties'=>true];
        $tool['annotations']['openWorldHint']=in_array($tool['name'],['publish_previewed','rollback_release'],true);
        if(in_array($tool['name'],['update_draft','update_media','update_design'],true))$tool['annotations']['destructiveHint']=true;
        // get_job reconciles/dispatches queued jobs in the existing Hub implementation.
        if($tool['name']==='get_job')$tool['annotations']['readOnlyHint']=false;
        if(in_array($tool['name'],['show_workflow','request_publication_approval','request_rollback_approval'],true)) {
            $tool['_meta']['ui']['resourceUri']=self::URI;
            $tool['_meta']['openai/outputTemplate']=self::URI;
        }
        return $tool;
    }
    public static function resource(): array {
        $origin=Config::origin();$domain=(string)Config::get('chatgpt_widget_origin','https://dashless.blog');
        if(!preg_match('~^https://[a-z0-9.-]+$~D',$domain))throw new Failure('widget_configuration','The component origin needs configuration.',503);
        $file=self::root().'/dist/workflow.html';
        if(!is_file($file))throw new Failure('component_missing','The component package is not installed.',503);
        return ['uri'=>self::URI,'name'=>'Dashless publishing workflow','mimeType'=>'text/html;profile=mcp-app','text'=>file_get_contents($file),
            '_meta'=>['ui'=>['prefersBorder'=>true,'domain'=>$domain,'csp'=>['connectDomains'=>[],'resourceDomains'=>[]]],
            'openai/widgetDescription'=>'Private build status and a link to signed-in preview and publication approval. Publishing is completed in the authenticated Dashless browser.',
            'openai/widgetCSP'=>['connect_domains'=>[],'resource_domains'=>[],'redirect_domains'=>[$origin]],'openai/widgetDomain'=>$domain]];
    }
    public static function challenge(string $scope='',string $error='invalid_token'): string {
        return 'Bearer resource_metadata="'.Config::origin().'/.well-known/oauth-protected-resource/mcp", error="'.$error.'", error_description="Reconnect your Dashless account"'.($scope!==''?', scope="'.$scope.'"':'');
    }
    public static function uuid(string $value): string {
        if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$value))throw new Failure('invalid_identifier','Use an identifier returned by Dashless.');
        return $value;
    }
    public static function capabilities(Agent $agent,array $a,array $required): array {
        $result=$agent->call($a,'GET','/capabilities');
        foreach($required as $name)if(($result['capabilities'][$name]??false)!==true)throw new Failure('capability_unavailable','Your site needs an update before this operation is available.',409);
        return $result['capabilities'];
    }
    public static function epoch(Store $store,int $owner): string { return $store->get('oauth_epoch',(string)$owner)['data']['epoch']??'initial'; }
    public static function handoff(Store $store,array $a,string $kind,string $id,array $binding=[]): array {
        if($kind==='publish')self::uuid($id);
        elseif(!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$id))throw new Failure('invalid_release','Invalid release identifier.');
        $token=bin2hex(random_bytes(32));$expires=time()+900;$owner=(int)$a['user_id'];
        // Store::prune already expires consent rows; a ticket is a read handoff, never an approval.
        $store->add('consent','chatgpt:'.hash('sha256',$token),['site_id'=>$a['site_id'],'kind'=>$kind,'target'=>$id,'binding'=>$binding,'epoch'=>self::epoch($store,$owner)],$owner,'unused',$expires);
        return ['review_url'=>Config::origin().'/preview/?'.http_build_query([$kind==='publish'?'preview':'release'=>$id,'handoff'=>$token]),'review_expires_at'=>$expires];
    }
    public static function verifyHandoff(Store $store,array $a,string $kind,string $target,string $token): array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new Failure('handoff_invalid','Reopen the review from your Dashless conversation.',403);
        $row=$store->get('consent','chatgpt:'.hash('sha256',$token));
        if(!$row || $row['expires']<=time() || $row['owner']!==(int)$a['user_id'] || ($row['data']['site_id']??null)!==$a['site_id'] || $row['data']['kind']!==$kind || $row['data']['target']!==$target || $row['data']['epoch']!==self::epoch($store,(int)$a['user_id']))throw new Failure('handoff_invalid','Sign in to the connected account and reopen this review from your conversation.',403);
        return $row['data'];
    }
    public static function rollbackBinding(array $releases,string $target): array {
        $previous=$releases['previous']??null;$active=$releases['active']??null;
        if(!is_array($previous) || !is_array($active) || ($previous['verified']??false)!==true || ($previous['release_id']??'')!==$target || empty($previous['manifest_hash']) || empty($active['release_id']))throw new Failure('rollback_unavailable','That verified previous release is not available. Refresh the release list.',409);
        return ['target'=>$target,'manifest_hash'=>$previous['manifest_hash'],'active'=>$active['release_id']];
    }
    /** Deny secret fields anywhere, including in accidental nested upstream results. */
    public static function modelData(array $data): array {
        $out=[];
        foreach($data as $key=>$value) {
            if(is_string($key) && preg_match('/(?:^_|token|secret|password|api_key|credential|cookie|nonce|approval_id|download_url|preview_url|upload_url|review_url|authorization|account_id|site_id|debug|trace|stack|environment|stdout|stderr|local_path)/i',$key))continue;
            if(is_array($value))$value=self::modelData($value);
            if(is_string($value) && (preg_match('~(?:[?&](?:token|ticket|nonce|signature|sig|handoff)=|/wp-json/dashless-hosted/v1/(?:previews|exports|uploads)/)~i',$value)))throw new Failure('unsafe_site_result','The site returned private data in an unsupported format. Please try again after the site is updated.',503);
            $out[$key]=$value;
        }
        return $out;
    }
}
require_once __DIR__.'/Uploads.php';
