<?php
namespace Dashless\Hub;
final class App {
    public Store $store; public Identity $identity; public Billing $billing; public Cloud $cloud; public Agent $agent; public Provisioner $provisioner; public Jobs $jobs; public OAuth $oauth; public Mcp $mcp; public Maintenance $maintenance;
    private static ?self $instance=null;
    public static function instance(): self { return self::$instance??=new self(); }
    public function __construct() {
        $this->store=new Store();$this->identity=new Identity($this->store);$this->cloud=new Cloud();$this->agent=new Agent();
        $this->billing=new Billing($this->store,$this->identity,new StripeGateway());
        $this->provisioner=new Provisioner($this->store,$this->identity,$this->cloud,$this->agent);
        $this->jobs=new Jobs($this->store,$this->identity,$this->cloud,$this->agent);
        $this->oauth=new OAuth($this->store);$this->mcp=new Mcp($this->store,$this->identity,$this->agent,$this->jobs);
        $this->maintenance=new Maintenance($this->store,$this->identity,$this->billing,$this->provisioner,$this->jobs,$this->cloud,$this->agent);
    }
    public function register(): void {
        (new Routes($this))->register();(new Screens($this))->register();(new Admin($this))->register();
        add_action('dashless_hub_maintenance',function(){$lease=$this->store->lock('maintenance',280);if(!$lease)return;try{$this->maintenance->run();}finally{$this->store->unlock('maintenance',$lease);}});
        if(!wp_next_scheduled('dashless_hub_maintenance'))wp_schedule_event(time()+60,'hourly','dashless_hub_maintenance');
        if(defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('dashless-hub reconcile',function($args,$assoc){
                $lease=$this->store->lock('maintenance',280);if(!$lease){\WP_CLI::log('Reconciliation already running.');return;}
                try {
                    $deadline=microtime(true)+240;
                    do {
                        $result=$this->maintenance->run();\WP_CLI::log(wp_json_encode($result));
                        $pending=$this->maintenance->pending();
                        if(!$pending || isset($assoc['once']))break;
                        if(microtime(true)+80>$deadline)break;
                        sleep(5);
                    }while(true);
                }finally{$this->store->unlock('maintenance',$lease);}
                if(!empty($pending) && !isset($assoc['once']))$this->kick();
            });
            \WP_CLI::add_command('dashless-hub install-pages',fn()=> Screens::installPages());
            \WP_CLI::add_command('dashless-hub health',function(){\WP_CLI::log(wp_json_encode(['version'=>'0.1.0','checkout_enabled'=>Config::checkoutAllowed(),'blockers'=>Config::blockers()]));});
        }
    }
    public function kick(): void {
        // A durable hourly recovery command remains the fallback if immediate dispatch cannot be confirmed.
        if(!Config::get('hub_site_id'))return;
        if(($this->store->get('lock','maintenance')['expires']??0)>time())return;
        $lock=$this->store->lock('dispatch-maintenance',20);if(!$lock)return;
        try {$this->cloud->call('POST','/task-create/'.rawurlencode(Config::required('wpcloud_client')).'/run-wp-cli-command',['args'=>['dashless-hub','reconcile'],'site_run_list'=>[(int)Config::get('hub_site_id')],'cli_mode'=>'full','site_count_limit'=>'1','send_webhook_for'=>'none']);}
        catch(\Throwable $e){$this->store->audit(0,'maintenance_dispatch_failed');}
        // Leave the short lease in place as a debounce, not a permanent lock.
    }
}
