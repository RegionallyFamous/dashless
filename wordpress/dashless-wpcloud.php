<?php
/**
 * Plugin Name: Dashless for WordPress
 * Description: Connects WordPress to the local Dashless Codex workflow and serves atomic Astro releases on WP Cloud.
 * Version: 1.0.0
 * Author: Regionally Famous
 * License: GPL-2.0-or-later
 */

// Legacy upgrade marker retained so Dashless WP Cloud Bridge 0.3 can verify this staged successor: Plugin Name: Dashless WP Cloud Bridge.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DASHLESS_WPCLOUD_OPTION = 'dashless_wpcloud_release';
const DASHLESS_CONTENT_VERSION_OPTION = 'dashless_content_version';
const DASHLESS_WPCLOUD_BRIDGE_VERSION = '1.0.0';

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
			'previous_release_id' => isset( $release['previous']['id'] ) ? $release['previous']['id'] : null,
			'bridge'       => DASHLESS_WPCLOUD_BRIDGE_VERSION,
			'bridge_sha256' => hash_file( 'sha256', __FILE__ ),
			'content_version' => get_option( DASHLESS_CONTENT_VERSION_OPTION, array() ),
		)
	);
}

/**
 * Atomically select a fully uploaded release. The old release stays active if
 * validation fails at any point.
 */
function dashless_wpcloud_activate_release( WP_REST_Request $request ) {
	$release_id = sanitize_text_field( (string) $request->get_param( 'release_id' ) );
	$public_url = esc_url_raw( (string) $request->get_param( 'public_url' ) );

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

	$current = get_option( DASHLESS_WPCLOUD_OPTION, array() );
	if ( isset( $current['id'] ) && $release_id === $current['id'] && $public_host === ( $current['public_host'] ?? '' ) ) {
		return rest_ensure_response(
			array(
				'activated'    => true,
				'idempotent'   => true,
				'release_id'   => $release_id,
				'public_host'  => $public_host,
				'activated_at' => $current['activated_at'] ?? null,
				'previous_release_id' => $current['previous']['id'] ?? null,
			)
		);
	}

	$release = array(
		'id'           => $release_id,
		'public_host'  => $public_host,
		'activated_at' => gmdate( 'c' ),
		'previous'     => ! empty( $current['id'] ) ? array(
			'id'           => $current['id'],
			'public_host'  => $current['public_host'] ?? $public_host,
			'activated_at' => $current['activated_at'] ?? null,
		) : null,
	);

	update_option( DASHLESS_WPCLOUD_OPTION, $release, false );
	$stored = get_option( DASHLESS_WPCLOUD_OPTION, array() );
	if ( ( $stored['id'] ?? '' ) !== $release_id || ( $stored['public_host'] ?? '' ) !== $public_host ) {
		return new WP_Error( 'dashless_activation_not_persisted', 'WordPress could not persist the active Dashless release.', array( 'status' => 500 ) );
	}
	wp_cache_flush();

	do_action( 'dashless_wpcloud_release_activated', $release );

	return rest_ensure_response(
		array(
			'activated'    => true,
			'release_id'   => $release_id,
			'public_host'  => $public_host,
			'activated_at' => $release['activated_at'],
			'previous_release_id' => $release['previous']['id'] ?? null,
		)
	);
}

/**
 * Reactivate the immediately previous verified release.
 */
function dashless_wpcloud_rollback_release() {
	$current  = get_option( DASHLESS_WPCLOUD_OPTION, array() );
	$previous = isset( $current['previous'] ) && is_array( $current['previous'] ) ? $current['previous'] : array();
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
	if ( false === strpos( $source, 'Plugin Name: Dashless for WordPress' ) || ! preg_match( "/DASHLESS_WPCLOUD_BRIDGE_VERSION = '" . preg_quote( $version, '/' ) . "'/", $source ) ) {
		return new WP_Error( 'dashless_bridge_upgrade_invalid', 'The staged file is not the expected Dashless bridge version.', array( 'status' => 409 ) );
	}

	if ( ! rename( $staged, __FILE__ ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
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
		$allowed_extensions = array( 'avif', 'css', 'gif', 'html', 'ico', 'jpeg', 'jpg', 'js', 'json', 'map', 'mjs', 'otf', 'pdf', 'png', 'svg', 'ttf', 'txt', 'webmanifest', 'webp', 'woff', 'woff2', 'xml' );
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
function dashless_wpcloud_send_file( $file, $release_id, $status = 200 ) {
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
	$etag      = '"dashless-' . $release_id . '-' . (string) filesize( $file ) . '-' . (string) filemtime( $file ) . '"';

	status_header( $status );
	header( 'Content-Type: ' . $type );
	header( 'Content-Length: ' . (string) filesize( $file ) );
	header( 'Cache-Control: public, max-age=0, s-maxage=300, must-revalidate, stale-if-error=86400' );
	header( 'ETag: ' . $etag );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $file ) ) . ' GMT' );
	header( 'Vary: Host', false );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Dashless-Release: ' . $release_id );

	if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) {
		header_remove( 'Content-Length' );
		status_header( 304 );
		exit;
	}

	if ( 'HEAD' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
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

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( dashless_wpcloud_is_wordpress_request( $path ) ) {
		return;
	}

	$release_directory = dashless_wpcloud_releases_directory() . '/' . $release['id'];
	$file              = dashless_wpcloud_resolve_file( $release_directory, $path );
	if ( false !== $file ) {
		dashless_wpcloud_send_file( $file, $release['id'] );
	}

	$not_found = dashless_wpcloud_resolve_file( $release_directory, '/404.html' );
	if ( false !== $not_found ) {
		dashless_wpcloud_send_file( $not_found, $release['id'], 404 );
	}
}

add_action( 'template_redirect', 'dashless_wpcloud_route_request', -10000 );
