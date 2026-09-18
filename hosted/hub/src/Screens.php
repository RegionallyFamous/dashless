<?php
namespace Dashless\Hub;
final class Screens {
    public function __construct(private App $app) {}
    public function register(): void {
        add_filter('show_admin_bar',fn($show)=>current_user_can('manage_options')?$show:false);
        add_action('admin_init',function(){
            if(is_user_logged_in() && !current_user_can('manage_options') && !wp_doing_ajax() && ($GLOBALS['pagenow']??'')!=='admin-post.php') {wp_safe_redirect(home_url('/#account'),303);exit;}
        });
        add_action('init',fn()=>register_block_type('dashless/launch-note',['api_version'=>3,'render_callback'=>fn()=>do_shortcode('[dashless_launch_note]')]));
        add_shortcode('dashless_preview',fn()=>(new Previews($this->app))->screen());
        add_shortcode('dashless_account',fn()=> $this->account());
        add_action('init',function(){
            register_block_type('dashless/login-button',['api_version'=>3,'render_callback'=>fn()=> $this->loginButton()]);
            register_block_type('dashless/home-account',['api_version'=>3,'render_callback'=>fn()=> '<div id="account" class="dl-home-account">'.(Identity::verified(get_current_user_id())?$this->account():$this->signin()).'</div>']);
            register_block_type('dashless/home-help',['api_version'=>3,'render_callback'=>fn()=> $this->homeHelp()]);
        });
        add_shortcode('dashless_signin',fn()=> $this->signin());
        add_shortcode('dashless_launch_note',fn()=>Config::checkoutAllowed()?'':'<p class="dl-launch-note"><strong>Opening soon.</strong> Create your account now. No payment required today.</p>');
        add_shortcode('dashless_support',fn()=> $this->support());
        add_action('wp_enqueue_scripts',function(){
            wp_enqueue_style('dashless-hub',plugins_url('assets/hub.css',dirname(__DIR__).'/dashless-hub.php'),[],(string)filemtime(dirname(__DIR__).'/assets/hub.css'));
            wp_enqueue_script('dashless-hub',plugins_url('assets/hub.js',dirname(__DIR__).'/dashless-hub.php'),[],(string)filemtime(dirname(__DIR__).'/assets/hub.js'),true);
            wp_add_inline_script('dashless-hub','window.dashlessHub='.wp_json_encode(['api'=>rest_url('dashless-hub/v1/'),'nonce'=>wp_create_nonce('wp_rest')]).';','before');
        });
        add_action('template_redirect',function(){
            if(is_page(['account','sign-in','support'])) {
                $target=home_url('/');
                if(is_page('sign-in') && isset($_GET['return']))$target=add_query_arg('return',Identity::returnPath((string)wp_unslash($_GET['return'])),$target);
                wp_safe_redirect($target.(is_page('support')?'#support':'#account'),302);exit;
            }
        });
        add_action('template_redirect',function(){if(is_front_page() || is_page(['account','sign-in','preview'])){nocache_headers();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');}},-100);
    }
    public static function publicAccount(array $a): array {
        return ['state'=>$a['state'],'step'=>$a['step']??null,'slug'=>$a['slug']??null,'site_url'=>isset($a['public_domain'])?'https://'.$a['public_domain']:(isset($a['domain'])?'https://'.$a['domain']:null),
            'custom_domain'=>['status'=>$a['custom_domain_status']??'none','domain'=>$a['custom_domain']??null],
            'entitlement'=>$a['entitlement']??'none','subscription_status'=>$a['subscription_status']??null,'paid_through'=>$a['paid_through']??null,'delete_after'=>$a['delete_after']??null,
            'cancel_at_period_end'=>$a['cancel_at_period_end']??false,'message'=>$a['last_error']['message']??null,
            'chatgpt_url'=>$a['state']==='ready'?(string)Config::get('chatgpt_url'):null,
            'release'=>['id'=>$a['release_id']??null,'published_at'=>$a['ready_at']??null,'content_generation'=>isset($a['phase_state']['verify']['remote']['content_generation'])?(int)$a['phase_state']['verify']['remote']['content_generation']:null,'verified'=>$a['state']==='ready' && !empty($a['release_id'])],
            'checklist'=>['address'=>!empty($a['domain']),'publication'=>!empty($a['release_id']),'custom_domain'=>($a['custom_domain_status']??'none')==='ready']];
    }
    public function loginButton(): string {
        if(Identity::verified(get_current_user_id()))return '<a class="dl-button" href="'.esc_url(home_url('/#account')).'">Your account →</a>';
        $return=Identity::returnPath((string)wp_unslash($_GET['return']??'/#account'));
        $url=Config::origin().'/auth/login?'.http_build_query(['return'=>$return]);
        if (!Identity::configured()) {
            return '<a class="dl-signin-button" href="'.esc_url($url).'"><span>Continue with email</span></a><p class="dl-login-explainer" role="status">Sign-in setup is finishing. You can still start here.</p>';
        }
        return '<a class="dl-signin-button" href="'.esc_url($url).'"><span>Continue with email</span></a><p class="dl-login-explainer">Secure email sign-in. No WordPress account needed.</p>';
    }
    public function homeHelp(): string {
        $html='<section class="dl-home-policies" aria-label="Support and policies"><details id="support"><summary>Support</summary>'.$this->support().'</details>';
        foreach(['privacy'=>'Privacy','terms'=>'Terms'] as $slug=>$label){
            $page=get_page_by_path($slug);
            if($page)$html.='<details id="'.$slug.'"><summary>'.esc_html($label).'</summary>'.apply_filters('the_content',$page->post_content).'</details>';
        }
        return $html.'</section>';
    }
    public function signin(): string {
        if(is_user_logged_in() && Identity::verified(get_current_user_id()))return '<p>You’re signed in.</p><a class="dl-button" href="'.esc_url(home_url('/#account')).'">Open your account →</a>';
        $control=$this->loginButton();
        return '<section class="dl-panel dl-signin"><div class="dl-signin-cover" aria-hidden="true"><span class="dl-signin-mark">↗</span><p>YOUR<br>PLACE<br>TO WRITE.</p><span class="dl-signin-scribble">same curious humans.<br>different stories.</span></div><div class="dl-signin-content"><p class="dl-kicker">A little less admin</p><h2>Your words are waiting.</h2><p>Sign in once and keep your blog, writing, and ideas together.</p>'.$control.'<p class="dl-fine">By creating an account, you agree to our <a href="'.esc_url(home_url('/#terms')).'">terms</a> and <a href="'.esc_url(home_url('/#privacy')).'">privacy notice</a>.</p></div></section>';
    }
    public function account(): string {
        if(!is_user_logged_in() || !Identity::verified(get_current_user_id()))return '<section class="dl-panel"><h2>Your corner of the web.</h2><p>Sign in to set up your blog, connect ChatGPT, or manage your subscription.</p><a class="dl-button" href="'.esc_url(home_url('/sign-in/')).'">Sign in →</a></section>';
        $owner=get_current_user_id();$a=$this->app->identity->account($owner);$status=self::publicAccount($a);$activity=[];
        foreach($this->app->store->rows('audit',$owner,24) as $entry){$event=(string)($entry['data']['event']??'');$label=['signed_in_auth0'=>'Signed in securely','site_ready'=>'Blog verified and ready','custom_domain_connected'=>'Custom domain connected','custom_domain_removed'=>'Custom domain removed','chatgpt_disconnected'=>'ChatGPT connection disconnected','auth0_linked_by_operator'=>'Account connection repaired'][$event]??null;if($label)$activity[]=['label'=>$label,'time'=>(int)$entry['updated']];}
        usort($activity,fn($left,$right)=>$right['time']<=>$left['time']);$activity=array_slice($activity,0,4);
        ob_start(); ?>
        <section class="dl-panel" data-dl-account data-state="<?php echo esc_attr($a['state']); ?>"><div class="dl-account-heading"><p class="dl-kicker">Your Dashless account</p><a href="<?php echo esc_url(Identity::logoutUrl()); ?>">Sign out</a></div>
        <h2><?php echo !empty($a['public_domain'])?esc_html($a['public_domain']):(!empty($a['domain'])?esc_html($a['domain']):'Give your blog a home.'); ?></h2>
        <?php if(in_array($a['state'],['new','checkout'],true)): ?>
            <p>One blog. Your own address. <strong>$9.99 USD / month.</strong></p>
            <form data-dl-form="checkout"><label for="dl-slug">Choose your address</label><div class="dl-address"><input id="dl-slug" name="slug" value="<?php echo esc_attr($a['slug']??''); ?>" pattern="[a-z][a-z0-9-]{1,38}[a-z0-9]" minlength="3" maxlength="40" required autocomplete="off" placeholder="your-name"><span>.<?php echo esc_html(Config::domain()); ?></span></div>
            <p class="dl-fine">3–40 characters. Letters, numbers, and single hyphens.</p><button class="dl-button" type="submit" <?php disabled(!Config::checkoutAllowed()); ?>><?php echo Config::checkoutAllowed()?'Continue to secure checkout →':'Subscriptions open soon'; ?></button><p class="dl-form-status" role="status"></p></form>
            <?php if(!Config::checkoutAllowed()): ?><p>We’re completing the final checks. You haven’t been charged.</p><?php endif; ?>
        <?php else: ?>
            <p class="dl-status" data-dl-status role="status"><?php echo esc_html(self::statusText($status)); ?></p>
            <?php if($a['state']==='ready'): ?>
                <div class="dl-account-grid"><div class="dl-account-card"><p class="dl-card-label">Your blog</p><h3><?php echo esc_html($a['public_domain']??$a['domain']); ?></h3><p class="dl-fine">Your Dashless address is always kept as a backup.</p><a class="dl-button" href="<?php echo esc_url('https://'.($a['public_domain']??$a['domain'])); ?>" target="_blank" rel="noopener">Visit your blog ↗</a></div><div class="dl-account-card"><p class="dl-card-label">Your plan</p><h3>$9.99 <span class="dl-card-unit">/ month</span></h3><p class="dl-fine"><?php echo !empty($a['cancel_at_period_end'])?'Ends '.esc_html(gmdate('F j, Y',(int)($a['paid_through']??time()))):'Your subscription is active.'; ?></p><?php if(!empty($a['stripe_customer'])): ?><button class="dl-button dl-secondary" data-dl-action="billing/portal">Manage billing ↗</button><?php endif; ?></div></div>
                <section class="dl-account-release" aria-labelledby="dl-release-title"><div><p class="dl-card-label">Publication health</p><h3 id="dl-release-title"><span class="dl-health-dot" aria-hidden="true"></span> Live and verified</h3><p class="dl-fine">Your latest release passed its public check and is serving readers.</p></div><dl><div><dt>Release</dt><dd><?php echo esc_html(substr((string)($a['release_id']??'—'),0,18)); ?></dd></div><div><dt>Published</dt><dd><?php echo !empty($a['ready_at'])?esc_html(gmdate('M j, Y',(int)$a['ready_at'])):'—'; ?></dd></div></dl></section>
                <section class="dl-account-checklist" aria-labelledby="dl-checklist-title"><div class="dl-account-tools"><div><p class="dl-card-label">Your launch checklist</p><h3 id="dl-checklist-title">The important bits are in place.</h3></div><a class="dl-button dl-secondary" href="<?php echo esc_url(home_url('/themes/')); ?>">Explore themes ↗</a></div><ul><li class="<?php echo !empty($a['domain'])?'is-done':''; ?>"><span aria-hidden="true"><?php echo !empty($a['domain'])?'✓':'○'; ?></span>Your Dashless address</li><li class="<?php echo !empty($a['release_id'])?'is-done':''; ?>"><span aria-hidden="true"><?php echo !empty($a['release_id'])?'✓':'○'; ?></span>First publication verified</li><li class="<?php echo ($a['custom_domain_status']??'none')==='ready'?'is-done':''; ?>"><span aria-hidden="true"><?php echo ($a['custom_domain_status']??'none')==='ready'?'✓':'○'; ?></span>Custom domain (optional)</li></ul></section>
                <?php if($activity): ?><section class="dl-account-activity" aria-labelledby="dl-activity-title"><div class="dl-account-tools"><div><p class="dl-card-label">A quiet record</p><h3 id="dl-activity-title">What’s happened lately.</h3></div><p class="dl-fine">Only you can see this.</p></div><ul><?php foreach($activity as $item): ?><li><span><?php echo esc_html($item['label']); ?></span><time datetime="<?php echo esc_attr(gmdate('c',$item['time'])); ?>"><?php echo esc_html(gmdate('M j, Y',$item['time'])); ?></time></li><?php endforeach; ?></ul></section><?php endif; ?>
                <div class="dl-actions">
                <?php if(Config::get('chatgpt_url')): ?><a class="dl-button dl-secondary" href="<?php echo esc_url(Config::get('chatgpt_url')); ?>" target="_blank" rel="noopener">Connect ChatGPT ↗</a><?php else: ?><span class="dl-fine">The ChatGPT connection is awaiting release.</span><?php endif; ?></div>
                <p class="dl-account-note">Writing, design, and publishing happen in ChatGPT. Your account is where you manage the practical bits.</p>
                <?php $customStatus=(string)($a['custom_domain_status']??'none'); $customLabel=['pending_dns'=>'Waiting for DNS verification.','pending_https'=>'DNS verified. HTTPS is being prepared.','ready'=>'Your custom domain is live.'][$customStatus]??''; ?><div class="dl-domain-box" data-dl-domain data-dl-domain-state="<?php echo esc_attr($customStatus); ?>"><div class="dl-domain-heading"><div><p class="dl-card-label">Your domain</p><h3><?php echo $customStatus==='ready' && !empty($a['public_domain'])?esc_html($a['public_domain']):'Bring your own domain'; ?></h3></div><?php if($customStatus!=='none'): ?><span class="dl-domain-status dl-domain-status-<?php echo esc_attr($customStatus); ?>"><?php echo esc_html($customLabel); ?></span><?php endif; ?></div><p class="dl-fine">Keep your Dashless address as a backup while you connect a domain you own.</p><form data-dl-form="domain"><label for="dl-domain">Domain you own</label><input id="dl-domain" name="domain" value="<?php echo esc_attr($a['custom_domain']??''); ?>" placeholder="example.com" inputmode="url" autocomplete="off" required><button class="dl-button" type="submit"><?php echo $customStatus==='none'?'Connect domain →':'Update DNS instructions'; ?></button></form><p class="dl-form-status" data-dl-domain-status role="status"><?php echo esc_html($customLabel); ?></p><?php if(in_array($customStatus,['pending_dns','pending_https'],true)): ?><button class="dl-button dl-secondary dl-domain-verify" type="button" data-dl-domain-verify><?php echo $customStatus==='pending_dns'?'Verify DNS and connect':'Check HTTPS again'; ?></button><?php endif; ?><?php if(!empty($a['custom_domain'])): ?><button class="dl-link" data-dl-action="domain/remove" data-dl-confirm="Remove this custom domain? Your Dashless address will remain available.">Remove custom domain</button><?php endif; ?></div>
            <?php elseif($a['state']==='suspended'): ?><p>Your blog is offline. You can recover or export it until <?php echo esc_html(gmdate('F j, Y',$a['delete_after'])); ?>.</p><button class="dl-button" data-dl-action="recover">Recover my blog →</button>
            <?php else: ?><p>We’ll keep your setup progress here. You can safely close this page and return.</p><ol class="dl-setup-steps"><li class="is-done"><span>1</span><div><strong>Account created</strong><small>Your sign-in is secure and ready.</small></div></li><li class="<?php echo $a['state']==='provisioning'?'is-current':''; ?>"><span>2</span><div><strong>Blog being prepared</strong><small>We’re setting up your private publishing space.</small></div></li><li><span>3</span><div><strong>Connect ChatGPT</strong><small>Write, design, and publish from one place.</small></div></li></ol><button class="dl-button dl-secondary" data-dl-action="progress">Check setup progress</button><?php endif; ?>
        <?php endif; ?>
        <?php if(!empty($a['stripe_customer'])): ?><hr><div class="dl-account-tools"><div><p class="dl-card-label">Account tools</p><p class="dl-fine">Take your writing with you or manage the connection to ChatGPT.</p></div><div class="dl-actions"><?php if($a['state']!=='ready'): ?><button class="dl-button dl-secondary" data-dl-action="billing/portal">Manage billing ↗</button><?php endif; ?><?php if(!empty($a['site_id']) && $a['state']!=='deleted'): ?><button class="dl-button dl-secondary" data-dl-action="export">Export my blog</button><button class="dl-link" data-dl-action="disconnect" data-dl-confirm="Disconnect ChatGPT from this blog? You can reconnect it later.">Disconnect ChatGPT</button><?php endif; ?></div></div><?php endif; ?>
        <?php if(!empty($a['cancel_at_period_end'])): ?><p>Your subscription ends on <?php echo esc_html(gmdate('F j, Y',$a['paid_through'])); ?>. Your blog stays available until then.</p><?php endif; ?>
        <p class="dl-form-status" data-dl-global-status role="status" aria-live="polite"></p>
        </section>
        <?php return ob_get_clean();
    }
    public static function statusText(array $s): string {
        return match($s['state']) {'ready'=>'Your blog is ready. Make it yours.','provisioning'=>'Setting up your blog…','provision_error'=>'Setup needs attention. Your progress is saved.','suspended'=>'Your blog is currently offline.','deleted'=>'Your blog’s retention period has ended.','refunding','refunded'=>'Your setup could not be completed. Your initial payment is being refunded.',default=>'Your account is ready.'};
    }
    public function support(): string {
        $email=(string)Config::get('support_email');
        return '<section class="dl-panel"><h2>A human, when you need one.</h2><p>For account, billing, or site recovery help, include your blog address. Never send passwords or payment details.</p>'.($email?'<a class="dl-button" href="mailto:'.esc_attr($email).'">Contact support →</a>':'<p>Support contact details will be published before subscriptions open.</p>').'</section>';
    }
    public static function installPages(): void {
        $pages=['preview'=>['Private preview','[dashless_preview]'],'account'=>['Your account','[dashless_account]'],'sign-in'=>['Sign in','[dashless_signin]'],'support'=>['Support','[dashless_support]'],
            'privacy'=>['Privacy',Policies::content('privacy')],
            'terms'=>['Terms',Policies::content('terms')]];
        foreach($pages as $slug=>[$title,$content])if(!get_page_by_path($slug))wp_insert_post(['post_type'=>'page','post_name'=>$slug,'post_title'=>$title,'post_content'=>$content,'post_status'=>'publish']);
        if(defined('WP_CLI')&&WP_CLI)\WP_CLI::success('Hub pages installed. Existing pages preserved.');
    }
}
