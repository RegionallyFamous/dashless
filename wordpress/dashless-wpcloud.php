<?php
/**
 * Plugin Name: Dashless
 * Description: Connects WordPress to the local Dashless Codex workflow and serves atomic Astro releases on WP Cloud.
 * Version: 1.0.0
 * Author: Regionally Famous
 * License: GPL-2.0-or-later
 */

// Legacy upgrade markers retained so older bridges can verify this staged successor: Plugin Name: Dashless WP Cloud Bridge. Plugin Name: Dashless for WordPress.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DASHLESS_WPCLOUD_OPTION = 'dashless_wpcloud_release';
const DASHLESS_CONTENT_VERSION_OPTION = 'dashless_content_version';
const DASHLESS_WPCLOUD_BRIDGE_VERSION = '1.0.0';
const DASHLESS_RECEIVER_LIST_OPTION = 'dashless_receiver_list';
const DASHLESS_WEBMENTIONS_OPTION = 'dashless_webmentions';

/**
 * Track a monotonic content generation so Codex can detect WordPress changes
 * made outside the current conversation without receiving a webhook.
 */
function dashless_mark_content_changed( $object_id = 0 ) {
	$object_id = is_numeric( $object_id ) ? (int) $object_id : 0;
	if ( $object_id > 0 && ( ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $object_id ) ) || ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $object_id ) ) ) ) {
		return;
	}
	$current = get_option( DASHLESS_CONTENT_VERSION_OPTION, array() );
	update_option(
		DASHLESS_CONTENT_VERSION_OPTION,
		array(
			'generation'     => max( 0, (int) ( $current['generation'] ?? 0 ) ) + 1,
			'changed_at_gmt' => gmdate( 'c' ),
			'object_id'      => $object_id > 0 ? $object_id : null,
		),
		false
	);
}

foreach ( array( 'save_post_post', 'save_post_page', 'trashed_post', 'untrashed_post', 'before_delete_post', 'add_attachment', 'edit_attachment', 'delete_attachment', 'created_category', 'edited_category', 'delete_category', 'created_post_tag', 'edited_post_tag', 'delete_post_tag' ) as $dashless_content_hook ) {
	add_action( $dashless_content_hook, 'dashless_mark_content_changed', 10, 1 );
}

/**
 * Return the authenticated site contract consumed by local Dashless builds.
 */
function dashless_site_status() {
	$content_version = get_option( DASHLESS_CONTENT_VERSION_OPTION, array() );
	$release         = get_option( DASHLESS_WPCLOUD_OPTION, array() );
	$post_counts     = function_exists( 'wp_count_posts' ) ? wp_count_posts( 'post' ) : null;
	$page_counts     = function_exists( 'wp_count_posts' ) ? wp_count_posts( 'page' ) : null;
	$media_counts    = function_exists( 'wp_count_attachments' ) ? wp_count_attachments() : null;

	return rest_ensure_response(
		array(
			'plugin_version' => DASHLESS_WPCLOUD_BRIDGE_VERSION,
			'content_version' => array(
				'generation'     => (int) ( $content_version['generation'] ?? 0 ),
				'changed_at_gmt' => $content_version['changed_at_gmt'] ?? null,
				'object_id'      => $content_version['object_id'] ?? null,
			),
			'site' => array(
				'name'                => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '',
				'description'         => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'description' ) : '',
				'url'                 => function_exists( 'home_url' ) ? home_url( '/' ) : '',
				'timezone'            => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '',
				'permalink_structure' => get_option( 'permalink_structure', '' ),
				'show_on_front'       => get_option( 'show_on_front', 'posts' ),
				'page_on_front'       => (int) get_option( 'page_on_front', 0 ),
				'page_for_posts'      => (int) get_option( 'page_for_posts', 0 ),
			),
			'content' => array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array( 'category', 'post_tag' ),
				'counts'     => array(
					'posts' => $post_counts ? (int) ( $post_counts->publish ?? 0 ) : null,
					'pages' => $page_counts ? (int) ( $page_counts->publish ?? 0 ) : null,
					'media' => $media_counts ? array_sum( (array) $media_counts ) : null,
				),
			),
			'routes' => array(
				'wordpress_rest' => function_exists( 'rest_url' ) ? rest_url() : '/wp-json/',
				'wordpress_admin' => function_exists( 'admin_url' ) ? admin_url() : '/wp-admin/',
			),
			'release' => array(
				'active'       => ! empty( $release['id'] ),
				'release_id'   => $release['id'] ?? null,
				'public_host'  => $release['public_host'] ?? null,
				'activated_at' => $release['activated_at'] ?? null,
				'content_generation' => isset( $release['content_generation'] ) ? (int) $release['content_generation'] : null,
			),
		)
	);
}

/**
 * Return the uploads-backed directory where Dashless stores immutable releases.
 */
function dashless_wpcloud_releases_directory() {
	$uploads = wp_upload_dir( null, false );
	return trailingslashit( $uploads['basedir'] ) . 'dashless/releases';
}

/**
 * Restrict release activation to users trusted to publish public content.
 */
function dashless_wpcloud_can_activate() {
	return current_user_can( 'publish_posts' );
}

/**
 * Report the currently active release without exposing server paths.
 */
function dashless_wpcloud_release_status() {
	$release = get_option( DASHLESS_WPCLOUD_OPTION, array() );

	return rest_ensure_response(
		array(
			'active'       => ! empty( $release['id'] ),
			'release_id'   => isset( $release['id'] ) ? $release['id'] : null,
			'public_host'  => isset( $release['public_host'] ) ? $release['public_host'] : null,
			'activated_at' => isset( $release['activated_at'] ) ? $release['activated_at'] : null,
			'content_generation' => isset( $release['content_generation'] ) ? (int) $release['content_generation'] : null,
			'previous_release_id' => isset( $release['previous']['id'] ) ? $release['previous']['id'] : null,
			'bridge'       => DASHLESS_WPCLOUD_BRIDGE_VERSION,
			'bridge_sha256' => hash_file( 'sha256', __FILE__ ),
			'content_version' => get_option( DASHLESS_CONTENT_VERSION_OPTION, array() ),
		)
	);
}

/**
 * Tell independent discovery services about the newly activated static tree.
 * These requests are deliberately non-blocking: publishing must never fail
 * because a third-party indexing service is having a bad afternoon.
 */
