<?php
/** Reset ONLY one of this suite's disposable customer-site databases. */
$root=$argv[1]??'';
if(!in_array($root,['/tmp/dashless-site-test-chatgpt-a','/tmp/dashless-site-test-chatgpt-b'],true))throw new RuntimeException('Not a dedicated ChatGPT fixture.');
require $root.'/wp-load.php';
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local fixture required.');
$app=\Dashless\Site\App::boot();global $wpdb;$wpdb->query('DELETE FROM '.$app->store->table());
$app->store->put('entitlement','current',['active'=>true,'retain_until'=>0]);
foreach(get_posts(['post_type'=>['post','page','attachment'],'post_status'=>'any','posts_per_page'=>-1]) as $post)wp_delete_post($post->ID,true);
global $wp_rewrite;$wp_rewrite->set_permalink_structure('/%postname%/');flush_rewrite_rules(false);
echo "Disposable fixture reset with pretty REST routes.\n";
