<?php
/** Temporary operator-only acceptance fixture. Never included in a release package. */
if (!defined('ABSPATH')) { return; }
add_action('rest_api_init',function(){
    register_rest_route('dashless-native-fixture/v1','/report',['methods'=>'GET','permission_callback'=>function($r){try{\Dashless\Site\App::boot()->authorize($r);return true;}catch(\Throwable $e){return new WP_Error('unauthorized','Operator fixture only.',['status'=>401]);}},'callback'=>function(){
        $a=\Dashless\Site\App::boot();$c=$a->config();if(($c['site_id']??0)!==152056190||empty($c['adopted']))return new WP_Error('wrong_fixture','Wrong fixture.');
        $work=[];foreach(glob(rtrim(sys_get_temp_dir(),'/').'/dashless-site-152056190-*',GLOB_ONLYDIR) as $dir){$work[]=['path'=>$dir,'files'=>array_map('basename',glob($dir.'/*')),'log'=>is_file($dir.'/process.log')?substr(file_get_contents($dir.'/process.log'),-5000):null,'metrics'=>is_file($dir.'/process-metrics.json')?json_decode(file_get_contents($dir.'/process-metrics.json'),true):null,'process'=>is_file($dir.'/process-result.json')?json_decode(file_get_contents($dir.'/process-result.json'),true):null];}
        $jobs=[];foreach($a->store->rows('job') as $j){$jobs[]=$a->jobs->public($j)+array_intersect_key($j,array_flip(['runner_pid','runner_started','lease_expires']));}
        $legacy=get_option(DASHLESS_WPCLOUD_OPTION,[]);$generation=get_option(DASHLESS_CONTENT_VERSION_OPTION,[]);
        foreach($work as &$entry){$entry['usage']=[];foreach(glob($entry['path'].'/usage-*.jsonl') as $trace){$lines=file($trace,FILE_IGNORE_NEW_LINES);$entry['usage'][basename($trace)]=array_map(fn($s)=>json_decode($s,true),array_merge(array_slice($lines,0,1),array_slice($lines,-3)));}}unset($entry);
        $r=new WP_REST_Response(['jobs'=>$jobs,'workspaces'=>$work,'diagnosis'=>get_option('dashless_native_diagnosis',[]),'legacy_release'=>$legacy['id']??null,'legacy_generation'=>$generation,'runtime_root'=>$c['runtime_path']]);$r->header('Cache-Control','private, no-store');return $r;
    }]);
});
if (!defined('WP_CLI') || !WP_CLI) { return; }
WP_CLI::add_command('dashless acceptance-diagnose',function($args,$assoc){
    $a=\Dashless\Site\App::boot();$c=$a->config();$mode=$args[0]??'';
    if(empty($c['adopted'])||($c['operation']??'')!==($assoc['operation']??'')||($c['site_id']??0)!==152056190||!in_array($mode,['empty-direct','empty-supervised','full-direct','full-serial'],true))WP_CLI::error('Wrong fixed fixture');
    foreach($a->store->rows('job') as $j)if(($j['lease_expires']??0)>=time()&&$j['status']==='running')WP_CLI::error('Wait for the previous lease to expire.');
    $records=get_option('dashless_native_diagnosis',[]);if(isset($records[$mode]))WP_CLI::error('Preserve prior evidence.');
    $work=$a->runtime->workspace();$runtime=$a->runtime->root();$a->runtime->verify();$records[$mode]=['stage'=>'preparing','work'=>$work,'started'=>gmdate('c'),'php_peak_kb'=>getrusage()['ru_maxrss']];update_option('dashless_native_diagnosis',$records,false);
    $snapshot=str_starts_with($mode,'full-')?$a->content->snapshot([],900000001,$work):['frontend_contract'=>1,'generation'=>1,'site_url'=>'https://diagnostic.example','settings'=>['homePageId'=>0,'postsPageId'=>0,'language'=>'en'],'design'=>['version'=>0,'palette'=>'paper','typography'=>'editorial','layout'=>'journal','site_title'=>'Private hosting check','description'=>'Private empty build check.','logo_media_id'=>0,'navigation'=>[]],'items'=>[],'media'=>[],'assets'=>(object)[],'terms'=>['category'=>[],'post_tag'=>[]],'target'=>null];
    \Dashless\Site\Support::write($work.'/snapshot.json',wp_json_encode($snapshot));$records[$mode]['snapshot_posts']=count(array_filter($snapshot['items'],fn($p)=>$p['post_type']==='post'));update_option('dashless_native_diagnosis',$records,false);
    $launcher=(new ReflectionMethod(\Dashless\Site\Runtime::class,'linuxLauncher'))->invoke(null,$runtime,$work);
    $font=$work.'/fonts.conf';file_put_contents($font,'<?xml version="1.0"?><!DOCTYPE fontconfig SYSTEM "urn:fontconfig:fonts.dtd"><fontconfig><dir>'.htmlspecialchars($runtime.'/fonts',ENT_XML1).'</dir><cachedir>'.$work.'/font-cache</cachedir></fontconfig>');
    $trace=__DIR__.'/native-trace.mjs';$env=['PATH'=>$runtime.'/bin:/usr/bin:/bin','HOME'=>$work,'TMPDIR'=>$work,'ASTRO_TELEMETRY_DISABLED'=>'1','NODE_OPTIONS'=>'--max-old-space-size=384 --v8-pool-size=1 --import='.$trace,'UV_THREADPOOL_SIZE'=>'1','UV_USE_IO_URING'=>'0','GOMAXPROCS'=>'1','RAYON_NUM_THREADS'=>'1','VIPS_CONCURRENCY'=>'1','FONTCONFIG_FILE'=>$font,'DASHLESS_NODE_LAUNCHER'=>wp_json_encode($launcher)];
    if($mode==='full-serial')$env['DASHLESS_DIAGNOSTIC_SERIAL_IMAGES']='1';
    $deadline=microtime(true)+235;$command=array_merge($launcher,[$runtime.($mode==='empty-supervised'?'/supervise.mjs':'/build.mjs'),$work]);if($mode==='empty-supervised')$command=array_merge($command,[(string)getmypid(),(string)(int)($deadline*1000)]);
    $p=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$work.'/process.log','a'],2=>['file',$work.'/process.log','a']],$pipes,$work,$env);if(!is_resource($p))WP_CLI::error('Cannot launch fixed check.');$records[$mode]['stage']='running';update_option('dashless_native_diagnosis',$records,false);
    do{$status=proc_get_status($p);if(!$status['running'])break;if(microtime(true)>$deadline){proc_terminate($p,15);usleep(800000);proc_terminate($p,9);break;}usleep(100000);}while(true);
    $records[$mode]['process']=$status;$records[$mode]['close_code']=proc_close($p);$records[$mode]['stage']='complete';$records[$mode]['finished']=gmdate('c');$records[$mode]['php_peak_kb']=getrusage()['ru_maxrss'];$records[$mode]['result']=is_file($work.'/build-result.json')?\Dashless\Site\Support::json($work.'/build-result.json')['quality']:null;update_option('dashless_native_diagnosis',$records,false);WP_CLI::line(wp_json_encode($records[$mode]));
});
WP_CLI::add_command('dashless acceptance-cleanup',function($args,$assoc){
    $c=get_option('dashless_hosted_config',[]);
    if (empty($c['adopted']) || ($c['operation']??'')!==($assoc['operation']??'') || ($c['site_id']??0)!==152056190) { WP_CLI::error('Fixture identity mismatch.'); }
    $app=\Dashless\Site\App::boot();foreach($app->store->rows('job') as $j){if(!in_array($j['status'],['succeeded','failed','canceled'],true)){
        if(!isset($assoc['reconcile']) || ($j['lease_expires']??0)>=time()){WP_CLI::error('A fixture job is still active.');}
        $j['status']='canceled';$j['error']=['code'=>'acceptance_cleanup','message'=>'Operator reconciled completed native tasks.'];$app->store->put('job',$j['job_id'],$j,$j['owner']);
    }}
    $before=get_option(DASHLESS_WPCLOUD_OPTION,[]);$generation=get_option(DASHLESS_CONTENT_VERSION_OPTION,[]);
    $dirs=[$app->vault()->root(),$c['runtime_path']];
    foreach($dirs as $dir){$uploads=wp_upload_dir(null,false)['basedir'];if(!str_starts_with($dir,$uploads.'/dashless-hosted-')||is_link($dir)){WP_CLI::error('Unsafe cleanup target.');}if(is_dir($dir))dashless_wpcloud_remove_assembly($dir);}
    foreach(glob(rtrim(sys_get_temp_dir(),'/').'/dashless-site-152056190-*',GLOB_ONLYDIR) as $dir)\Dashless\Site\Runtime::removeWorkspace($dir);
    global $wpdb;$wpdb->query('DROP TABLE IF EXISTS '.$app->store->table());
    foreach(['dashless_hosted_config','dashless_hosted_schema','dashless_hosted_vault_key','dashless_native_diagnosis'] as $key)delete_option($key);
    WP_CLI::line(wp_json_encode(['cleaned'=>true,'legacy_release'=>$before['id']??null,'legacy_generation'=>$generation['generation']??null]));
});
