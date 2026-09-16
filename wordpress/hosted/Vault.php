<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

/** Only authenticated ciphertext is stored under the HTTP document root. Keys stay in the database. */
final class Vault {
    private string $key;
    public function __construct() {
        $encoded=get_option('dashless_hosted_vault_key','');
        if (!$encoded) { add_option('dashless_hosted_vault_key',base64_encode(random_bytes(32)),'',false);$encoded=get_option('dashless_hosted_vault_key'); }
        $this->key=(string)base64_decode($encoded,true);
        if (strlen($this->key)!==32) { throw new Failure('vault_unavailable','Private storage needs operator recovery.',503); }
    }
    public function root(): string { return wp_upload_dir(null,false)['basedir'].'/dashless-hosted-vault'; }
    private function file(string $key): string { return $this->root().'/'.hash('sha256',$key).'.blob'; }
    public function put(string $key,string $bytes): void {
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($bytes,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag,$key);
        if ($cipher===false) { throw new Failure('vault_failed','Private storage could not encrypt this artifact.',503); }
        Support::write($this->file($key),$iv.$tag.$cipher);
    }
    public function get(string $key): string {
        $file=$this->file($key);
        if (!is_file($file) || is_link($file)) { throw new Failure('artifact_missing','The requested artifact was not found.',404); }
        $blob=file_get_contents($file);
        $data=strlen($blob)>=28?openssl_decrypt(substr($blob,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($blob,0,12),substr($blob,12,16),$key):false;
        if ($data===false) { throw new Failure('artifact_invalid','The private artifact failed integrity verification.',503); }
        return $data;
    }
    public function putFile(string $key,string $file): array {
        $in=fopen($file,'rb');if (!$in) { throw new Failure('artifact_missing','The source artifact is missing.',503); }
        $hash=hash_init('sha256');$i=0;$size=0;
        try { while (!feof($in)) { $part=fread($in,1024*1024);if ($part===false) { throw new Failure('storage_failed','Could not read the artifact.',503); } if ($part==='') { break; }hash_update($hash,$part);$size+=strlen($part);$this->put($key.':'.$i++,$part); } }
        finally { fclose($in); }
        $meta=['chunks'=>$i,'bytes'=>$size,'sha256'=>hash_final($hash)];$this->put($key.':meta',wp_json_encode($meta));return $meta;
    }
    public function materialize(string $key,string $file): array {
        $meta=json_decode($this->get($key.':meta'),true);$h=fopen($file,'wb');if (!$h) { throw new Failure('storage_failed','Private workspace is unavailable.',503); }chmod($file,0600);
        try { for ($i=0;$i<$meta['chunks'];$i++) { $part=$this->get($key.':'.$i);if (fwrite($h,$part)!==strlen($part)) { throw new Failure('storage_failed','Private workspace is full.',503); } } }
        finally { fclose($h); }
        if (!hash_equals($meta['sha256'],(string)hash_file('sha256',$file))) { throw new Failure('artifact_invalid','The private artifact failed integrity verification.',503); }
        return $meta;
    }
    public function stream(string $key): void {
        $meta=json_decode($this->get($key.':meta'),true);
        for ($i=0;$i<$meta['chunks'];$i++) { echo $this->get($key.':'.$i); }
    }
}
