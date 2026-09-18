<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

final class Cli {
    public static function register(App $app): void {
        \WP_CLI::add_command('dashless bootstrap',function($args,$assoc)use($app){self::command(fn()=>self::bootstrap($app,$assoc));});
        \WP_CLI::add_command('dashless build-job',function($args,$assoc)use($app){self::command(function()use($app,$args,$assoc){$id=Support::uuid($args[0]??'');if (isset($assoc['retry'])) { $app->jobs->retry($id); }return $app->jobs->run($id);});});
        \WP_CLI::add_command('dashless list-jobs',function()use($app){self::command(fn()=>['jobs'=>$app->jobs->queue()]);});
        \WP_CLI::add_command('dashless rotate-credential',function($args,$assoc)use($app){self::command(fn()=>self::rotate($app,(string)($assoc['credential-hash']??'')));});
        \WP_CLI::add_command('dashless cleanup',function($args,$assoc)use($app){self::command(function()use($app,$assoc){$result=$app->cleanupExpired((int)($assoc['limit']??100));update_option('dashless_hosted_last_cleanup',['time'=>time()]+$result,false);return ['cleanup'=>$result];});});
    }
    private static function command(callable $fn): void {
        try { $result=$fn();\WP_CLI::line(wp_json_encode($result));if (($result['status']??'')==='failed') { \WP_CLI::halt(1); } }
        catch(Failure $e) { \WP_CLI::error($e->slug.': '.$e->getMessage()); }
        catch(\Throwable $e) { \WP_CLI::error('operation_failed: The operation could not be completed.'); }
    }
    private static function fetch(string $hub,string $hash,string $local,string $destination): string {
        if ($local!=='') {
            if (!is_file($local) || is_link($local)) { throw new Failure('package_missing','The operator package archive is unavailable.',503); }
            $file=$local;
        } else {
            $file=$destination;
            $r=wp_remote_get($hub.'/wp-content/dashless-packages/'.$hash.'.zip',['timeout'=>90,'redirection'=>0,'stream'=>true,'filename'=>$file,'limit_response_size'=>250*1024*1024]);
            if (is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200) { throw new Failure('package_missing','The pinned Hub package could not be downloaded.',503); }
        }
        if (!hash_equals($hash,(string)hash_file('sha256',$file))) { throw new Failure('package_invalid','The package checksum did not match.',503); }
        return $file;
    }
    public static function bootstrap(App $app,array $args): array {
        $operation=Support::uuid((string)($args['operation']??''));$id=filter_var($args['site-id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$hub=Support::origin((string)($args['hub']??''));$hash=(string)($args['credential-hash']??'');$package=(string)($args['package-sha256']??'');
        if (!$id || !preg_match('/^[a-f0-9]{64}$/D',$hash) || !preg_match('/^[a-f0-9]{64}$/D',$package)) { throw new Failure('bootstrap_invalid','Explicit atomic site identity and SHA-256 hashes are required.',400); }
        // Never substitute get_current_blog_id(): it is 1 on many unrelated single-site installations.
        if (defined('ATOMIC_SITE_ID') && (int)ATOMIC_SITE_ID!==$id) { throw new Failure('site_mismatch','The requested atomic ID does not match this site.',403); }
        $app->store->install();$existing=$app->config();
        if ($existing) {
            if ($existing['site_id']!==$id || $existing['hub']!==$hub || $existing['operation']!==$operation || $existing['package_sha256']!==$package) { throw new Failure('bootstrap_conflict','This site is already initialized with another identity or package.'); }
            if (($existing['build_driver']??'')!=='railway') $app->runtime->verify();return $app->envelope(['bootstrapped'=>true,'idempotent_replay'=>true]);
        }
        $lock=$app->store->lock('bootstrap',240);if (!$lock) { throw new Failure('bootstrap_busy','Bootstrap is already running.'); }
        $work=$app->runtime->workspace();
        try {
            $file=self::fetch($hub,$package,(string)($args['archive']??''),$work.'/site.zip');$zip=new \ZipArchive();if ($zip->open($file)!==true) { throw new Failure('package_invalid','The site package could not be read.',503); }
            $text=$zip->getFromName('dashless-site/site-manifest.json');$zip->close();$manifest=json_decode((string)$text,true);
            if (!is_array($manifest) || ($manifest['version']??'')!==Support::VERSION) { throw new Failure('package_invalid','The package version is incompatible.',503); }
            Runtime::verifyFiles(dirname(__DIR__),$manifest);
            $railway=($args['build-driver']??'')==='railway';$runtimeHash=$manifest['runtime']['sha256'];$root=dirname(__DIR__).'/build-source';$runtimeManifestHash='';
            if (!$railway) {
                $runtime=self::fetch($hub,$runtimeHash,(string)($args['runtime-archive']??''),$work.'/runtime.zip');
                $root=wp_upload_dir(null,false)['basedir'].'/dashless-hosted-runtime/'.$runtimeHash;
                Runtime::extract($runtime,$root,$runtimeHash);$rm=Support::json($root.'/runtime-manifest.json');Runtime::verifyFiles($root,$rm);$runtimeManifestHash=hash_file('sha256',$root.'/runtime-manifest.json');
            }
            $posts=get_posts(['post_type'=>['post','page'],'post_status'=>['publish','draft','pending','future','private'],'posts_per_page'=>-1]);
            if (!isset($args['preserve-content'])) {
                foreach ($posts as $p) { if (!in_array($p->post_name,['hello-world','sample-page','privacy-policy'],true) || count($posts)>3) { throw new Failure('existing_content','Bootstrap found existing editorial content. Operator adoption requires --preserve-content.'); } }
            }
            $admins=get_users(['role'=>'administrator','number'=>1]);if (!$admins) { throw new Failure('operator_missing','An administrator must exist before bootstrap.',503); }
            $config=['site_id'=>$id,'hub'=>$hub,'operation'=>$operation,'credential_hash'=>$hash,'account_id'=>0,'service_user'=>(int)$admins[0]->ID,'package_sha256'=>$package,'runtime_path'=>$root,'runtime_sha256'=>$runtimeHash,'runtime_manifest_sha256'=>$runtimeManifestHash,'runtime_verified'=>!$railway,'build_driver'=>$railway?'railway':'native','adopted'=>isset($args['preserve-content'])];
            $app->store->transaction(function()use($app,$config,$posts,$args){
                if ($app->config()) { throw new Failure('bootstrap_conflict','The site was initialized concurrently.'); }
                update_option('dashless_hosted_config',$config,false);$app->vault();$app->store->put('entitlement','current',['active'=>true,'retain_until'=>0]);
                if (!isset($args['preserve-content'])) { foreach ($posts as $p) { wp_delete_post($p->ID,true); } }
                $app->content->changed();
            });
            if (!$railway) $app->runtime->verify();Support::purge();return $app->envelope(['bootstrapped'=>true,'plugin_version'=>Support::VERSION,'runtime_sha256'=>$runtimeHash,'preserved_existing_content'=>isset($args['preserve-content'])]);
        } finally { Runtime::removeWorkspace($work);$app->store->unlock('bootstrap',$lock); }
    }
    public static function rotate(App $app,string $hash): array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash)) { throw new Failure('credential_invalid','A credential hash is required.',400); }
        return $app->store->transaction(function()use($app,$hash){
            $c=$app->config();if (!$c) { throw new Failure('not_bootstrapped','Initialize the site first.',409); }
            if (hash_equals($c['credential_hash'],$hash)) { return $app->envelope(['rotated'=>true,'idempotent_replay'=>true]); }
            $c['previous_hash']=$c['credential_hash'];$c['previous_until']=time()+120;$c['credential_hash']=$hash;update_option('dashless_hosted_config',$c,false);return $app->envelope(['rotated'=>true,'previous_expires_at'=>gmdate('c',$c['previous_until'])]);
        });
    }
}