function dashless_notify_discovery_services( $release_directory, $public_url, $indexnow_key = '' ) {
	if ( ! function_exists( 'wp_remote_post' ) ) {
		return;
	}
	$public_url   = trailingslashit( esc_url_raw( $public_url ) );
	$public_host  = strtolower( (string) wp_parse_url( $public_url, PHP_URL_HOST ) );
	$indexnow_key = sanitize_text_field( (string) $indexnow_key );

	if ( preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $indexnow_key ) ) {
		$sitemap_file = trailingslashit( $release_directory ) . 'sitemap.xml';
		$sitemap      = is_readable( $sitemap_file ) ? (string) file_get_contents( $sitemap_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$url_list     = array();
		if ( preg_match_all( '/<loc>(.*?)<\/loc>/is', $sitemap, $matches ) ) {
			foreach ( array_slice( $matches[1], 0, 10000 ) as $candidate ) {
				$url       = esc_url_raw( html_entity_decode( trim( wp_strip_all_tags( $candidate ) ), ENT_QUOTES, 'UTF-8' ) );
				$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				$url_scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
				if ( $public_host === $url_host && in_array( $url_scheme, array( 'http', 'https' ), true ) ) {
					$url_list[] = $url;
				}
			}
		}
		$url_list = array_values( array_unique( $url_list ) );
		if ( $url_list ) {
			wp_remote_post(
				'https://api.indexnow.org/indexnow',
				array(
					'blocking' => false,
					'timeout'  => 2,
					'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'     => wp_json_encode(
						array(
							'host'        => $public_host,
							'key'         => $indexnow_key,
							'keyLocation' => $public_url . 'indexnow-key.txt',
							'urlList'     => $url_list,
						)
					),
				)
			);
		}
	}

	foreach ( array( 'rss.xml', 'broadcast.xml' ) as $feed ) {
		wp_remote_post(
			'https://pubsubhubbub.appspot.com/',
			array(
				'blocking' => false,
				'timeout'  => 2,
				'body'     => array(
					'hub.mode' => 'publish',
					'hub.url'  => $public_url . $feed,
				),
			)
		);
	}
}

/**
 * Atomically select a fully uploaded release. The old release stays active if
 * validation fails at any point.
 */
