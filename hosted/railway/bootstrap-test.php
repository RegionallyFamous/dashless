<?php
$root=$argv[1]??'';
if(!str_starts_with($root,'/tmp/dashless-site-test-bootstrap-railway-'))throw new RuntimeException('Disposable fixture required');
ob_start();require $root.'/wp-load.php';ob_end_clean();error_reporting(E_ALL & ~E_DEPRECATED);
use Dashless\Site\{App,Cli};
$app=App::boot();$p=json_decode(file_get_contents(dirname(__DIR__).'/dist/site-packages.json'),true)['site'];
$args=['operation'=>wp_generate_uuid4(),'site-id'=>'4242003','hub'=>'https://dashless.blog','credential-hash'=>hash('sha256',random_bytes(32)),'package-sha256'=>$p['sha256'],'archive'=>realpath(dirname(__DIR__).'/dist/'.$p['filename']),'build-driver'=>'railway'];
$r=Cli::bootstrap($app,$args);
if (!$r['bootstrapped'] || $app->config()['build_driver']!=='railway' || is_dir($app->runtime->root().'/node_modules') || !is_file($app->runtime->root().'/template/package.json'))throw new RuntimeException('Railway bootstrap incorrect');
if(!Cli::bootstrap($app,$args)['idempotent_replay'])throw new RuntimeException('Retry not idempotent');
if((int)wp_count_posts('post')->publish || (int)wp_count_posts('page')->publish)throw new RuntimeException('Sample content retained');
echo json_encode(['passed'=>true,'installed_plugin_package_verified'=>true,'node_runtime_not_installed'=>true,'portable_source_present'=>true,'bootstrap_replay'=>true,'sample_content_removed'=>true],JSON_PRETTY_PRINT)."\n";
