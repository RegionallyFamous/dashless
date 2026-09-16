<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

/** Railway executes only a sealed snapshot. Approval and activation remain on this site. */
final class RemoteRuntime {
    public function __construct(private App $app) {}
    public function configured(): bool { return ($this->app->config()['build_driver']??'')==='railway'; }
    public function configure(array $a): array {
        $url=rtrim((string)($a['url']??''),'/');$token=(string)($a['token']??'');
        if (wp_parse_url($url,PHP_URL_SCHEME)!=='https' || wp_parse_url($url,PHP_URL_PATH) || wp_parse_url($url,PHP_URL_QUERY) || wp_parse_url($url,PHP_URL_USER) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new Failure('builder_invalid','A secure builder address and site credential are required.',400);
        $this->app->vault()->put('railway:credential',$token);
        $c=$this->app->config();$c['build_driver']='railway';$c['builder_url']=$url;update_option('dashless_hosted_config',$c,false);
        return ['configured'=>true,'build_driver'=>'railway'];
    }
    private function request(string $method,string $id,string $suffix='',?string $body=null,?string $file=null): array {
        $url=($this->app->config()['builder_url']??'').'/v1/sites/'.$this->app->siteId().'/jobs/'.Support::uuid($id).$suffix;
        $args=['method'=>$method,'timeout'=>20,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.$this->app->vault()->get('railway:credential'),'Content-Type'=>'application/json','Cache-Control'=>'no-store'],'limit_response_size'=>$file!==null?1024*1024*1024:16*1024*1024];
        if ($body!==null) $args['body']=$body;
        if ($file!==null) { $args['stream']=true;$args['filename']=$file; }
        $r=wp_remote_request($url,$args);
        if (is_wp_error($r)) throw new Failure('builder_unavailable','The build service could not be reached. Retry this job.',503);
        $code=wp_remote_retrieve_response_code($r);
        if ($code<200 || $code>=300) throw new Failure('builder_request_failed','The build service could not complete this request (HTTP '.$code.').',503);
        return $file!==null?[]:(json_decode(wp_remote_retrieve_body($r),true)??[]);
    }
    public function run(string $work,float $deadline,callable $progress,string $id): array {
        $raw=(string)file_get_contents($work.'/snapshot.json');$snapshot=json_decode($raw,true);
        $j=$this->request('POST',$id,'',$raw);
        if (!hash_equals(hash('sha256',$raw),(string)($j['input_sha256']??''))) throw new Failure('snapshot_changed','The queued snapshot changed. Create another preview.');
        if (($j['status']??'')==='uploading') {
            foreach ($snapshot['assets'] as $relative=>$asset) {
                if (in_array($relative,$j['uploaded']??[],true)) continue;
                if (microtime(true)>$deadline-25) return ['pending'=>true];
                $relative=Support::relative($relative);$bytes=(string)file_get_contents($work.'/'.$relative);
                if (strlen($bytes)!==$asset['bytes'] || !hash_equals($asset['sha256'],hash('sha256',$bytes))) throw new Failure('snapshot_changed','The snapshot media changed.');
                $this->request('PUT',$id,'/'.$relative,$bytes);
            }
            $j=$this->request('POST',$id,'/start','{}');
        }
        if (in_array($j['status']??'',['queued','running','uploading'],true)) return ['pending'=>true];
        if (($j['status']??'')!=='succeeded') throw new Failure('build_failed','The build did not finish. Your saved work and previous public release are safe.');
        $result=$this->request('GET',$id,'/result');
        if (($result['quality']['passed']??false)!==true || ($result['frontend_contract']??0)!==1 || !is_array($result['files']??null)) throw new Failure('manifest_invalid','The build result failed validation.');
        $archive=$result['archive']??[];
        if (!is_int($archive['bytes']??null) || $archive['bytes']<1 || $archive['bytes']>1024*1024*1024 || !preg_match('/^[a-f0-9]{64}$/D',$archive['sha256']??'')) throw new Failure('manifest_invalid','The build archive is invalid.');
        $this->request('GET',$id,'/archive',null,$work.'/output.zip');
        if (filesize($work.'/output.zip')!==$archive['bytes'] || !hash_equals($archive['sha256'],(string)hash_file('sha256',$work.'/output.zip'))) throw new Failure('manifest_invalid','The build archive failed integrity verification.');
        $zip=new \ZipArchive();if ($zip->open($work.'/output.zip')!==true) throw new Failure('manifest_invalid','The build archive could not be read.');
        $total=0;$seen=[];
        try {
        foreach ($result['files'] as $entry) {
            $relative=Support::relative($entry['path']);$total+=$entry['bytes'];
            if (isset($seen[$relative]) || !is_int($entry['bytes']) || $entry['bytes']<0 || $entry['bytes']>64*1024*1024 || $total>1024*1024*1024 || !preg_match('/^[a-f0-9]{64}$/D',$entry['sha256'])) throw new Failure('manifest_invalid','The build manifest is invalid.');
            $seen[$relative]=true;
            if (microtime(true)>$deadline-25) throw new Failure('build_transfer_deadline','The build completed but transfer exceeded its time limit. Contact support.');
            $file=$work.'/source/dist/'.$relative;wp_mkdir_p(dirname($file));
            $stat=$zip->statName($relative);if (!$stat || $stat['size']!==$entry['bytes']) throw new Failure('manifest_invalid','An archive entry is invalid.');
            $input=$zip->getStream($relative);$output=fopen($file,'xb');
            if (!$input || !$output) throw new Failure('manifest_invalid','An archive entry could not be read.');
            try { if (stream_copy_to_stream($input,$output)!==$entry['bytes']) throw new Failure('manifest_invalid','An archive entry was incomplete.'); } finally { fclose($input);fclose($output); }
            if (filesize($file)!==$entry['bytes'] || !hash_equals($entry['sha256'],(string)hash_file('sha256',$file))) throw new Failure('manifest_invalid','A returned build file failed integrity verification.');
        }
        } finally { $zip->close(); }
        $progress(['build_driver'=>'railway']);return $result;
    }
}
