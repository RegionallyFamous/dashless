<?php
/**
 * Plugin Name: Dashless Hub
 * Description: Signup, subscriptions, account authorization, and WP Cloud provisioning for Dashless.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * Text Domain: dashless-hub
 */
if (!defined('ABSPATH')) exit;
if (!is_file(__DIR__.'/vendor/autoload.php')) {
    add_action('admin_notices',function(){echo '<div class="notice notice-error"><p>Dashless Hub needs its packaged Composer dependencies. Install the complete release ZIP.</p></div>';});return;
}
$chatgptIntegration=__DIR__.'/chatgpt/php/Integration.php';
// The release ZIP bundles the adapter beside the Hub. The source checkout
// keeps it in hosted/chatgpt so disposable WordPress fixtures can exercise the
// same integration without manufacturing a package first.
if (!is_file($chatgptIntegration)) $chatgptIntegration=dirname(__DIR__).'/chatgpt/php/Integration.php';
if (!is_file($chatgptIntegration)) {
    add_action('admin_notices',function(){echo '<div class="notice notice-error"><p>Dashless Hub needs the packaged ChatGPT adapter. Install the complete release ZIP.</p></div>';});return;
}
require_once __DIR__.'/vendor/autoload.php';
require_once $chatgptIntegration;
register_activation_hook(__FILE__,function(){(new \Dashless\Hub\Store())->install();});
add_action('plugins_loaded',function(){
    if ((int)get_option('dashless_hub_schema',0)!==1) (new \Dashless\Hub\Store())->install();
    \Dashless\Hub\App::instance()->register();
});