function dashless_wpcloud_activate_release( WP_REST_Request $request ) {
	$release_id = sanitize_text_field( (string) $request->get_param( 'release_id' ) );
	$public_url = esc_url_raw( (string) $request->get_param( 'public_url' ) );
	$generation_param = $request->get_param( 'content_generation' );
	$content_generation = null;
	if ( null !== $generation_param && '' !== $generation_param ) {
		$content_generation = filter_var( $generation_param, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $content_generation ) {
			return new WP_Error( 'dashless_content_generation_invalid', 'The content generation must be a non-negative integer.', array( 'status' => 400 ) );
		}
	}

	if ( ! preg_match( '/^[0-9T]+Z-[a-f0-9]{6}$/', $release_id ) ) {
		return new WP_Error( 'dashless_release_invalid', 'The Dashless release identifier is invalid.', array( 'status' => 400 ) );
	}

	$public_parts = wp_parse_url( $public_url );
	$public_host  = isset( $public_parts['host'] ) ? strtolower( rtrim( (string) $public_parts['host'], '.' ) ) : '';
	$public_path  = isset( $public_parts['path'] ) ? (string) $public_parts['path'] : '';
	if ( 'https' !== ( $public_parts['scheme'] ?? '' ) || '' === $public_host || ! in_array( $public_path, array( '', '/' ), true ) || isset( $public_parts['user'] ) || isset( $public_parts['pass'] ) || isset( $public_parts['query'] ) || isset( $public_parts['fragment'] ) ) {
		return new WP_Error( 'dashless_public_url_invalid', 'The public URL must be a clean HTTPS origin.', array( 'status' => 400 ) );
	}

	$releases_directory = dashless_wpcloud_releases_directory();
	$release_directory  = $releases_directory . '/' . $release_id;
	$index_file         = $release_directory . '/index.html';
	$manifest_file      = $release_directory . '/dashless-release.json';

	if ( ! is_file( $index_file ) || ! is_readable( $index_file ) || ! is_file( $manifest_file ) || ! is_readable( $manifest_file ) ) {
		return new WP_Error( 'dashless_release_incomplete', 'The uploaded release does not contain a readable index and release manifest.', array( 'status' => 409 ) );
	}

	$real_base    = realpath( $releases_directory );
	$real_release = realpath( $release_directory );
	if ( false === $real_base || false === $real_release || 0 !== strpos( $real_release, trailingslashit( $real_base ) ) ) {
		return new WP_Error( 'dashless_release_path_invalid', 'The release is outside the Dashless releases directory.', array( 'status' => 409 ) );
	}

	$manifest = json_decode( (string) file_get_contents( $manifest_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_array( $manifest ) || 1 !== (int) ( $manifest['version'] ?? 0 ) || $release_id !== ( $manifest['release_id'] ?? '' ) || $public_host !== ( $manifest['public_host'] ?? '' ) ) {
		return new WP_Error( 'dashless_manifest_invalid', 'The release manifest does not match this activation request.', array( 'status' => 409 ) );
	}
	$manifest_generation = isset( $manifest['content_generation'] ) && is_int( $manifest['content_generation'] ) && $manifest['content_generation'] >= 0 ? $manifest['content_generation'] : null;
	if ( null !== $content_generation ) {
		if ( null === $manifest_generation || $manifest_generation !== $content_generation ) {
			return new WP_Error(
				'dashless_content_generation_mismatch',
				'The release manifest does not match the requested WordPress content generation.',
				array( 'status' => 409, 'expected_generation' => $content_generation, 'manifest_generation' => $manifest_generation )
			);
		}
	}
	if ( empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) || count( $manifest['files'] ) > 20000 ) {
		return new WP_Error( 'dashless_manifest_invalid', 'The release manifest has an invalid file list.', array( 'status' => 409 ) );
	}

	$required_files = array( 'index.html' => false, '404.html' => false );
	$seen_files     = array();
	foreach ( $manifest['files'] as $entry ) {
		$relative = isset( $entry['path'] ) ? (string) $entry['path'] : '';
		$sha256  = isset( $entry['sha256'] ) ? (string) $entry['sha256'] : '';
		$bytes   = isset( $entry['bytes'] ) ? (int) $entry['bytes'] : -1;
		$segments = explode( '/', $relative );
		if ( '' === $relative || '/' === substr( $relative, 0, 1 ) || false !== strpos( $relative, '\\' ) || preg_match( '/[\x00-\x1F\x7F]/', $relative ) || in_array( '', $segments, true ) || in_array( '.', $segments, true ) || in_array( '..', $segments, true ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || $bytes < 0 || isset( $seen_files[ $relative ] ) ) {
			return new WP_Error( 'dashless_manifest_invalid', 'The release manifest contains an invalid file entry.', array( 'status' => 409 ) );
		}
		$seen_files[ $relative ] = true;
		$file = realpath( $release_directory . '/' . $relative );
		$actual_hash = false !== $file && is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
		if ( false === $file || 0 !== strpos( $file, trailingslashit( $real_release ) ) || ! is_file( $file ) || ! is_readable( $file ) || filesize( $file ) !== $bytes || ! is_string( $actual_hash ) || ! hash_equals( $sha256, $actual_hash ) ) {
			return new WP_Error( 'dashless_release_incomplete', 'At least one release file is missing or failed its integrity check.', array( 'status' => 409, 'file' => $relative ) );
		}
		if ( array_key_exists( $relative, $required_files ) ) {
			$required_files[ $relative ] = true;
		}
	}
	if ( in_array( false, $required_files, true ) ) {
		return new WP_Error( 'dashless_release_incomplete', 'The release manifest is missing a required reader-facing page.', array( 'status' => 409 ) );
	}
	if ( null !== $content_generation ) {
		$current_content    = get_option( DASHLESS_CONTENT_VERSION_OPTION, array() );
		$current_generation = max( 0, (int) ( $current_content['generation'] ?? 0 ) );
		if ( $current_generation !== $content_generation ) {
			return new WP_Error(
				'dashless_content_changed_during_deployment',
				'WordPress changed after this release was built. Build again before publishing.',
				array( 'status' => 409, 'expected_generation' => $content_generation, 'current_generation' => $current_generation )
			);
		}
	}

	$current = get_option( DASHLESS_WPCLOUD_OPTION, array() );
	if ( isset( $current['id'] ) && $release_id === $current['id'] && $public_host === ( $current['public_host'] ?? '' ) ) {
		return rest_ensure_response(
			array(
				'activated'    => true,
				'idempotent'   => true,
				'release_id'   => $release_id,
				'public_host'  => $public_host,
				'activated_at' => $current['activated_at'] ?? null,
				'content_generation' => isset( $current['content_generation'] ) ? (int) $current['content_generation'] : $manifest_generation,
				'previous_release_id' => $current['previous']['id'] ?? null,
			)
		);
	}

	$release = array(
		'id'           => $release_id,
		'public_host'  => $public_host,
		'activated_at' => gmdate( 'c' ),
		'content_generation' => $manifest_generation,
		'previous'     => ! empty( $current['id'] ) ? array(
			'id'           => $current['id'],
			'public_host'  => $current['public_host'] ?? $public_host,
			'activated_at' => $current['activated_at'] ?? null,
			'content_generation' => isset( $current['content_generation'] ) ? (int) $current['content_generation'] : null,
		) : null,
	);

	update_option( DASHLESS_WPCLOUD_OPTION, $release, false );
	$stored = get_option( DASHLESS_WPCLOUD_OPTION, array() );
	if ( ( $stored['id'] ?? '' ) !== $release_id || ( $stored['public_host'] ?? '' ) !== $public_host ) {
		return new WP_Error( 'dashless_activation_not_persisted', 'WordPress could not persist the active Dashless release.', array( 'status' => 500 ) );
	}
	wp_cache_flush();

	do_action( 'dashless_wpcloud_release_activated', $release );
	dashless_notify_discovery_services( $release_directory, $public_url, $manifest['indexnow_key'] ?? '' );

	return rest_ensure_response(
		array(
			'activated'    => true,
			'release_id'   => $release_id,
			'public_host'  => $public_host,
			'activated_at' => $release['activated_at'],
			'content_generation' => $release['content_generation'],
			'previous_release_id' => $release['previous']['id'] ?? null,
		)
	);
}

/**
 * Reactivate the immediately previous verified release.
 */
function dashless_wpcloud_rollback_release( $request = null ) {
	$current  = get_option( DASHLESS_WPCLOUD_OPTION, array() );
	$previous = isset( $current['previous'] ) && is_array( $current['previous'] ) ? $current['previous'] : array();
	$expected_release_id = $request instanceof WP_REST_Request ? sanitize_text_field( (string) $request->get_param( 'expected_release_id' ) ) : '';
	if ( '' !== $expected_release_id && ! preg_match( '/^[0-9T]+Z-[a-f0-9]{6}$/', $expected_release_id ) ) {
		return new WP_Error( 'dashless_rollback_expected_release_invalid', 'The expected active release identifier is invalid.', array( 'status' => 400 ) );
	}
	if ( '' !== $expected_release_id && $expected_release_id !== ( $current['id'] ?? '' ) ) {
		return new WP_Error(
			'dashless_rollback_release_changed',
			'The active release changed before rollback. Nothing was switched.',
			array( 'status' => 409, 'expected_release_id' => $expected_release_id, 'current_release_id' => $current['id'] ?? null )
		);
	}
	if ( empty( $previous['id'] ) || empty( $previous['public_host'] ) ) {
		return new WP_Error( 'dashless_rollback_unavailable', 'There is no previous Dashless release available for rollback.', array( 'status' => 409 ) );
	}

	$request = new WP_REST_Request( 'POST', '/dashless/v1/release/activate' );
	$request->set_param( 'release_id', $previous['id'] );
	$request->set_param( 'public_url', 'https://' . $previous['public_host'] );
	$response = dashless_wpcloud_activate_release( $request );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$data                = $response->get_data();
	$data['rolled_back'] = true;
	$response->set_data( $data );
	return $response;
}

/**
 * Atomically replace this must-use bridge with a fully staged, hash-verified
 * version. The current request continues running the old, known-good code.
 */
function dashless_wpcloud_upgrade_bridge( WP_REST_Request $request ) {
	$staged_name = sanitize_file_name( (string) $request->get_param( 'staged_name' ) );
	$sha256     = strtolower( sanitize_text_field( (string) $request->get_param( 'sha256' ) ) );
	$version    = sanitize_text_field( (string) $request->get_param( 'version' ) );

	if ( ! preg_match( '/^dashless-wpcloud-[0-9T]+Z-[a-f0-9]{6}\.stage$/', $staged_name ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
		return new WP_Error( 'dashless_bridge_upgrade_invalid', 'The staged bridge metadata is invalid.', array( 'status' => 400 ) );
	}

	$staged = trailingslashit( WPMU_PLUGIN_DIR ) . $staged_name;
	if ( ! is_file( $staged ) || ! is_readable( $staged ) || ! hash_equals( $sha256, (string) hash_file( 'sha256', $staged ) ) ) {
		return new WP_Error( 'dashless_bridge_upgrade_incomplete', 'The staged bridge is missing or failed its integrity check.', array( 'status' => 409 ) );
	}

	$source = (string) file_get_contents( $staged ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! preg_match( '/^\s*\*\s*Plugin Name:\s*Dashless\s*$/m', $source ) || ! preg_match( "/DASHLESS_WPCLOUD_BRIDGE_VERSION = '" . preg_quote( $version, '/' ) . "'/", $source ) ) {
		return new WP_Error( 'dashless_bridge_upgrade_invalid', 'The staged file is not the expected Dashless bridge version.', array( 'status' => 409 ) );
	}

	// Dashless runs as an MU plugin on WP Cloud. This authenticated, hash-verified operation replaces only its own bridge file.
	if ( ! rename( $staged, __FILE__ ) ) { // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite,WordPress.WP.AlternativeFunctions.rename_rename
		return new WP_Error( 'dashless_bridge_upgrade_failed', 'WordPress could not atomically install the staged bridge.', array( 'status' => 500 ) );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( __FILE__, true );
	}

	return rest_ensure_response(
		array(
			'upgraded' => true,
			'bridge'   => $version,
		)
	);
}

/**
 * Return the number of characters in a mailbox field.
 */
function dashless_mailbox_string_length( $value ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
}

/**
 * Consume one slot from a short, privacy-preserving mailbox rate-limit bucket.
 */
function dashless_mailbox_consume_rate_limit() {
	$limit  = max( 1, min( 20, (int) apply_filters( 'dashless_mailbox_rate_limit', 5 ) ) );
	$window = max( 60, min( DAY_IN_SECONDS, (int) apply_filters( 'dashless_mailbox_rate_window', 15 * MINUTE_IN_SECONDS ) ) );
	$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ip     = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	$key    = 'dashless_mailbox_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 40 );
	$now    = time();
	$bucket = get_transient( $key );

	if ( ! is_array( $bucket ) || (int) ( $bucket['reset'] ?? 0 ) <= $now ) {
		$bucket = array(
			'count' => 0,
			'reset' => $now + $window,
		);
	}

	if ( (int) $bucket['count'] >= $limit ) {
		return new WP_Error(
			'dashless_mailbox_rate_limited',
			'Too many transmissions from this connection. Please wait a few minutes and try again.',
			array(
				'status'      => 429,
				'retry_after' => max( 1, (int) $bucket['reset'] - $now ),
			)
		);
	}

	$bucket['count']++;
	set_transient( $key, $bucket, max( 1, (int) $bucket['reset'] - $now ) );
	return true;
}

/**
 * Deliver a private reader note without creating a WordPress comment or post.
 */
function dashless_mailbox_deliver( WP_REST_Request $request ) {
	$website = sanitize_text_field( wp_unslash( (string) $request->get_param( 'website' ) ) );
	if ( '' !== $website ) {
		return rest_ensure_response(
			array(
				'delivered' => true,
				'private'   => true,
				'message'   => 'Transmission received. Teddy put it in the good pile.',
			)
		);
	}

	$post_id = absint( $request->get_param( 'post' ) );
	$name    = sanitize_text_field( wp_unslash( (string) $request->get_param( 'author_name' ) ) );
	$email   = sanitize_email( wp_unslash( (string) $request->get_param( 'author_email' ) ) );
	$content = sanitize_textarea_field( wp_unslash( (string) $request->get_param( 'content' ) ) );

	if ( ! $post_id || ! get_post( $post_id ) || 'post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
		return new WP_Error( 'dashless_mailbox_story_invalid', 'That story is not available for private transmissions.', array( 'status' => 400 ) );
	}
	if ( '' === $name || dashless_mailbox_string_length( $name ) > 80 ) {
		return new WP_Error( 'dashless_mailbox_name_invalid', 'Please enter a name no longer than 80 characters.', array( 'status' => 400 ) );
	}
	if ( '' === $email || dashless_mailbox_string_length( $email ) > 160 || ! is_email( $email ) ) {
		return new WP_Error( 'dashless_mailbox_email_invalid', 'Please enter a valid reply email address.', array( 'status' => 400 ) );
	}
	if ( '' === $content || dashless_mailbox_string_length( $content ) > 4000 ) {
		return new WP_Error( 'dashless_mailbox_content_invalid', 'Please write a note no longer than 4,000 characters.', array( 'status' => 400 ) );
	}

	$rate_limit = dashless_mailbox_consume_rate_limit();
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	$recipient = sanitize_email( (string) apply_filters( 'dashless_mailbox_recipient', get_option( 'admin_email', '' ), $post_id ) );
	if ( ! is_email( $recipient ) ) {
		return new WP_Error( 'dashless_mailbox_recipient_invalid', 'The mailbox is not configured yet. Your note has not been sent.', array( 'status' => 503 ) );
	}

	$story_title = sanitize_text_field( wp_strip_all_tags( get_the_title( $post_id ) ) );
	$story_url   = esc_url_raw( get_permalink( $post_id ) );
	$site_name   = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$subject     = sprintf( '[%s Mailbox] %s', $site_name ? $site_name : 'Dashless', $story_title ? $story_title : 'Reader note' );
	$body        = "A private reader transmission arrived. It was not saved as a WordPress comment.\n\n";
	$body       .= "Name: {$name}\nReply email: {$email}\nStory: {$story_title}\nStory URL: {$story_url}\n\nMessage:\n{$content}\n";
	$headers     = array(
		'Content-Type: text/plain; charset=UTF-8',
		"Reply-To: {$name} <{$email}>",
	);

	if ( ! wp_mail( $recipient, $subject, $body, $headers ) ) {
		return new WP_Error( 'dashless_mailbox_delivery_failed', 'The antenna could not deliver your note. Your text is still here—please try again later.', array( 'status' => 503 ) );
	}

	return rest_ensure_response(
		array(
			'delivered' => true,
			'private'   => true,
			'message'   => 'Transmission received. Teddy put it in the good pile.',
		)
	);
}

/**
 * Build one opaque token suitable for an emailed confirmation link.
 */
function dashless_reader_token() {
	return bin2hex( random_bytes( 24 ) );
}

/**
 * Return Teddy's public static story URL for a WordPress post.
 */
function dashless_reader_story_url( $post ) {
	$slug = is_object( $post ) ? ( $post->post_name ?? '' ) : ( is_array( $post ) ? ( $post['post_name'] ?? '' ) : '' );
	return home_url( '/stories/' . rawurlencode( (string) $slug ) . '/' );
}

/**
 * Request double opt-in for Teddy's private, tracker-free Receiver List.
 */
function dashless_receiver_list_request( WP_REST_Request $request ) {
	$website = sanitize_text_field( wp_unslash( (string) $request->get_param( 'website' ) ) );
	if ( '' !== $website ) {
		return rest_ensure_response( array( 'requested' => true, 'message' => 'Check your email to confirm the frequency.' ) );
	}

	$email  = sanitize_email( wp_unslash( (string) $request->get_param( 'email' ) ) );
	$source = sanitize_key( wp_unslash( (string) $request->get_param( 'source' ) ) );
	if ( '' === $email || dashless_mailbox_string_length( $email ) > 160 || ! is_email( $email ) ) {
		return new WP_Error( 'dashless_receiver_email_invalid', 'Please enter a valid email address.', array( 'status' => 400 ) );
	}
	$rate_limit = dashless_mailbox_consume_rate_limit();
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}

	$subscribers = get_option( DASHLESS_RECEIVER_LIST_OPTION, array() );
	$subscribers = is_array( $subscribers ) ? $subscribers : array();
	$pending_cutoff = time() - 7 * DAY_IN_SECONDS;
	foreach ( $subscribers as $subscriber_key => $subscriber ) {
		$requested = is_array( $subscriber ) && ! empty( $subscriber['requested_at_gmt'] ) ? strtotime( $subscriber['requested_at_gmt'] ) : false;
		if ( is_array( $subscriber ) && 'pending' === ( $subscriber['status'] ?? '' ) && false !== $requested && $requested < $pending_cutoff ) {
			unset( $subscribers[ $subscriber_key ] );
		}
	}
	$key         = hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
	$existing    = isset( $subscribers[ $key ] ) && is_array( $subscribers[ $key ] ) ? $subscribers[ $key ] : array();
	if ( 'active' === ( $existing['status'] ?? '' ) ) {
		return rest_ensure_response( array( 'requested' => true, 'message' => 'If that address can join, a confirmation signal is on its way.' ) );
	}

	$confirm_token     = dashless_reader_token();
	$unsubscribe_token = dashless_reader_token();
	$subscribers[ $key ] = array(
		'email'            => $email,
		'status'           => 'pending',
		'source'           => $source ? $source : 'site',
		'confirm_hash'     => hash( 'sha256', $confirm_token ),
		'unsubscribe_hash' => hash( 'sha256', $unsubscribe_token ),
		'unsubscribe_token' => $unsubscribe_token,
		'requested_at_gmt' => gmdate( 'c' ),
		'confirmed_at_gmt' => null,
	);
	update_option( DASHLESS_RECEIVER_LIST_OPTION, $subscribers, false );

	$confirm_url = add_query_arg( array( 'token' => $confirm_token, 'action' => 'confirm' ), rest_url( 'dashless/v1/receiver-list/confirm' ) );
	$subject     = '[Teddy Receiver] Confirm the frequency';
	$body        = "Someone asked Teddy to turn on a receiver for this address.\n\nConfirm: {$confirm_url}\n\nIf that was not you, ignore this message. Nothing will be sent. No tracking pixel is hiding in here.";
	if ( ! wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) {
		unset( $subscribers[ $key ] );
		update_option( DASHLESS_RECEIVER_LIST_OPTION, $subscribers, false );
		return new WP_Error( 'dashless_receiver_delivery_failed', 'The confirmation email could not leave the workshop. Please try again later.', array( 'status' => 503 ) );
	}

	return rest_ensure_response( array( 'requested' => true, 'double_opt_in' => true, 'message' => 'Check your email to confirm the frequency.' ) );
}

/**
 * Confirm or unsubscribe one Receiver List address using an emailed token.
 */
function dashless_receiver_list_confirm( WP_REST_Request $request ) {
	$token       = sanitize_text_field( (string) $request->get_param( 'token' ) );
	$action      = sanitize_key( (string) $request->get_param( 'action' ) );
	$token_hash  = hash( 'sha256', $token );
	$subscribers = get_option( DASHLESS_RECEIVER_LIST_OPTION, array() );
	$subscribers = is_array( $subscribers ) ? $subscribers : array();
	$state       = 'invalid';

	if ( strlen( $token ) >= 32 && in_array( $action, array( 'confirm', 'unsubscribe' ), true ) ) {
		foreach ( $subscribers as $key => $subscriber ) {
			if ( ! is_array( $subscriber ) ) {
				continue;
			}
			$expected = 'confirm' === $action ? ( $subscriber['confirm_hash'] ?? '' ) : ( $subscriber['unsubscribe_hash'] ?? '' );
			if ( '' === $expected || ! hash_equals( $expected, $token_hash ) ) {
				continue;
			}
			$requested = ! empty( $subscriber['requested_at_gmt'] ) ? strtotime( $subscriber['requested_at_gmt'] ) : false;
			if ( 'confirm' === $action && false !== $requested && $requested < time() - 7 * DAY_IN_SECONDS ) {
				unset( $subscribers[ $key ] );
				update_option( DASHLESS_RECEIVER_LIST_OPTION, $subscribers, false );
				$state = 'expired';
				break;
			}
			if ( 'confirm' === $action ) {
				$state = 'active' === ( $subscriber['status'] ?? '' ) ? 'already-confirmed' : 'confirmed';
				$subscribers[ $key ]['status']           = 'active';
				$subscribers[ $key ]['confirmed_at_gmt'] = gmdate( 'c' );
				$subscribers[ $key ]['confirm_hash']     = '';
			} else {
				unset( $subscribers[ $key ] );
				$state = 'unsubscribed';
			}
			update_option( DASHLESS_RECEIVER_LIST_OPTION, $subscribers, false );
			break;
		}
	}

	$response = new WP_REST_Response( null, 302 );
	$response->header( 'Location', home_url( '/receiver/?state=' . rawurlencode( $state ) ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * Send a new published story to confirmed receivers without tracking.
 */
function dashless_receiver_send_story( $post_id ) {
	$post = get_post( absint( $post_id ) );
	if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return;
	}
	$subscribers = get_option( DASHLESS_RECEIVER_LIST_OPTION, array() );
	$story_url   = dashless_reader_story_url( $post );
	$title       = sanitize_text_field( wp_strip_all_tags( get_the_title( $post ) ) );
	$excerpt     = sanitize_text_field( wp_strip_all_tags( get_the_excerpt( $post ) ) );
	foreach ( (array) $subscribers as $subscriber ) {
		if ( ! is_array( $subscriber ) || 'active' !== ( $subscriber['status'] ?? '' ) || ! is_email( $subscriber['email'] ?? '' ) ) {
			continue;
		}
		$unsubscribe_url = add_query_arg( array( 'token' => $subscriber['unsubscribe_token'] ?? '', 'action' => 'unsubscribe' ), rest_url( 'dashless/v1/receiver-list/confirm' ) );
		if ( empty( $subscriber['unsubscribe_token'] ) && ! empty( $subscriber['unsubscribe_hash'] ) ) {
			// Older records without a reversible token receive the story but can reply to leave.
			$unsubscribe_url = 'Reply with UNSUBSCRIBE and Teddy will turn it off.';
		}
		$body = "A new Teddy transmission exists.\n\n{$title}\n{$excerpt}\n\nRead: {$story_url}\n\nReceiver controls: {$unsubscribe_url}\n\nNo tracking pixel. No secret redirect. Just a link.";
		wp_mail( $subscriber['email'], '[Teddy] ' . $title, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}
}

add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status && 'publish' !== $old_status && $post instanceof WP_Post && 'post' === $post->post_type ) {
			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, 'dashless_receiver_send_story', array( (int) $post->ID ) );
		}
	},
	20,
	3
);
add_action( 'dashless_receiver_send_story', 'dashless_receiver_send_story', 10, 1 );

