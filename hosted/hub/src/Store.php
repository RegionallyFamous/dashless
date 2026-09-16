<?php
namespace Dashless\Hub;

/** Durable documents and expiring database leases. Never use transients for correctness. */
final class Store {
    public function table(): string { global $wpdb; return $wpdb->prefix . 'dashless_hub'; }
    public function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table(); $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            record_key varchar(191) NOT NULL,
            kind varchar(32) NOT NULL,
            owner bigint unsigned NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            expires bigint NOT NULL DEFAULT 0,
            updated bigint NOT NULL DEFAULT 0,
            PRIMARY KEY  (record_key),
            KEY kind_owner (kind,owner),
            KEY kind_status (kind,status),
            KEY expires (expires)
        ) $charset;");
        update_option('dashless_hub_schema', 1, false);
    }
    private function key(string $kind, string $id): string { return $kind . ':' . hash('sha256', $id); }
    public function get(string $kind, string $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE record_key=%s", $this->key($kind,$id)), ARRAY_A);
        return $row ? $this->decode($row) : null;
    }
    private function decode(array $row): array {
        return ['id'=>$row['record_key'], 'owner'=>(int)$row['owner'], 'status'=>$row['status'], 'expires'=>(int)$row['expires'], 'updated'=>(int)$row['updated'], 'data'=>json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR)];
    }
    public function add(string $kind, string $id, array $data, int $owner=0, string $status='', int $expires=0): bool {
        global $wpdb;
        $prior = $wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->table(),['record_key'=>$this->key($kind,$id),'kind'=>$kind,'owner'=>$owner,'status'=>$status,'payload'=>wp_json_encode($data),'expires'=>$expires,'updated'=>time()]);
        $wpdb->suppress_errors($prior);
        if ($ok === false && !$this->get($kind,$id)) throw new Failure('storage_failed','The operation could not be saved.',503);
        return $ok !== false;
    }
    public function put(string $kind, string $id, array $data, int $owner=0, string $status='', int $expires=0): void {
        global $wpdb;
        if (!$this->get($kind,$id)) { if ($this->add($kind,$id,$data,$owner,$status,$expires)) return; }
        if ($wpdb->update($this->table(),['payload'=>wp_json_encode($data),'owner'=>$owner,'status'=>$status,'expires'=>$expires,'updated'=>time()],['record_key'=>$this->key($kind,$id)]) === false) throw new Failure('storage_failed','The operation could not be saved.',503);
    }
    public function remove(string $kind,string $id): void { global $wpdb; $wpdb->delete($this->table(),['record_key'=>$this->key($kind,$id)]); }
    public function rows(string $kind, ?int $owner=null, int $limit=100, int $offset=0, ?string $status=null): array {
        global $wpdb;
        $sql="SELECT * FROM {$this->table()} WHERE kind=%s"; $args=[$kind];
        if ($owner !== null) {$sql.=' AND owner=%d';$args[]=$owner;}
        if ($status!==null) {$sql.=' AND status=%s';$args[]=$status;}
        $sql.=' ORDER BY record_key ASC LIMIT %d OFFSET %d';$args[]=min(500,$limit);$args[]=max(0,$offset);
        return array_map(fn($row)=>$this->decode($row),$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A));
    }
    public function consume(string $kind,string $id): ?array {
        global $wpdb;
        $row=$this->get($kind,$id);
        if (!$row || $row['status']!=='unused' || $row['expires']<=time()) return null;
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$this->table()} SET status='used', updated=%d WHERE record_key=%s AND status='unused' AND expires>%d",time(),$this->key($kind,$id),time()));
        return $changed===1 ? $row : null;
    }
    public function lock(string $id,int $ttl=120): ?string {
        global $wpdb;
        $token=bin2hex(random_bytes(24)); $now=time();
        if ($this->add('lock',$id,['token'=>$token],0,'locked',$now+$ttl)) return $token;
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$this->table()} SET payload=%s,expires=%d,updated=%d WHERE record_key=%s AND expires<%d",wp_json_encode(['token'=>$token]),$now+$ttl,$now,$this->key('lock',$id),$now));
        return $changed===1 ? $token : null;
    }
    public function unlock(string $id,string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE record_key=%s AND payload=%s",$this->key('lock',$id),wp_json_encode(['token'=>$token])));
    }
    public function locked(string $id, callable $callback, int $ttl=120): mixed {
        $token=$this->lock($id,$ttl);
        if (!$token) throw new Failure('operation_busy','An operation is already in progress. Please try again shortly.',409);
        try { return $callback(); } finally { $this->unlock($id,$token); }
    }
    public function rate(string $key,int $maximum,int $window): void {
        $bucket=$key.':'.intdiv(time(),$window);
        $this->locked('rate:'.$bucket,function()use($bucket,$maximum,$window){
            $count=(int)($this->get('rate',$bucket)['data']['count']??0);
            if ($count >= $maximum) throw new Failure('rate_limited','Please wait before trying again.',429);
            $this->put('rate',$bucket,['count'=>$count+1],0,'',time()+$window*2);
        });
    }
    public function audit(int $owner,string $event,array $details=[]): void {
        $this->add('audit',wp_generate_uuid4(),['event'=>$event,'details'=>$details],$owner,'',time()+90*DAY_IN_SECONDS);
    }
    public function prune(): void {
        global $wpdb;
        // Accounts, jobs, billing events, reservations and OAuth credentials need explicit lifecycle handling.
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE kind IN ('magic','rate','audit','consent','lock','oauth_access','oauth_code','oauth_refresh','notice') AND expires>0 AND expires<%d",time()));
    }
}
