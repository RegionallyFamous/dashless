<?php
namespace Dashless\Hub;
require_once (is_file(dirname(__DIR__).'/chatgpt/php/Integration.php') ? dirname(__DIR__).'/chatgpt/php/Integration.php' : dirname(__DIR__,2).'/chatgpt/php/Integration.php');
final class Mcp {
    public function __construct(private Store $store,private Identity $identity,private Agent $agent,private Jobs $jobs) {}
    public static function tools(): array {
        $tools=array_merge(json_decode(file_get_contents(dirname(__DIR__).'/contracts/tools.v1.json'),true,512,JSON_THROW_ON_ERROR),Chatgpt::extraTools());
        // PHP decodes an empty JSON object as [], which wp_json_encode() would
        // emit as an array. OpenAI's MCP validator requires properties to stay
        // an object, even for no-argument tools such as inspect_site.
        foreach($tools as &$tool) {
            if(isset($tool['inputSchema']['properties']) && is_array($tool['inputSchema']['properties']) && $tool['inputSchema']['properties']===[])$tool['inputSchema']['properties']=new \stdClass();
        }
        unset($tool);
        return $tools;
    }
    public function handle(array $message,array $auth): ?array {
        $id=$message['id']??null;$method=$message['method']??'';
        if(!array_key_exists('id',$message) && ($message['jsonrpc']??'')==='2.0' && is_string($method) && str_starts_with($method,'notifications/'))return null;
        try {
            if (($message['jsonrpc']??'')!=='2.0' || !is_string($method) || !array_key_exists('id',$message) || !(is_int($id) || is_string($id)) || (isset($message['params']) && !is_array($message['params']))) throw new Failure('invalid_request','Invalid MCP request.');
            $result=match($method) {
                'initialize'=>['protocolVersion'=>in_array($message['params']['protocolVersion']??'',Chatgpt::PROTOCOLS,true)?$message['params']['protocolVersion']:Chatgpt::PROTOCOLS[0],'capabilities'=>['tools'=>['listChanged'=>false],'resources'=>['subscribe'=>false,'listChanged'=>false]],'serverInfo'=>['name'=>'dashless','version'=>'0.1.0'],'instructions'=>'Manage the connected blog. Content changes require an editorial request. Publishing requires the exact preview and an authenticated user approval. Never create starter posts to populate a design. During setup and redesign call list_themes and offer the available named themes with their preview images. Use get_theme before selection, then update_design with theme_id and theme_version. Explicit presentation settings override theme defaults. Preserve identity and content, and create a private preview using real content before requesting publication approval. Custom design uses the supported options; do not promise arbitrary generated code.'],
                'ping'=>new \stdClass(),
                'resources/list'=>['resources'=>[['uri'=>Chatgpt::URI,'name'=>'Dashless publishing workflow','mimeType'=>'text/html;profile=mcp-app']]],
                'resources/templates/list'=>['resourceTemplates'=>[]],
                'resources/read'=>($message['params']['uri']??'')===Chatgpt::URI ? ['contents'=>[Chatgpt::resource()]] : throw new Failure('resource_missing','Resource not found.',404),
                'tools/list'=>['tools'=>array_map([Chatgpt::class,'descriptor'],self::tools())],
                'tools/call'=>$this->call($auth,$message['params']??[]),
                default=>throw new Failure('method_not_found','Unsupported MCP method.',404),
            };
            return ['jsonrpc'=>'2.0','id'=>$id,'result'=>$result];
        } catch(Failure $e) { return ['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$e->slug==='method_not_found'?-32601:($e->slug==='invalid_request'?-32600:($e->slug==='resource_missing'?-32002:-32602)),'message'=>$e->getMessage(),'data'=>['code'=>$e->slug]]]; }
    }
    private function call(array $auth,array $params): array {
        $owner=(int)$auth['owner'];$name=$params['name']??'';$tool=null;
        foreach(self::tools() as $candidate)if($candidate['name']===$name)$tool=$candidate;
        if(!$tool)throw new Failure('tool_missing','Unknown tool.',404);
        if(!in_array($tool['scope'],$auth['scopes'],true)) { $r=self::result(['code'=>'insufficient_scope','message'=>'Reconnect with the required permission.'],true);$r['_meta']['mcp/www_authenticate']=[Chatgpt::challenge($tool['scope'],'insufficient_scope')];return $r; }
        $args=$params['arguments']??[];
        if(!is_array($args) || array_is_list($args) && $args!==[])throw new Failure('invalid_arguments','Expected tool arguments.');
        foreach($tool['inputSchema']['required']??[] as $key)if(!array_key_exists($key,$args))throw new Failure('missing_argument','Missing required argument: '.$key);
        foreach($args as $key=>$value)if(!array_key_exists($key,$tool['inputSchema']['properties']))throw new Failure('invalid_argument','Unexpected argument: '.$key);
        $validation=rest_validate_value_from_schema($args,$tool['inputSchema'],'arguments');
        if(is_wp_error($validation))throw new Failure('invalid_arguments','Arguments do not match the tool schema.');
        $this->store->rate('tools:'.$owner,120,60);
        try {
            $a=$this->identity->account($owner);
            if($name==='list_themes')return self::result(['themes'=>Themes::catalog()]);
            if($name==='get_theme')return self::result(Themes::get($args['theme_id']));
            if($name==='get_status')return self::result(['state'=>$a['state'],'entitlement'=>$a['entitlement']??'none','site_url'=>isset($a['domain'])?'https://'.$a['domain']:null,'ready'=>$a['state']==='ready']);
            if(!in_array($a['entitlement']??'',['active','grace'],true) && !in_array($name,['export_site','get_job','show_workflow'],true))throw new Failure('subscription_inactive','This account does not currently include editing access.',403);
            if($a['state']!=='ready' && !(in_array($name,['export_site','get_job','show_workflow'],true) && $a['state']==='suspended'))throw new Failure('site_not_ready','Your site is not ready for this action.',409);
            if(in_array($name,['get_job','show_workflow'],true)) {
                $data=$this->jobs->status($owner,Chatgpt::uuid((string)$args['job_id']));$meta=[];
                if(!empty($data['preview_id'])) {
                    $publication=$this->store->get('consent','published:'.$owner.':'.$data['preview_id']);
                    if($publication && $publication['owner']===$owner && $publication['expires']>time() && $publication['data']['site_id']===$a['site_id'])$data=$this->jobs->status($owner,Chatgpt::uuid($publication['data']['job_id']));
                }
                if(($data['status']??'')==='succeeded' && !empty($data['preview_id']) && $a['state']==='ready' && $name==='show_workflow')$meta=Chatgpt::handoff($this->store,$a,'publish',Chatgpt::uuid($data['preview_id']));
                return self::result($data,false,$meta);
            }
            if($name==='import_chatgpt_file')return self::result(ChatgptUploads::run($this->store,$this->agent,$a,$args));
            if($name==='request_rollback_approval') {
                Chatgpt::capabilities($this->agent,$a,['rollback_approval_v1']);
                $releases=$this->agent->call($a,'POST','/tools/get_release',['arguments'=>[],'actor'=>['account_id'=>$owner]]);
                return self::result(['release_id'=>$args['release_id'],'approval_required'=>true],false,Chatgpt::handoff($this->store,$a,'rollback',$args['release_id'],Chatgpt::rollbackBinding($releases,$args['release_id'])));
            }
            $required=match($name){'create_preview','request_publication_approval'=>['private_previews','jobs'],'publish_previewed'=>['approvals','jobs'],'rollback_release'=>['rollback_approval_v1','jobs'],'export_site'=>['exports','jobs'],'get_design','update_design'=>['design'],default=>[]};
            if($name==='update_design' && (isset($args['changes']['theme_id']) || isset($args['changes']['theme_version'])))$required[]='theme_catalog_v1';
            Chatgpt::capabilities($this->agent,$a,$required);
            // All tenant routing is derived from the OAuth identity, never a tool argument.
            $result=$this->agent->call($a,'POST','/tools/'.$name,['arguments'=>$args,'actor'=>['account_id'=>$owner],'idempotency_key'=>$args['client_key']??null]);
            if(!empty($result['job_id'])) {$this->jobs->remember($owner,$result,$name==='export_site'?'export':'build');$this->jobs->tick($owner);}
            $meta=[];
            if($name==='request_publication_approval')$meta=Chatgpt::handoff($this->store,$a,'publish',Chatgpt::uuid($args['preview_id']));
            return self::result($result,false,$meta);
        } catch(Failure $e) { return self::result(['code'=>$e->slug,'message'=>$e->getMessage()],true); }
    }
    private static function result(array $data,bool $error=false,array $meta=[]): array {
        // Widget-only data must never appear in model-visible text/structuredContent.
        $data=Chatgpt::modelData($data);
        $result=['content'=>[['type'=>'text','text'=>wp_json_encode($data)]],'structuredContent'=>$data,'isError'=>$error];
        if($meta)$result['_meta']=$meta;
        return $result;
    }
}