/**
 * Deliver a private, count-free reaction by email.
 */
function dashless_private_reaction_deliver( WP_REST_Request $request ) {
	$post_id  = absint( $request->get_param( 'post' ) );
	$reaction = sanitize_key( (string) $request->get_param( 'reaction' ) );
	$labels   = array( 'hell-yes' => 'HELL YES', 'questions' => 'I HAVE QUESTIONS', 'reminded-me' => 'THIS REMINDED ME OF SOMETHING' );
	if ( ! $post_id || 'post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) || ! isset( $labels[ $reaction ] ) ) {
		return new WP_Error( 'dashless_reaction_invalid', 'That private signal could not be identified.', array( 'status' => 400 ) );
	}
	$rate_limit = dashless_mailbox_consume_rate_limit();
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}
	$recipient = sanitize_email( (string) get_option( 'admin_email', '' ) );
	$title     = sanitize_text_field( wp_strip_all_tags( get_the_title( $post_id ) ) );
	$body      = "A private Teddy reaction arrived. Nothing was stored and no public count exists.\n\nReaction: {$labels[ $reaction ]}\nStory: {$title}\nURL: " . dashless_reader_story_url( get_post( $post_id ) );
	if ( ! is_email( $recipient ) || ! wp_mail( $recipient, '[Teddy Signal] ' . $labels[ $reaction ], $body, array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) {
		return new WP_Error( 'dashless_reaction_delivery_failed', 'The reaction light did not reach the console. Please try again later.', array( 'status' => 503 ) );
	}
	return rest_ensure_response( array( 'delivered' => true, 'private' => true, 'message' => 'Private signal received. One tiny light came on.' ) );
}

