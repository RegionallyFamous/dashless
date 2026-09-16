<?php
$root=$argv[1]??'';if(!str_starts_with($root,'/tmp/dashless-site-test-bootstrap-'))throw new RuntimeException('Dedicated bootstrap fixture required');
ob_start();require $root.'/wp-load.php';ob_end_clean();error_reporting(E_ALL & ~E_DEPRECATED);
use Dashless\Site\{App,Cli,Failure,Routes};
$app=App::boot();$packages=json_decode(file_get_contents(dirname(__DIR__).'/dist/site-packages.json'),true);$dist=realpath(dirname(__DIR__).'/dist');$secret=bin2hex(random_bytes(32));
$args=['operation'=>wp_generate_uuid4(),'site-id'=>'123456789','hub'=>'https://hub.example.test','credential-hash'=>hash('sha256',$secret),'package-sha256'=>$packages['site']['sha256'],'archive'=>$dist.'/'.$packages['site']['filename'],'runtime-archive'=>$dist.'/'.$packages['runtime']['filename']];$passed=0;
function ok($yes,$label){global $passed;if(!$yes)throw new RuntimeException('FAIL '.$label);$passed++;echo "PASS $label\n";}
$r=Cli::bootstrap($app,$args);ok($r['bootstrapped']&&$r['site_id']===123456789,'exact installable packages bootstrap explicit atomic identity');
ok((int)wp_count_posts('post')->publish===0&&(int)wp_count_posts('page')->publish===0,'fresh blog contains no sample stories or pages');
ok(Cli::bootstrap($app,$args)['idempotent_replay'],'bootstrap retry is idempotent');
try{Cli::bootstrap($app,array_replace($args,['site-id'=>'111111111']));throw new RuntimeException('Wrong site accepted');}catch(Failure $e){ok($e->slug==='bootstrap_conflict','bootstrap cannot change an existing site identity');}
$config=$app->config();$runtime=realpath(dirname(__DIR__).'/runtime');$config['runtime_path']=$runtime;$config['runtime_sha256']=hash_file('sha256',$runtime.'/runtime-manifest.json');$config['runtime_manifest_sha256']=$config['runtime_sha256'];update_option('dashless_hosted_config',$config,false);
$public=function()use($app){$active=$app->store->get('release','active')['data'];return ['headers'=>['x-dashless-release'=>$active['release_id']],'body'=>(new Routes($app))->artifact($active['candidate'],'index.html',''),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];};add_filter('pre_http_request',$public,10,3);
$job=$app->jobs->enqueue('initial_release',[],0);$result=$app->jobs->run($job['job_id']);ok($result['status']==='succeeded' && $result['publicly_verified'],'fresh empty blog passes real Astro and publication checks (network fixture)');
$active=$app->store->get('release','active')['data'];$html=(new Routes($app))->artifact($active['candidate'],'index.html','');ok(str_contains($html,'No published stories yet')&&!str_contains($html,'Hello world'),'empty publication is honest and contains no invented stories');
echo "$passed bootstrap and empty-blog checks passed. Linux files verified; build used local Node; public network response was a fixture.\n";
