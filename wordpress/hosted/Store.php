<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

/** Durable records. InnoDB transactions couple approvals, idempotency and queued jobs. */
final class Store {
    private int $depth=0;
    public function table(): string { global $wpdb; return $wpdb->prefix.'dashless_site'; }
    public function install(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=$this->table();$charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            record_key varchar(191) NOT NULL,
            kind varchar(32) NOT NULL,
            owner bigint unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            expires bigint NOT NULL DEFAULT 0,
            revision bigint NOT NULL DEFAULT 1,
            PRIMARY KEY  (record_key),
            KEY kind_owner (kind,owner),
            KEY kind_expires (kind,expires)
        ) ENGINE=InnoDB $charset;");
        update_option('dashless_hosted_schema',1,false);
    }
    private function key(string $kind,string $id): string { return $kind.':'.hash('sha256',$id); }
    public function get(string $kind,string $id): ?array {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT payload,owner,expires,revision FROM {$this->table()} WHERE record_key=%s",$this->key($kind,$id)),ARRAY_A);
        return $r?['data'=>json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR),'owner'=>(int)$r['owner'],'expires'=>(int)$r['expires'],'revision'=>(int)$r['revision']]:null;
    }
    public function owned(string $kind,string $id,int $owner): array {
        $r=$this->get($kind,Support::uuid($id));
        if (!$r || $r['owner']!==$owner) { throw new Failure('not_found','The requested record was not found.',404); }
        return $r['data'];
    }
    public function add(string $kind,string $id,array $data,int $owner=0,int $expires=0): bool {
        global $wpdb;
        if (!$expires && isset($data['expires']) && is_numeric($data['expires'])) $expires=(int)$data['expires'];
        if ($this->get($kind,$id)) { return false; }
        $prior=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->table(),['record_key'=>$this->key($kind,$id),'kind'=>$kind,'owner'=>$owner,'payload'=>wp_json_encode($data),'expires'=>$expires,'revision'=>1]);
        $wpdb->suppress_errors($prior);
        if ($ok===false && !$this->get($kind,$id)) { throw new Failure('storage_failed','The operation could not be saved.',503); }
        return $ok!==false;
    }
    public function put(string $kind,string $id,array $data,int $owner=0,int $expires=0): void {
        global $wpdb;
        if (!$expires && isset($data['expires']) && is_numeric($data['expires'])) $expires=(int)$data['expires'];
        if (!$this->get($kind,$id) && $this->add($kind,$id,$data,$owner,$expires)) { return; }
        if ($wpdb->query($wpdb->prepare("UPDATE {$this->table()} SET payload=%s,owner=%d,expires=%d,revision=revision+1 WHERE record_key=%s",wp_json_encode($data),$owner,$expires,$this->key($kind,$id)))===false) { throw new Failure('storage_failed','The operation could not be saved.',503); }
    }
    public function rows(string $kind): array {
        global $wpdb;
        return array_map(fn($r)=>json_decode($r['payload'],true),$wpdb->get_results($wpdb->prepare("SELECT payload FROM {$this->table()} WHERE kind=%s ORDER BY record_key",$kind),ARRAY_A));
    }
    public function count(string $kind,int $owner=0,bool $activeOnly=false): int {
        global $wpdb;
        $sql="SELECT COUNT(*) FROM {$this->table()} WHERE kind=%s";$args=[$kind];
        if($owner>0){$sql.=' AND owner=%d';$args[]=$owner;}
        if($activeOnly){$sql.=' AND (expires=0 OR expires>%d)';$args[]=time();}
        return (int)$wpdb->get_var($wpdb->prepare($sql,...$args));
    }
    public function expired(string $kind,int $now=0,int $limit=100): array {
        global $wpdb; $now=$now?:time(); $limit=max(1,min(500,$limit));
        return array_map(fn($r)=>['id'=>$r['record_key'],'data'=>json_decode($r['payload'],true),'owner'=>(int)$r['owner']],$wpdb->get_results($wpdb->prepare("SELECT record_key,payload,owner FROM {$this->table()} WHERE kind=%s AND expires>0 AND expires<=%d ORDER BY expires ASC LIMIT %d",$kind,$now,$limit),ARRAY_A));
    }
    public function deleteKey(string $kind,string $id): void {
        global $wpdb; $wpdb->delete($this->table(),['record_key'=>$this->key($kind,$id)]);
    }
    public function deleteRecord(string $recordKey): void { global $wpdb; $wpdb->delete($this->table(),['record_key'=>$recordKey]); }
    public function lock(string $id,int $ttl): ?string {
        global $wpdb;
        $token=bin2hex(random_bytes(24));$payload=['token'=>$token];$now=time();
        if ($this->add('lease',$id,$payload,0,$now+$ttl)) { return $token; }
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$this->table()} SET payload=%s,expires=%d,revision=revision+1 WHERE record_key=%s AND expires<%d",wp_json_encode($payload),$now+$ttl,$this->key('lease',$id),$now));
        return $ok===1?$token:null;
    }
    public function unlock(string $id,string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE record_key=%s AND payload=%s",$this->key('lease',$id),wp_json_encode(['token'=>$token])));
    }
    public function transaction(callable $fn): mixed {
        if ($this->depth>0) { return $fn(); }
        $token=$this->lock('editorial',30);
        if (!$token) { throw new Failure('operation_busy','Another operation is being saved. Retry the same request.',409); }
        global $wpdb;
        if ($wpdb->query('START TRANSACTION')===false) { $this->unlock('editorial',$token);throw new Failure('storage_failed','Transactional storage is unavailable.',503); }$this->depth++;
        try { $r=$fn(); if ($wpdb->query('COMMIT')===false) { throw new Failure('storage_failed','The operation could not be committed.',503); } return $r; }
        catch (\Throwable $e) { $wpdb->query('ROLLBACK');wp_cache_flush();throw $e; }
        finally { $this->depth--; $this->unlock('editorial',$token); }
    }
    public function once(int $owner,string $kind,string $key,array $input,callable $fn): array {
        if (strlen($key)<8 || strlen($key)>150) { throw new Failure('invalid_idempotency_key','Use a stable client key of 8 to 150 characters.',400); }
        return $this->transaction(function()use($owner,$kind,$key,$input,$fn){
            $id=$owner.':'.$kind.':'.$key;$hash=Support::hash($input);$r=$this->get('idempotency',$id);
            if ($r) { if (!hash_equals($r['data']['hash'],$hash)) { throw new Failure('idempotency_conflict','This client key was already used for different input.'); } return $r['data']['result']; }
            $result=$fn();$this->put('idempotency',$id,['hash'=>$hash,'result'=>$result],$owner);return $result;
        });
    }
}