/**
 * Accept, verify, and email-moderate one W3C Webmention.
 */
function dashless_webmention_receive( WP_REST_Request $request ) {
	$source = esc_url_raw( (string) $request->get_param( 'source' ) );
	$target = esc_url_raw( (string) $request->get_param( 'target' ) );
	if ( ! wp_http_validate_url( $source ) || ! wp_http_validate_url( $target ) || $source === $target ) {
		return new WP_Error( 'dashless_webmention_url_invalid', 'Source and target must be different public HTTP URLs.', array( 'status' => 400 ) );
	}
	$home_host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$target_host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
	if ( $home_host !== $target_host ) {
		return new WP_Error( 'dashless_webmention_target_invalid', 'That target does not belong to Teddy.', array( 'status' => 400 ) );
	}
	$rate_limit = dashless_mailbox_consume_rate_limit();
	if ( is_wp_error( $rate_limit ) ) {
		return $rate_limit;
	}
	$response = wp_safe_remote_get( $source, array( 'timeout' => 6, 'redirection' => 3, 'limit_response_size' => 524288, 'headers' => array( 'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.8' ), 'user-agent' => 'Teddy Webmention Receiver/1.0' ) );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'dashless_webmention_source_unavailable', 'The source page could not be verified.', array( 'status' => 400 ) );
	}
	$body        = (string) wp_remote_retrieve_body( $response );
	$target_link = strtok( $target, '#' );
	$linked      = false;
	if ( preg_match_all( '/<(?:a|area|link)\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $body, $href_matches ) ) {
		foreach ( $href_matches[2] as $href ) {
			$candidate = strtok( esc_url_raw( html_entity_decode( trim( $href ), ENT_QUOTES, 'UTF-8' ) ), '#' );
			if ( $candidate === $target_link ) {
				$linked = true;
				break;
			}
		}
	}
	if ( ! $linked ) {
		return new WP_Error( 'dashless_webmention_link_missing', 'The source page does not link to the target.', array( 'status' => 400 ) );
	}
	$title = '';
	if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $match ) ) {
		$title = sanitize_text_field( wp_strip_all_tags( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) ) );
	}
	$token    = dashless_reader_token();
	$mentions = get_option( DASHLESS_WEBMENTIONS_OPTION, array() );
	$mentions = is_array( $mentions ) ? $mentions : array();
	$key      = hash( 'sha256', $source . "\n" . $target );
	if ( 'approved' === ( $mentions[ $key ]['status'] ?? '' ) ) {
		return new WP_REST_Response( array( 'received' => true, 'verified' => true, 'moderated' => true ), 202 );
	}
	$mentions[ $key ] = array( 'source' => $source, 'target' => strtok( $target, '#' ), 'title' => $title, 'status' => 'pending', 'moderation_hash' => hash( 'sha256', $token ), 'received_at_gmt' => gmdate( 'c' ) );
	update_option( DASHLESS_WEBMENTIONS_OPTION, $mentions, false );
	$approve = add_query_arg( array( 'token' => $token, 'action' => 'approve' ), rest_url( 'dashless/v1/webmention/moderate' ) );
	$delete  = add_query_arg( array( 'token' => $token, 'action' => 'delete' ), rest_url( 'dashless/v1/webmention/moderate' ) );
	$admin   = sanitize_email( (string) get_option( 'admin_email', '' ) );
	if ( is_email( $admin ) ) {
		wp_mail( $admin, '[Teddy Webmention] ' . ( $title ? $title : wp_parse_url( $source, PHP_URL_HOST ) ), "Verified source: {$source}\nTarget: {$target}\n\nApprove: {$approve}\nDelete: {$delete}", array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}
	return new WP_REST_Response( array( 'received' => true, 'verified' => true, 'moderated' => true ), 202 );
}

