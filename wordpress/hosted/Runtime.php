<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

final class Runtime {
    public function __construct(private App $app) {}
    public function root(): string { return (string)($this->app->config()['runtime_path']??''); }
    public function digest(): ?string { return $this->app->config()['runtime_sha256']??null; }
    public function ready(): bool {
        $r=$this->root();$c=$this->app->config();
        if (($c['build_driver']??'')==='railway') return !empty($c['builder_url']);
        return $r!=='' && !empty($c['runtime_verified']) && is_file($r.'/runtime-manifest.json') && hash_equals((string)($c['runtime_manifest_sha256']??''),(string)hash_file('sha256',$r.'/runtime-manifest.json')) && is_file($r.'/build.mjs') && function_exists('proc_open');
    }
    public function verify(): void {
        if (!$this->ready()) { throw new Failure('runtime_unavailable','The pinned runtime has not been installed and verified.',503); }
        if ((new RemoteRuntime($this->app))->configured()) return;
        self::verifyFiles($this->root(),Support::json($this->root().'/runtime-manifest.json'));
    }
    public static function verifyFiles(string $root,array $manifest): void {
        if (empty($manifest['files'])) { throw new Failure('package_invalid','The installed package has no integrity manifest.',503); }
        foreach ($manifest['files'] as $entry) {
            $rel=Support::relative($entry['path']);$file=$root.'/'.$rel;$real=realpath($file);
            if (!$real || !str_starts_with($real,realpath($root).'/') || is_link($file) || !is_file($file) || filesize($file)!==$entry['bytes'] || !hash_equals($entry['sha256'],(string)hash_file('sha256',$file))) { throw new Failure('package_invalid','The installed package failed integrity verification.',503); }
        }
    }
    public function workspace(): string {
        $dir=rtrim(sys_get_temp_dir(),'/').'/dashless-site-'.$this->app->siteId().'-'.bin2hex(random_bytes(12));
        if (!mkdir($dir,0700,true)) { throw new Failure('workspace_failed','The private build workspace is unavailable.',503); }
        if (str_starts_with(realpath($dir),realpath(ABSPATH).'/')) { rmdir($dir);throw new Failure('workspace_unsafe','Private workspaces must be outside the document root.',503); }
        return $dir;
    }
    public static function removeWorkspace(string $dir): void {
        if (!preg_match('~/dashless-site-\d+-[a-f0-9]{24}$~',$dir) || !str_starts_with($dir,rtrim(sys_get_temp_dir(),'/').'/') || is_link($dir)) { throw new Failure('workspace_unsafe','Refusing to remove an unrecognized workspace.',503); }
        if (!is_dir($dir)) { return; }
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname()); }rmdir($dir);
    }
    /** Select an already-allowed CPU, never widen the host's affinity mask. */
    public static function firstAllowedCpu(string $output): int {
        if (!preg_match('/:\s*(\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*)\s*$/D',$output,$match)) { throw new Failure('runtime_unavailable','The build CPU limit could not be verified.',503); }
        return (int)preg_split('/[-,]/',$match[1])[0];
    }
    private static function linuxLauncher(string $root,string $work): array {
        $loader=[$root.'/lib/ld-linux-x86-64.so.2','--library-path',$root.'/lib'];
        $query=proc_open(array_merge($loader,[$root.'/bin/taskset','--cpu-list','--pid',(string)getmypid()]),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,$work,['LANG'=>'C']);
        if (!is_resource($query)) { throw new Failure('runtime_unavailable','The build CPU limit is unavailable.',503); }
        stream_set_blocking($pipes[1],false);$output='';$expires=microtime(true)+2;$exit=-1;
        try {
            do {
                $output.=stream_get_contents($pipes[1]);$status=proc_get_status($query);
                if (!$status['running']) { $exit=$status['exitcode'];break; }
                if (microtime(true)>$expires) { proc_terminate($query,9);break; }
                usleep(10000);
            } while (true);
            $output.=stream_get_contents($pipes[1]);
        } finally { fclose($pipes[1]);$closed=proc_close($query);if ($exit<0) { $exit=$closed; } }
        if ($exit!==0) { throw new Failure('runtime_unavailable','The build CPU limit could not be verified.',503); }
        return array_merge($loader,[$root.'/bin/taskset','--cpu-list',(string)self::firstAllowedCpu($output)],$loader,[$root.'/bin/node']);
    }
    public function run(string $work,float $deadline,callable $progress,string $remoteId=""): array {
        if ((new RemoteRuntime($this->app))->configured()) return (new RemoteRuntime($this->app))->run($work,$deadline,$progress,$remoteId);
        $this->verify();$root=$this->root();$manifest=Support::json($root.'/runtime-manifest.json');
        if (PHP_OS_FAMILY==='Linux') { $launcher=self::linuxLauncher($root,$work); }
        elseif (wp_get_environment_type()==='local' && defined('DASHLESS_LOCAL_NODE')) { $launcher=[DASHLESS_LOCAL_NODE]; }
        else { throw new Failure('runtime_platform','This runtime requires Linux x86-64.',503); }
        $fontConfig=$work.'/fonts.conf';Support::write($fontConfig,'<?xml version="1.0"?><!DOCTYPE fontconfig SYSTEM "urn:fontconfig:fonts.dtd"><fontconfig><dir>'.htmlspecialchars($root.'/fonts',ENT_XML1).'</dir><cachedir>'.htmlspecialchars($work.'/font-cache',ENT_XML1).'</cachedir></fontconfig>');
        $env=['PATH'=>$root.'/bin:/usr/bin:/bin','ASTRO_TELEMETRY_DISABLED'=>'1','NODE_OPTIONS'=>'--max-old-space-size=384 --v8-pool-size=1','UV_THREADPOOL_SIZE'=>'1','UV_USE_IO_URING'=>'0','GOMAXPROCS'=>'1','RAYON_NUM_THREADS'=>'1','VIPS_CONCURRENCY'=>'1','FONTCONFIG_FILE'=>$fontConfig,'DASHLESS_NODE_LAUNCHER'=>wp_json_encode($launcher),'TMPDIR'=>$work,'HOME'=>$work];
        if (wp_get_environment_type()==='local' && !is_dir($root.'/fonts')) { unset($env['FONTCONFIG_FILE']); }
        $cmd=array_merge($launcher,[$root.'/supervise.mjs',$work,(string)getmypid(),(string)(int)($deadline*1000)]);
        $process=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['file',$work.'/process.log','a'],2=>['file',$work.'/process.log','a']],$pipes,$work,$env);
        if (!is_resource($process)) { throw new Failure('build_launch_failed','The native build could not start.',503); }
        $state=proc_get_status($process);$progress(['runner_pid'=>$state['pid'],'runner_started'=>time()]);$exit=-1;
        try {
            do {
                $state=proc_get_status($process);
                if (!$state['running']) { $exit=$state['exitcode'];break; }
                if (microtime(true)>$deadline) { proc_terminate($process,15);usleep(900000);$state=proc_get_status($process);if ($state['running']) { proc_terminate($process,9); }throw new Failure('build_deadline','The build exceeded its time limit. The previous public release is preserved.'); }
                usleep(100000);
            } while (true);
        } finally { $closed=proc_close($process);if ($exit<0) { $exit=$closed; } }
        $metrics=is_file($work.'/process-result.json')?Support::json($work.'/process-result.json'):[];
        $progress(['peak_memory_bytes'=>(int)($metrics['peak_memory_bytes']??0),'memory_method'=>$metrics['memory_method']??'unavailable']);
        if ($exit!==0 || !empty($metrics['reason'])) { do_action('dashless_hosted_process_failure',$work);throw new Failure(in_array($metrics['reason']??'',['build_deadline','build_memory_limit','parent_lost'],true)?$metrics['reason']:'build_failed','The native build failed. The previous public release is preserved.'); }
        return Support::json($work.'/build-result.json')+$metrics;
    }
    public static function extract(string $archive,string $destination,string $expected): void {
        if (!hash_equals($expected,(string)hash_file('sha256',$archive))) { throw new Failure('package_invalid','The downloaded package checksum did not match.',503); }
        $zip=new \ZipArchive();if ($zip->open($archive)!==true) { throw new Failure('package_invalid','The package archive is invalid.',503); }
        wp_mkdir_p($destination);$total=0;
        try { for ($i=0;$i<$zip->numFiles;$i++) {
            $stat=$zip->statIndex($i);$name=$stat['name'];if (str_ends_with($name,'/')) { Support::relative(rtrim($name,'/'));continue; }
            Support::relative($name);$total+=$stat['size'];if ($total>800*1024*1024) { throw new Failure('package_invalid','The package is too large.',503); }
            $zip->getExternalAttributesIndex($i,$os,$attr);if (($attr>>16&0170000)===0120000) { throw new Failure('package_invalid','Package links are not allowed.',503); }
            $dest=$destination.'/'.$name;if (!wp_mkdir_p(dirname($dest))) { throw new Failure('storage_failed','The required files could not be saved.',503); }
            $temporary=$dest.'.'.bin2hex(random_bytes(8)).'.tmp';$input=$zip->getStream($name);$output=fopen($temporary,'xb');
            if (!$input || !$output) { if (is_resource($input)) { fclose($input); }if (is_resource($output)) { fclose($output); }throw new Failure('package_invalid','A required file could not be read.',503); }
            chmod($temporary,0600);
            try { if (stream_copy_to_stream($input,$output)!==$stat['size']) { throw new Failure('storage_failed','The required files could not be saved.',503); } }
            finally { fclose($input);fclose($output); }
            if (!rename($temporary,$dest)) { unlink($temporary);throw new Failure('storage_failed','The required files could not be saved.',503); }
            if (str_starts_with($name,'bin/') || str_contains($name,'/bin/') || str_starts_with($name,'lib/')) { chmod($dest,0755); }
        } } finally { $zip->close(); }
    }
}
