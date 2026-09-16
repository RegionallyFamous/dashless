<?php
/** Configure a disposable local test install; never a production bootstrap substitute. */
$root=$argv[1]??'';$site=(int)($argv[2]??0);$owner=(int)($argv[3]??0);$secretFile=$argv[4]??'';
if (!str_starts_with($root,'/tmp/dashless-site-test-') || !$site || !$owner || !is_file($secretFile)) { throw new RuntimeException('Usage: configure.php /tmp/dashless-site-test-N ATOMIC_ID ACCOUNT_ID SECRET_FILE [URL]'); }
require $root.'/wp-load.php';
if (wp_get_environment_type()!=='local') { throw new RuntimeException('Local fixture only.'); }
$secret=trim(file_get_contents($secretFile));if (!preg_match('/^[a-f0-9]{64}$/D',$secret)) { throw new RuntimeException('A 256-bit hex fixture secret is required.'); }
$app=\Dashless\Site\App::boot();$app->store->install();$runtime=realpath(dirname(__DIR__).'/runtime');
update_option('dashless_hosted_config',['site_id'=>$site,'account_id'=>$owner,'hub'=>'https://dashless.example.test','credential_hash'=>hash('sha256',$secret),'service_user'=>1,'runtime_path'=>$runtime,'runtime_sha256'=>hash_file('sha256',$runtime.'/runtime-manifest.json'),'runtime_manifest_sha256'=>hash_file('sha256',$runtime.'/runtime-manifest.json'),'runtime_verified'=>true,'adopted'=>false],false);
$app->store->put('entitlement','current',['active'=>true,'retain_until'=>0]);
if (isset($argv[5])) { update_option('home',$argv[5]);update_option('siteurl',$argv[5]); }
echo "Configured isolated site $site, account $owner. Secret stored only as a hash.\n";