function dashless_webmention_moderate( WP_REST_Request $request ) {
	$token       = sanitize_text_field( (string) $request->get_param( 'token' ) );
	$action      = sanitize_key( (string) $request->get_param( 'action' ) );
	$mentions    = get_option( DASHLESS_WEBMENTIONS_OPTION, array() );
	$mentions    = is_array( $mentions ) ? $mentions : array();
	$token_hash  = hash( 'sha256', $token );
	$state       = 'invalid';
	foreach ( $mentions as $key => $mention ) {
		if ( ! is_array( $mention ) || empty( $mention['moderation_hash'] ) || ! hash_equals( $mention['moderation_hash'], $token_hash ) ) {
			continue;
		}
		if ( 'approve' === $action ) {
			$mentions[ $key ]['status']          = 'approved';
			$mentions[ $key ]['moderation_hash'] = '';
			$state = 'approved';
		} elseif ( 'delete' === $action ) {
			unset( $mentions[ $key ] );
			$state = 'deleted';
		}
		update_option( DASHLESS_WEBMENTIONS_OPTION, $mentions, false );
		break;
	}
	$response = new WP_REST_Response( null, 302 );
	$response->header( 'Location', home_url( '/?webmention=' . rawurlencode( $state ) ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

function dashless_webmention_list( WP_REST_Request $request ) {
	$target   = strtok( esc_url_raw( (string) $request->get_param( 'target' ) ), '#' );
	$mentions = get_option( DASHLESS_WEBMENTIONS_OPTION, array() );
	$output   = array();
	foreach ( (array) $mentions as $mention ) {
		if ( ! is_array( $mention ) || 'approved' !== ( $mention['status'] ?? '' ) || $target !== ( $mention['target'] ?? '' ) ) {
			continue;
		}
		$output[] = array( 'source' => $mention['source'], 'title' => $mention['title'], 'domain' => wp_parse_url( $mention['source'], PHP_URL_HOST ) );
	}
	return rest_ensure_response( array( 'mentions' => $output ) );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'dashless/v1',
			'/site',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'dashless_site_status',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'dashless/v1',
			'/mailbox',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'dashless_mailbox_deliver',
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'dashless/v1',
			'/receiver-list',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'dashless_receiver_list_request',
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'dashless/v1',
			'/receiver-list/confirm',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'dashless_receiver_list_confirm',
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'dashless/v1',
			'/reaction',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'dashless_private_reaction_deliver',
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'dashless/v1',
			'/webmention',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => 'dashless_webmention_receive',
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => 'dashless_webmention_list',
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			'dashless/v1',
			'/webmention/moderate',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'dashless_webmention_moderate',
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'dashless/v1',
			'/release',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'dashless_wpcloud_release_status',
				'permission_callback' => 'dashless_wpcloud_can_activate',
			)
		);

		register_rest_route(
			'dashless/v1',
			'/release/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'dashless_wpcloud_activate_release',
				'permission_callback' => 'dashless_wpcloud_can_activate',
				'args'                => array(
					'release_id' => array( 'required' => true, 'type' => 'string' ),
					'public_url' => array( 'required' => true, 'type' => 'string', 'format' => 'uri' ),
					'content_generation' => array( 'required' => false, 'type' => 'integer', 'minimum' => 0 ),
				),
			)
		);

		register_rest_route(
			'dashless/v1',
			'/release/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'dashless_wpcloud_rollback_release',
				'permission_callback' => 'dashless_wpcloud_can_activate',
				'args'                => array(
					'expected_release_id' => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			'dashless/v1',
			'/bridge/upgrade',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'dashless_wpcloud_upgrade_bridge',
				'permission_callback' => 'dashless_wpcloud_can_activate',
				'args'                => array(
					'staged_name' => array( 'required' => true, 'type' => 'string' ),
					'sha256'      => array( 'required' => true, 'type' => 'string' ),
					'version'     => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}
);

/**
 * Requests that must continue to reach WordPress even when WordPress and the
 * reader-facing Astro site share a hostname.
 */
function dashless_wpcloud_is_wordpress_request( $path ) {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return true;
	}

	if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return true;
	}

	$prefixes = array(
		'/wp-admin',
		'/wp-json',
		'/wp-login.php',
		'/wp-cron.php',
		'/wp-comments-post.php',
		'/xmlrpc.php',
		'/wp-content/',
		'/wp-includes/',
		'/wp-sitemap',
		'/.well-known/',
	);

	foreach ( $prefixes as $prefix ) {
		if ( 0 === strpos( $path, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Consolidate WordPress core sitemap URLs onto the static reader sitemap.
 */
function dashless_wpcloud_sitemap_redirect_target( $path ) {
	return preg_match( '/^\/wp-sitemap[^\/]*\.xml$/', (string) $path ) ? '/sitemap.xml' : false;
}

/**
 * Resolve a public URL to one file inside the active immutable release.
 */
function dashless_wpcloud_resolve_file( $release_directory, $request_path ) {
	$decoded = rawurldecode( $request_path );
	if ( false !== strpos( $decoded, "\0" ) || false !== strpos( $decoded, '..' ) || false !== strpos( $decoded, '\\' ) ) {
		return false;
	}

	$relative   = ltrim( $decoded, '/' );
	$candidates = array();
	if ( '' === $relative ) {
		$candidates[] = 'index.html';
	} elseif ( '/' === substr( $decoded, -1 ) ) {
		$candidates[] = trailingslashit( $relative ) . 'index.html';
	} else {
		$candidates[] = $relative;
		$candidates[] = trailingslashit( $relative ) . 'index.html';
		$candidates[] = $relative . '.html';
	}

	$real_release = realpath( $release_directory );
	if ( false === $real_release ) {
		return false;
	}

	foreach ( $candidates as $candidate ) {
		$file = realpath( $release_directory . '/' . $candidate );
		$allowed_extensions = array( 'avif', 'css', 'gif', 'html', 'ico', 'jpeg', 'jpg', 'js', 'json', 'map', 'mjs', 'mp3', 'otf', 'pdf', 'png', 'svg', 'ttf', 'txt', 'webmanifest', 'webp', 'woff', 'woff2', 'xml' );
		$extension          = false !== $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
		if ( false !== $file && in_array( $extension, $allowed_extensions, true ) && is_file( $file ) && is_readable( $file ) && 0 === strpos( $file, trailingslashit( $real_release ) ) ) {
			return $file;
		}
	}

	return false;
}

/**
 * Stream an immutable file with cache validators suitable for WP Cloud's edge.
 */
function dashless_wpcloud_send_file( $file, $release_id, $status = 200, $content_generation = null ) {
	$types = array(
		'avif' => 'image/avif',
		'css'  => 'text/css; charset=UTF-8',
		'gif'  => 'image/gif',
		'html' => 'text/html; charset=UTF-8',
		'ico'  => 'image/x-icon',
		'jpeg' => 'image/jpeg',
		'jpg'  => 'image/jpeg',
		'js'   => 'text/javascript; charset=UTF-8',
		'json' => 'application/json; charset=UTF-8',
		'map'  => 'application/json; charset=UTF-8',
		'mjs'  => 'text/javascript; charset=UTF-8',
		'mp3'  => 'audio/mpeg',
		'otf'  => 'font/otf',
		'pdf'  => 'application/pdf',
		'png'  => 'image/png',
		'svg'  => 'image/svg+xml',
		'ttf'  => 'font/ttf',
		'txt'  => 'text/plain; charset=UTF-8',
		'webmanifest' => 'application/manifest+json',
		'webp' => 'image/webp',
		'woff' => 'font/woff',
		'woff2' => 'font/woff2',
		'xml'  => 'application/xml; charset=UTF-8',
	);
	$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	$type      = isset( $types[ $extension ] ) ? $types[ $extension ] : 'application/octet-stream';
	$size      = (int) filesize( $file );
	$etag      = '"dashless-' . $release_id . '-' . (string) $size . '-' . (string) filemtime( $file ) . '"';
	$start     = 0;
	$end       = max( 0, $size - 1 );
	$partial   = false;
	$range     = isset( $_SERVER['HTTP_RANGE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) ) : '';

	if ( 200 === $status && $size > 0 && '' !== $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $matches ) ) {
		$first = $matches[1];
		$last  = $matches[2];
		if ( '' === $first && '' !== $last && (int) $last > 0 ) {
			$start = max( 0, $size - (int) $last );
		} elseif ( '' !== $first ) {
			$start = (int) $first;
			$end   = '' !== $last ? min( (int) $last, $size - 1 ) : $size - 1;
		}
		if ( $start >= $size || $start > $end || ( '' === $first && ( '' === $last || (int) $last <= 0 ) ) ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . (string) $size );
			header( 'Content-Length: 0' );
			header( 'Accept-Ranges: bytes' );
			header( 'X-Dashless-Release: ' . $release_id );
			exit;
		}
		$partial = true;
	}

	status_header( $partial ? 206 : $status );
	header( 'Content-Type: ' . $type );
	header( 'Content-Length: ' . (string) ( $partial ? $end - $start + 1 : $size ) );
	header( 'Accept-Ranges: bytes' );
	if ( $partial ) {
		header( 'Content-Range: bytes ' . (string) $start . '-' . (string) $end . '/' . (string) $size );
	}
	header( 'Cache-Control: public, max-age=0, s-maxage=300, must-revalidate, stale-if-error=86400' );
	header( 'ETag: ' . $etag );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $file ) ) . ' GMT' );
	header( 'Vary: Host', false );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Dashless-Release: ' . $release_id );
	if ( null !== $content_generation ) {
		header( 'X-Dashless-Content-Generation: ' . (string) absint( $content_generation ) );
	}

	$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
	if ( trim( $if_none_match ) === $etag ) {
		header_remove( 'Content-Length' );
		status_header( 304 );
		exit;
	}

	if ( 'HEAD' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
		if ( ! $partial ) {
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		} else {
			$handle    = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$remaining = $end - $start + 1;
			if ( false !== $handle && 0 === fseek( $handle, $start ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek
				while ( $remaining > 0 && ! feof( $handle ) ) {
					$chunk = fread( $handle, min( 65536, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( false === $chunk || '' === $chunk ) {
						break;
					}
					echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$remaining -= strlen( $chunk );
				}
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}
	}
	exit;
}

/**
 * Replace only reader-facing requests with the active Astro release.
 */
function dashless_wpcloud_route_request() {
	$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );
	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
		return;
	}

	$release = get_option( DASHLESS_WPCLOUD_OPTION, array() );
	if ( empty( $release['id'] ) || empty( $release['public_host'] ) ) {
		return;
	}

	$host = strtolower( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' );
	$host = preg_replace( '/:\d+$/', '', $host );
	$host = rtrim( $host, '.' );
	if ( $host !== $release['public_host'] ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	$sitemap_redirect = dashless_wpcloud_sitemap_redirect_target( $path );
	if ( false !== $sitemap_redirect && wp_safe_redirect( $sitemap_redirect, 301, 'Dashless' ) ) {
		exit;
	}
	if ( dashless_wpcloud_is_wordpress_request( $path ) ) {
		return;
	}

	$release_directory = dashless_wpcloud_releases_directory() . '/' . $release['id'];
	$file              = dashless_wpcloud_resolve_file( $release_directory, $path );
	if ( false !== $file ) {
		dashless_wpcloud_send_file( $file, $release['id'], 200, $release['content_generation'] ?? null );
	}

	$not_found = dashless_wpcloud_resolve_file( $release_directory, '/404.html' );
	if ( false !== $not_found ) {
		dashless_wpcloud_send_file( $not_found, $release['id'], 404, $release['content_generation'] ?? null );
	}
}

add_action( 'template_redirect', 'dashless_wpcloud_route_request', -10000 );
