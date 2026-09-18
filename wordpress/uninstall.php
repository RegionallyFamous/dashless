<?php
/**
 * Remove Dashless options when the normally installed WordPress plugin is deleted.
 * Static releases are deliberately retained for recovery and manual cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dashless_wpcloud_release' );
delete_option( 'dashless_content_version' );
delete_option( 'dashless_receiver_list' );
delete_option( 'dashless_webmentions' );
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'dashless_hosted_cleanup' );
}
