<?php
namespace Dashless\Hub;
final class Admin {
    public function __construct(private App $app) {}
    public function register(): void {
        add_action('admin_menu',fn()=>add_menu_page('Dashless Hub','Dashless Hub','manage_options','dashless-hub',[$this,'page'],'dashicons-cloud'));
        add_action('admin_post_dashless_hub_operator',[$this,'action']);
    }
    public function action(): void {
        if(!current_user_can('manage_options'))wp_die('Not allowed.',403);
        check_admin_referer('dashless_hub_operator');
        try {
            $action=(string)($_POST['operation']??'');
            if($action==='pause')update_option('dashless_hub_signup_paused',!empty($_POST['paused']),false);
            elseif($action==='pages')Screens::installPages();
            elseif($action==='reconcile')$this->app->kick();
            elseif($action==='gates'){
                $gates=[];
                foreach(Config::GATES as $gate){$evidence=sanitize_textarea_field(wp_unslash($_POST['evidence'][$gate]??''));$gates[$gate]=['passed'=>!empty($_POST['passed'][$gate])&&$evidence!=='','evidence'=>$evidence,'by'=>get_current_user_id(),'at'=>time()];}
                update_option('dashless_hub_gates',$gates,false);
            } elseif($action==='rollout_create') {
                (new Rollouts($this->app))->create(preg_split('/[\s,]+/',trim((string)($_POST['owners']??''))));
            } elseif($action==='rollout_advance') {
                (new Rollouts($this->app))->advance(sanitize_text_field($_POST['rollout_id']??''));
            } elseif($action==='retry_event') {
                $id=sanitize_text_field($_POST['event_id']??'');$row=$this->app->store->get('stripe_event',$id);
                if(!$row || $row['status']!=='failed')throw new Failure('event_missing','No failed billing event to retry.',404);
                $data=$row['data'];$data['attempts']=0;$this->app->store->put('stripe_event',$id,$data,0,'pending');$this->app->kick();
            } elseif($action==='retry') {
                $owner=absint($_POST['owner']??0);$this->app->identity->account($owner);
                $this->app->maintenance->advance($owner);
            } elseif($action==='rotate') {
                $owner=absint($_POST['owner']??0);
                $this->app->store->locked('account:'.$owner,function()use($owner){
                    $a=$this->app->identity->account($owner);
                    if(empty($a['site_id']))throw new Failure('site_missing','Site is not provisioned.');
                    if(empty($a['pending_secret'])){$a['pending_secret']=Crypto::seal(bin2hex(random_bytes(32)));$this->app->identity->save($a);}
                    if(empty($a['rotation_task'])){$a['rotation_task']=$this->app->cloud->task($a['site_id'],['dashless','rotate-credential','--credential-hash='.hash('sha256',Crypto::open($a['pending_secret']))]);$this->app->identity->save($a);return;}
                    $task=$this->app->cloud->taskStatus($a['rotation_task']);
                    if(empty($task['complete']))throw new Failure('rotation_pending','Rotation is still running.',409);
                    $candidate=$a;$candidate['site_secret']=$a['pending_secret'];$this->app->agent->call($candidate,'GET','/health');
                    $a['site_secret']=$a['pending_secret'];unset($a['pending_secret'],$a['rotation_task']);$this->app->identity->save($a);
                });
            } else throw new Failure('unknown_action','Unknown operator action.');
            $this->app->store->audit(get_current_user_id(),'operator_'.$action);
            wp_safe_redirect(admin_url('admin.php?page=dashless-hub&saved=1'),303);exit;
        }catch(Failure $e){wp_die(esc_html($e->getMessage()),'Dashless Hub',['response'=>$e->status]);}
    }
    private function formStart(string $operation): void {echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dashless_hub_operator"><input type="hidden" name="operation" value="'.esc_attr($operation).'">';wp_nonce_field('dashless_hub_operator');}
    public function page(): void {
        if(!current_user_can('manage_options'))return;
        $gates=get_option('dashless_hub_gates',[]);
        echo '<div class="wrap"><h1>Dashless Hub</h1><p>Checkout: <strong>'.(Config::checkoutAllowed()?'enabled':'disabled').'</strong>. Never mark a launch gate passed without recorded evidence.</p>';
        echo '<p>Remaining checks: '.esc_html(implode(', ',Config::blockers())).'</p>';
        $this->formStart('pause');echo '<label><input type="checkbox" name="paused" value="1" '.checked(get_option('dashless_hub_signup_paused',false),true,false).'> Pause new signup and checkout</label> ';submit_button('Save', 'secondary','submit',false);echo '</form><hr>';
        foreach(['pages'=>'Install missing account pages','reconcile'=>'Queue reconciliation'] as $op=>$label){$this->formStart($op);submit_button($label,'secondary');echo '</form>';}
        echo '<h2>Launch evidence</h2>';$this->formStart('gates');echo '<table class="widefat"><thead><tr><th>Check</th><th>Passed</th><th>Evidence / date / result</th></tr></thead><tbody>';
        foreach(Config::GATES as $g)echo '<tr><td>'.esc_html($g).'</td><td><input aria-label="'.esc_attr($g).' passed" type="checkbox" name="passed['.esc_attr($g).']" value="1" '.checked(!empty($gates[$g]['passed']),true,false).'></td><td><textarea aria-label="'.esc_attr($g).' evidence" name="evidence['.esc_attr($g).']" rows="2" style="width:100%">'.esc_textarea($gates[$g]['evidence']??'').'</textarea></td></tr>';
        echo '</tbody></table>';submit_button('Save evidence');echo '</form><h2>Accounts and provisioning</h2><table class="widefat"><thead><tr><th>Owner</th><th>Blog</th><th>State / step</th><th>Access</th><th>Last issue</th><th>Actions</th></tr></thead><tbody>';
        $offset=max(0,(int)($_GET['offset']??0));
        foreach($this->app->store->rows('account',null,50,$offset) as $r){$a=$r['data'];echo '<tr><td>'.(int)$r['owner'].'</td><td>'.esc_html($a['domain']??'—').'</td><td>'.esc_html($a['state'].' / '.($a['step']??'—')).'</td><td>'.esc_html($a['entitlement']??'none').'</td><td>'.esc_html($a['last_error']['message']??'—').'</td><td>';
            foreach(['retry'=>'Reconcile','rotate'=>'Rotate / verify credential'] as $op=>$label){$this->formStart($op);echo '<input type="hidden" name="owner" value="'.(int)$r['owner'].'">';submit_button($label,'secondary','submit',false);echo '</form>';}
            echo '</td></tr>';}
        echo '</tbody></table><p><a href="'.esc_url(admin_url('admin.php?page=dashless-hub&offset='.($offset+50))).'">Next accounts →</a></p><h2>Jobs</h2><table class="widefat"><tr><th>Owner</th><th>Job</th><th>Status</th></tr>';
        foreach($this->app->store->rows('job',null,50) as $r)echo '<tr><td>'.(int)$r['owner'].'</td><td>'.esc_html($r['data']['job_id']).'</td><td>'.esc_html($r['status']).'</td></tr>';
        echo '</table><h2>Plugin rollouts</h2><p>Uses the configured, pinned package. First account is the canary. Advance verifies it before touching the next account. All steps are manual and stop on error.</p>';
        $this->formStart('rollout_create');echo '<label>Account IDs (first is canary) <input name="owners" required placeholder="12, 15, 19"></label>';submit_button('Stage rollout','secondary');echo '</form>';
        foreach($this->app->store->rows('rollout',null,20) as $row){echo '<p>'.esc_html($row['data']['rollout_id'].' · '.$row['data']['version'].' · '.$row['status']).'</p>';$this->formStart('rollout_advance');echo '<input type="hidden" name="rollout_id" value="'.esc_attr($row['data']['rollout_id']).'">';submit_button('Advance / verify one site','secondary','submit',false);echo '</form>';}
        echo '<h2>Billing reconciliation failures</h2>';
        foreach($this->app->store->rows('stripe_event',null,50,0,'failed') as $row){echo '<p>'.esc_html($row['data']['event_id'].' · '.($row['data']['last_error']??'Review required')).'</p>';$this->formStart('retry_event');echo '<input type="hidden" name="event_id" value="'.esc_attr($row['data']['event_id']).'">';submit_button('Retry after diagnosis','secondary','submit',false);echo '</form>';}
        echo '<p>Last maintenance: '.esc_html(wp_json_encode(get_option('dashless_hub_last_maintenance',[]))).'</p></div>';
    }
}
