<?php
/** Temporary fixed operator checks; excluded from the shipped plugin. */
if(!defined('ABSPATH'))exit;
$settings=json_decode(file_get_contents(__DIR__.'/dashless-host-diagnostic/settings.json'),true);
define('DASHLESS_DIAGNOSTIC_OPERATION',$settings['operation']);define('DASHLESS_DIAGNOSTIC_HASH',$settings['credential_hash']);define('DASHLESS_DIAGNOSTIC_RUNTIME_HASH',$settings['runtime_sha256']);
function dashless_host_diagnostic_root(){return wp_upload_dir(null,false)['basedir'].'/dashless-host-diagnostic/'.DASHLESS_DIAGNOSTIC_OPERATION;}
function dashless_host_diagnostic_work(){return '/tmp/dashless-host-diagnostic-'.DASHLESS_DIAGNOSTIC_OPERATION;}
add_action('rest_api_init',function(){register_rest_route('dashless-host-diagnostic/v1','/report',['methods'=>'GET','permission_callback'=>function($r){$h=$r->get_header('authorization');return preg_match('/^Bearer ([a-f0-9]{64})$/D',$h,$m)&&hash_equals(DASHLESS_DIAGNOSTIC_HASH,hash('sha256',$m[1]));},'callback'=>function(){
    $result=get_option('dashless_host_diagnostic',[]);$traces=[];foreach(glob(dashless_host_diagnostic_work().'/*/trace.jsonl') as $file){$traces[basename(dirname($file))]=array_map(fn($s)=>json_decode($s,true),file($file,FILE_IGNORE_NEW_LINES));}
    $logs=[];foreach(glob(dashless_host_diagnostic_work().'/*/process.log') as $file){$logs[basename(dirname($file))]=substr(file_get_contents($file),-6000);}
    $r=new WP_REST_Response(['operation'=>DASHLESS_DIAGNOSTIC_OPERATION,'result'=>$result,'traces'=>$traces,'logs'=>$logs]);$r->header('Cache-Control','private, no-store');return $r;
}]);});
if(!defined('WP_CLI')||!WP_CLI)return;
WP_CLI::add_command('dashless diagnostic',function($args,$assoc){
    $mode=$args[0]??'';if(!in_array($mode,['setup','host-limits','node','node-basic','timer','cpu','astro-import','rolldown-import','transform','transform-js','astro-minimal','astro-setup','astro-traced','astro-plain','astro-cold','shared-empty','astro-cold-sampled','astro-cold-cpu-one','shared-cpu-one','astro-cold-again-cpu-one','astro-cold-control','shared-again-cpu-one','cleanup'],true))WP_CLI::error('Unknown fixed check.');
    require_once __DIR__.'/dashless-host-diagnostic/Support.php';require_once __DIR__.'/dashless-host-diagnostic/Runtime.php';
    $root=dashless_host_diagnostic_root();$work=dashless_host_diagnostic_work();
    if($mode==='host-limits'){$record=['mode'=>$mode,'limits'=>function_exists('posix_getrlimit')?posix_getrlimit():null,'tools'=>[]];foreach(['/usr/bin/taskset','/bin/taskset','/usr/bin/time','/bin/time'] as $file)$record['tools'][$file]=is_executable($file);update_option('dashless_host_diagnostic',$record,false);WP_CLI::line(wp_json_encode($record));return;}
    if($mode==='setup'){
        \Dashless\Site\Runtime::extract($root.'/runtime.zip',$root.'/runtime',DASHLESS_DIAGNOSTIC_RUNTIME_HASH);\Dashless\Site\Runtime::verifyFiles($root.'/runtime',\Dashless\Site\Support::json($root.'/runtime/runtime-manifest.json'));copy(__DIR__.'/dashless-host-diagnostic/host-diagnostic.mjs',$root.'/runtime/host-diagnostic.mjs');WP_CLI::success('Pinned runtime installed for fixed checks only.');return;
    }
    if($mode==='cleanup'){
        foreach([$root,$work] as $dir){if(is_link($dir)||!str_contains($dir,DASHLESS_DIAGNOSTIC_OPERATION))WP_CLI::error('Unsafe cleanup');if(is_dir($dir)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($dir);}}
        delete_option('dashless_host_diagnostic');WP_CLI::success('Fixed check files removed.');return;
    }
    $work.='/'.$mode;if(is_dir($work))WP_CLI::error('This check already has evidence. Use another operation, not an overwrite.');mkdir($work,0700,true);
    $record=['mode'=>$mode,'stage'=>'php-start','started'=>gmdate('c'),'php_pid'=>getmypid(),'php_memory_limit'=>ini_get('memory_limit'),'php_usage'=>getrusage(),'operation'=>DASHLESS_DIAGNOSTIC_OPERATION];update_option('dashless_host_diagnostic',$record,false);
    register_shutdown_function(function()use(&$record){$record['shutdown']=true;$e=error_get_last();if($e)$record['last_error']=['type'=>$e['type'],'message'=>$e['message']];update_option('dashless_host_diagnostic',$record,false);});
    $runtime=$root.'/runtime';$env=['PATH'=>$runtime.'/bin:/usr/bin:/bin','HOME'=>$work,'TMPDIR'=>$work,'ASTRO_TELEMETRY_DISABLED'=>'1','NODE_OPTIONS'=>'--max-old-space-size=384 --v8-pool-size=1','UV_THREADPOOL_SIZE'=>'1','UV_USE_IO_URING'=>'0','GOMAXPROCS'=>'1','RAYON_NUM_THREADS'=>'1','VIPS_CONCURRENCY'=>'1'];
    $command=[$runtime.'/lib/ld-linux-x86-64.so.2','--library-path',$runtime.'/lib',$runtime.'/bin/node',$runtime.'/host-diagnostic.mjs',$mode,$work];
    if(str_ends_with($mode,'cpu-one'))$command=array_merge(['/usr/bin/taskset','--cpu-list','0'],$command);
    $record['stage']='before-proc-open';update_option('dashless_host_diagnostic',$record,false);$p=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$work.'/process.log','a'],2=>['file',$work.'/process.log','a']],$pipes,$work,$env);
    if(!is_resource($p))WP_CLI::error('Cannot launch fixed check');$record['stage']='child-running';update_option('dashless_host_diagnostic',$record,false);$start=microtime(true);
    do{$status=proc_get_status($p);if(!$status['running'])break;if(microtime(true)-$start>25){proc_terminate($p,15);usleep(500000);proc_terminate($p,9);break;}usleep(100000);}while(true);
    $record['status']=$status;$record['close_code']=proc_close($p);$record['stage']='complete';$record['duration']=microtime(true)-$start;$record['php_usage_final']=getrusage();update_option('dashless_host_diagnostic',$record,false);WP_CLI::line(wp_json_encode($record));
});
