<?php
namespace Dashless\Hub;
final class Screens {
    public function __construct(private App $app) {}
    public function register(): void {
        add_filter('the_content',fn($content)=>is_page('privacy')?$content.'<p>WordPress.com handles account sign-in. Dashless receives your WordPress.com user ID, verified email address and display name to identify your account. WordPress.com access tokens are used during sign-in and are not stored by Dashless.</p>':$content);
        add_filter('show_admin_bar',fn($show)=>current_user_can('manage_options')?$show:false);
        add_action('admin_init',function(){
            if(is_user_logged_in() && !current_user_can('manage_options') && !wp_doing_ajax() && ($GLOBALS['pagenow']??'')!=='admin-post.php') {wp_safe_redirect(home_url('/account/'),303);exit;}
        });
        add_action('init',fn()=>register_block_type('dashless/launch-note',['api_version'=>3,'render_callback'=>fn()=>do_shortcode('[dashless_launch_note]')]));
        add_shortcode('dashless_preview',fn()=>(new Previews($this->app))->screen());
        add_shortcode('dashless_account',fn()=> $this->account());
        add_shortcode('dashless_signin',fn()=> $this->signin());
        add_shortcode('dashless_launch_note',fn()=>Config::checkoutAllowed()?'':'<p class="dl-launch-note"><strong>Opening soon.</strong> Create your account now. No payment required today.</p>');
        add_shortcode('dashless_support',fn()=> $this->support());
        add_action('wp_enqueue_scripts',function(){
            wp_enqueue_style('dashless-hub',plugins_url('assets/hub.css',dirname(__DIR__).'/dashless-hub.php'),[],(string)filemtime(dirname(__DIR__).'/assets/hub.css'));
            wp_enqueue_script('dashless-hub',plugins_url('assets/hub.js',dirname(__DIR__).'/dashless-hub.php'),[],'0.1.0',true);
            wp_add_inline_script('dashless-hub','window.dashlessHub='.wp_json_encode(['api'=>rest_url('dashless-hub/v1/'),'nonce'=>wp_create_nonce('wp_rest')]).';','before');
        });
        add_action('template_redirect',function(){if(is_page(['account','sign-in','preview'])){nocache_headers();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');}},-100);
    }
    public static function publicAccount(array $a): array {
        return ['state'=>$a['state'],'step'=>$a['step']??null,'slug'=>$a['slug']??null,'site_url'=>isset($a['domain'])?'https://'.$a['domain']:null,
            'entitlement'=>$a['entitlement']??'none','paid_through'=>$a['paid_through']??null,'delete_after'=>$a['delete_after']??null,
            'cancel_at_period_end'=>$a['cancel_at_period_end']??false,'message'=>$a['last_error']['message']??null,
            'chatgpt_url'=>$a['state']==='ready'?(string)Config::get('chatgpt_url'):null];
    }
    public function signin(): string {
        if(is_user_logged_in() && Identity::verified(get_current_user_id()))return '<p>You’re signed in.</p><a class="dl-button" href="'.esc_url(home_url('/account/')).'">Open your account →</a>';
        $return=Identity::returnPath((string)wp_unslash($_GET['return']??'/account/'));
        $url=Config::origin().'/auth/wordpress/start?'.http_build_query(['return'=>$return]);
        $control=Identity::configured()?'<a class="dl-wpcom-login" href="'.esc_url($url).'"><img src="'.esc_url(plugins_url('assets/wordpress-logo-white.svg',dirname(__DIR__).'/dashless-hub.php')).'" width="26" height="26" alt="" aria-hidden="true"><span>Continue with WordPress.com</span></a>':'<p role="status">WordPress.com sign-in is being connected. Please check back soon.</p>';
        return '<section class="dl-panel dl-signin"><p class="dl-kicker">A little less admin</p><h2>Your words are waiting.</h2><p>Use your WordPress.com account to sign in or create your Dashless account. New to WordPress.com? You can create an account there.</p>'.$control.'<p class="dl-fine">By creating an account, you agree to our <a href="'.esc_url(home_url('/terms/')).'">terms</a> and <a href="'.esc_url(home_url('/privacy/')).'">privacy notice</a>.</p></section>';
    }
    public function account(): string {
        if(!is_user_logged_in() || !Identity::verified(get_current_user_id()))return '<section class="dl-panel"><h2>Your corner of the web.</h2><p>Sign in to set up your blog, connect ChatGPT, or manage your subscription.</p><a class="dl-button" href="'.esc_url(home_url('/sign-in/')).'">Sign in →</a></section>';
        $a=$this->app->identity->account(get_current_user_id());$status=self::publicAccount($a);
        ob_start(); ?>
        <section class="dl-panel" data-dl-account data-state="<?php echo esc_attr($a['state']); ?>"><div class="dl-account-heading"><p class="dl-kicker">Your Dashless account</p><a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Sign out</a></div>
        <h2><?php echo !empty($a['domain'])?esc_html($a['domain']):'Give your blog a home.'; ?></h2>
        <?php if(in_array($a['state'],['new','checkout'],true)): ?>
            <p>One blog. Your own address. <strong>$9.99 USD / month.</strong></p>
            <form data-dl-form="checkout"><label for="dl-slug">Choose your address</label><div class="dl-address"><input id="dl-slug" name="slug" value="<?php echo esc_attr($a['slug']??''); ?>" pattern="[a-z][a-z0-9-]{1,38}[a-z0-9]" minlength="3" maxlength="40" required autocomplete="off" placeholder="your-name"><span>.<?php echo esc_html(Config::domain()); ?></span></div>
            <p class="dl-fine">3–40 characters. Letters, numbers, and single hyphens.</p><button class="dl-button" type="submit" <?php disabled(!Config::checkoutAllowed()); ?>><?php echo Config::checkoutAllowed()?'Continue to secure checkout →':'Subscriptions open soon'; ?></button><p class="dl-form-status" role="status"></p></form>
            <?php if(!Config::checkoutAllowed()): ?><p>We’re completing the final checks. You haven’t been charged.</p><?php endif; ?>
        <?php else: ?>
            <p class="dl-status" data-dl-status role="status"><?php echo esc_html(self::statusText($status)); ?></p>
            <?php if($a['state']==='ready'): ?>
                <div class="dl-actions"><a class="dl-button" href="<?php echo esc_url('https://'.$a['domain']); ?>" target="_blank" rel="noopener">Visit your blog ↗</a>
                <?php if(Config::get('chatgpt_url')): ?><a class="dl-button dl-secondary" href="<?php echo esc_url(Config::get('chatgpt_url')); ?>" target="_blank" rel="noopener">Connect ChatGPT ↗</a><?php else: ?><span class="dl-fine">The ChatGPT connection is awaiting release.</span><?php endif; ?></div>
                <p>Writing, design, and publishing happen in ChatGPT. This page is just for your account.</p>
            <?php elseif($a['state']==='suspended'): ?><p>Your blog is offline. You can recover or export it until <?php echo esc_html(gmdate('F j, Y',$a['delete_after'])); ?>.</p><button class="dl-button" data-dl-action="recover">Recover my blog →</button>
            <?php else: ?><p>We’ll keep your setup progress here. You can safely close this page and return.</p><button class="dl-button dl-secondary" data-dl-action="progress">Check setup progress</button><?php endif; ?>
        <?php endif; ?>
        <?php if(!empty($a['stripe_customer'])): ?><hr><div class="dl-actions"><button class="dl-button dl-secondary" data-dl-action="billing/portal">Manage billing ↗</button><?php if(!empty($a['site_id']) && $a['state']!=='deleted'): ?><button class="dl-button dl-secondary" data-dl-action="export">Export my blog</button><button class="dl-link" data-dl-action="disconnect">Disconnect ChatGPT</button><?php endif; ?></div><?php endif; ?>
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
    public static function consent(array $query): void {
        nocache_headers();header('Referrer-Policy: no-referrer');header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connect ChatGPT · Dashless</title><link rel="stylesheet" href="<?php echo esc_url(plugins_url('assets/hub.css',dirname(__DIR__).'/dashless-hub.php')); ?>"></head><body class="dl-consent"><main class="dl-panel"><p class="dl-kicker">Dashless + ChatGPT</p><h1>Your blog. Your permission.</h1><p>Allow ChatGPT to work with your Dashless blog using these permissions:</p><ul><?php foreach(explode(' ',(string)($query['scope']??'')) as $scope): ?><li><?php echo esc_html(match($scope){'blog:read'=>'Read your content and account status.','blog:write'=>'Save drafts, manage media, and prepare designs and previews.','blog:publish'=>'Publish or roll back only after your recorded approval.',default=>'Unknown permission'}); ?></li><?php endforeach; ?></ul><p>You can disconnect ChatGPT from your account at any time.</p><form method="post"><?php wp_nonce_field('dashless_consent'); ?><button class="dl-button" name="decision" value="allow">Allow connection →</button><button class="dl-button dl-secondary" name="decision" value="deny">Cancel</button></form></main></body></html><?php
    }
    public static function installPages(): void {
        $pages=['preview'=>['Private preview','[dashless_preview]'],'account'=>['Your account','[dashless_account]'],'sign-in'=>['Sign in','[dashless_signin]'],'support'=>['Support','[dashless_support]'],
            'privacy'=>['Privacy','<!-- wp:paragraph --><p>Dashless stores your account, subscription references, WordPress content, and operational records on WP Cloud. Railway temporarily processes copies of content and media to build your site. Build workspaces are removed after 24 hours; final previews and releases are stored on WP Cloud. Stripe processes payments. ChatGPT receives the content needed for the actions you request. Draft previews require authorization.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>This notice is a release draft. Publisher contact details, retention practices, and the final privacy notice must be approved before paid signup opens.</p><!-- /wp:paragraph -->'],
            'terms'=>['Terms','<!-- wp:paragraph --><p>Dashless provides one hosted blog for US$9.99 per month. ChatGPT access is separate. Cancel through your account’s billing portal; service continues through the paid period, followed by 30 days of recovery/export access.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>These terms are a release draft. Publisher identity, acceptable use, refund terms, and applicable tax details must be finalized before paid signup opens.</p><!-- /wp:paragraph -->']];
        foreach($pages as $slug=>[$title,$content])if(!get_page_by_path($slug))wp_insert_post(['post_type'=>'page','post_name'=>$slug,'post_title'=>$title,'post_content'=>$content,'post_status'=>'publish']);
        if(defined('WP_CLI')&&WP_CLI)\WP_CLI::success('Hub pages installed. Existing pages preserved.');
    }
}
