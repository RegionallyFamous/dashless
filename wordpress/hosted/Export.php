<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

/** Resumable uncompressed POSIX tar, encrypted one MiB at a time; never a growing plaintext ZIP. */
final class Export {
    public function __construct(private App $app) {}
    private function header(string $name,int $size): string {
        Support::relative($name);$prefix='';
        if (strlen($name)>100) { $at=strrpos($name,'/');$prefix=substr($name,0,$at);$name=substr($name,$at+1); }
        if (strlen($name)>100 || strlen($prefix)>155 || $size>8589934591) { throw new Failure('export_entry_limit','An export path or file exceeds the portable archive limit.'); }
        $h=str_pad($name,100,"\0").sprintf('%07o',0600)."\0".str_repeat("0000000\0",2).sprintf('%011o',$size)."\0"."00000000000\0".str_repeat(' ',8).'0'.str_repeat("\0",100)."ustar\00000".str_repeat("\0",80).str_pad($prefix,155,"\0").str_repeat("\0",12);
        $sum=array_sum(unpack('C*',$h));return substr_replace($h,sprintf('%06o',$sum)."\0 ",148,8);
    }
    private function persist(array $s,int $owner): void { $this->app->store->put('export',$s['export_id'],$s,$owner,(int)($s['expires']??time()+30*DAY_IN_SECONDS)); }
    private function append(array &$s,string $bytes): void {
        if ($bytes==='') { return; }
        if (($s['bytes']??0)+strlen($bytes)>Support::QUOTAS['export_bytes']) throw new Failure('export_quota','This export exceeds the supported download size.',413);
        $this->app->vault()->put($s['key'].':'.$s['chunks'],$bytes);$s['chunks']++;$s['bytes']+=strlen($bytes);
    }
    private function plan(array &$job,string $work): array {
        $snapshot=$this->app->content->snapshot([],$job['owner'],$work,true,true);$entries=[];$assets=$snapshot['assets'];
        foreach ($assets as $rel=>&$a) { $entries[]=['name'=>$rel,'bytes'=>$a['bytes'],'source'=>$a['_source']];unset($a['_source']); }unset($a);
        $revisions=[];foreach ($snapshot['items'] as $p) { foreach (wp_get_post_revisions($p['id']) as $r) { $revisions[]=['post_id'=>$p['id'],'title'=>$r->post_title,'content'=>$r->post_content,'excerpt'=>$r->post_excerpt,'date_gmt'=>$r->post_date_gmt]; } }
        $id=$job['export_id']??wp_generate_uuid4();$job['export_id']=$id;
        $documents=['content.json'=>['items'=>$snapshot['items'],'terms'=>$snapshot['terms'],'media'=>$snapshot['media'],'revisions'=>$revisions],'design.json'=>$snapshot['design'],'manifest.json'=>['contract_version'=>1,'frontend_contract'=>1,'site_id'=>$this->app->siteId(),'generation'=>$snapshot['generation'],'settings'=>$snapshot['settings'],'assets'=>$assets,'runtime_sha256'=>$this->app->runtime->digest()]];
        foreach ($documents as $name=>$data) { $bytes=wp_json_encode($data);$key='export-document:'.$id.':'.$name;$this->app->vault()->put($key,$bytes);$entries[]=['name'=>$name,'bytes'=>strlen($bytes),'source'=>['document'=>$key]]; }
        $root=$this->app->runtime->root();
        foreach (['template','package.json','package-lock.json','build.mjs','sources.lock.json'] as $rel) {
            $path=$root.'/'.$rel;if ($rel==='template' && !is_file($path.'/package.json') && wp_get_environment_type()==='local') { $path=dirname(__DIR__,2).'/templates/astro'; }if (!file_exists($path)) { continue; }
            $files=is_dir($path)?new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS)):[new \SplFileInfo($path)];
            foreach ($files as $f) { if ($f->isFile()&&!$f->isLink()) { $suffix=is_dir($path)?$rel.'/'.substr($f->getPathname(),strlen($path)+1):$rel;if (preg_match('~(?:^|/)(?:node_modules|dist|\.astro|\.dashless-cache)(?:/|$)|(?:^|/)\.env~',$suffix)) { continue; }$entries[]=['name'=>'source/'.$suffix,'bytes'=>$f->getSize(),'source'=>['path'=>$f->getPathname(),'mtime'=>$f->getMTime()]]; } }
        }
        $this->app->vault()->put('export-plan:'.$id,wp_json_encode($entries));
        return ['export_id'=>$id,'key'=>'export:'.$id,'complete'=>false,'expires'=>time()+30*DAY_IN_SECONDS,'format'=>'tar','generation'=>$snapshot['generation'],'design_hash'=>$snapshot['design_hash'],'chunks'=>0,'bytes'=>0,'entry'=>0,'offset'=>0,'header_written'=>false,'total_entries'=>count($entries)];
    }
    public function step(array &$job,string $work,float $deadline): bool {
        $this->app->active(true);$s=isset($job['export_id'])?($this->app->store->get('export',$job['export_id'])['data']??null):null;
        if (!$s) { $s=$this->plan($job,$work);$this->persist($s,$job['owner']); }
        if ($s['complete']) { $job['saved']=true;return true; }
        if ($s['generation']!==$this->app->content->generation() || $s['design_hash']!==Support::hash($this->app->content->design())) { throw new Failure('export_snapshot_changed','Content changed during export. Request a new export to avoid mixed versions.'); }
        $entries=json_decode($this->app->vault()->get('export-plan:'.$s['export_id']),true);
        // A stable checkpoint can survive CLI termination. Replayed chunks overwrite only uncommitted bytes.
        $budget=wp_get_environment_type()==='local'?max(1,(int)apply_filters('dashless_hosted_export_chunk_budget',PHP_INT_MAX)):PHP_INT_MAX;$written=0;
        while ($s['entry']<count($entries) && microtime(true)<$deadline-10 && $written<$budget) {
            $e=$entries[$s['entry']];$src=$e['source'];$left=$e['bytes']-$s['offset'];
            if (isset($src['path'])) {
                clearstatcache(true,$src['path']);
                if (!is_file($src['path']) || is_link($src['path']) || filesize($src['path'])!==$e['bytes'] || filemtime($src['path'])!==$src['mtime']) { throw new Failure('export_asset_changed','A media file changed during export. Request a new export.'); }
            }
            if (!$s['header_written']) { $this->append($s,$this->header($e['name'],$e['bytes']));$s['header_written']=true; }
            if ($left>0) {
                if (isset($src['key'])) { $bytes=$this->app->vault()->get($src['key'].':'.intdiv($s['offset'],1024*1024)); }
                elseif (isset($src['document'])) { $bytes=substr($this->app->vault()->get($src['document']),$s['offset'],min($left,1024*1024)); }
                else { $h=fopen($src['path'],'rb');if (!$h) { throw new Failure('export_source_missing','An export source is unavailable.'); }try { fseek($h,$s['offset']);$bytes=fread($h,min($left,1024*1024)); }finally { fclose($h); } }
                if (!$bytes || strlen($bytes)>$left) { throw new Failure('export_source_invalid','An export source changed or became unreadable.'); }
                $this->append($s,$bytes);$s['offset']+=strlen($bytes);$written++;
            }
            if ($s['offset']===$e['bytes']) { $this->append($s,str_repeat("\0",(512-$e['bytes']%512)%512));$s['entry']++;$s['offset']=0;$s['header_written']=false; }
            $this->persist($s,$job['owner']);
        }
        $job['progress']=['entries_completed'=>$s['entry'],'entries_total'=>count($entries),'archive_bytes'=>$s['bytes']];
        if ($s['entry']<count($entries)) { return false; }
        if ($s['generation']!==$this->app->content->generation() || $s['design_hash']!==Support::hash($this->app->content->design())) { throw new Failure('export_snapshot_changed','Content changed during export. Request a new export.'); }
        $this->append($s,str_repeat("\0",1024));$s['complete']=true;$this->app->vault()->put($s['key'].':meta',wp_json_encode(['chunks'=>$s['chunks'],'bytes'=>$s['bytes']]));$this->persist($s,$job['owner']);$job['saved']=true;return true;
    }
}
