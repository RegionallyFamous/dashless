<?php

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

$input = json_decode( stream_get_contents( STDIN ), true );
if ( ! is_array( $input ) ) {
	fwrite( STDERR, "Invalid harness input.\n" );
	exit( 2 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPMU_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/wordpress' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['dashless_test_uploads'] = $input['uploads_basedir'] ?? sys_get_temp_dir();
$GLOBALS['dashless_test_options'] = $input['options'] ?? array();
$GLOBALS['dashless_test_posts'] = $input['posts'] ?? array();
$GLOBALS['dashless_test_transients'] = $input['transients'] ?? array();
$GLOBALS['dashless_test_mail'] = array();
$GLOBALS['dashless_test_mail_success'] = $input['mail_success'] ?? true;
$GLOBALS['dashless_test_site_name'] = $input['site_name'] ?? 'Teddy';
$GLOBALS['dashless_test_remote_body'] = $input['remote_body'] ?? '';
$GLOBALS['dashless_test_remote_code'] = $input['remote_code'] ?? 200;
$_SERVER['REMOTE_ADDR'] = $input['remote_addr'] ?? '203.0.113.10';

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code, $message, $data = array() ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
}

class WP_REST_Request {
	private $params = array();
	public function __construct( $method = 'GET', $route = '/' ) {}
	public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
}

class WP_REST_Response {
	private $data;
	public $status;
	public $headers = array();
	public function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function set_data( $data ) { $this->data = $data; }
	public function header( $name, $value ) { $this->headers[ $name ] = $value; }
}

function add_action( $hook, $callback, $priority = 10 ) {}
function current_user_can( $capability ) { return true; }
function rest_ensure_response( $data ) { return new WP_REST_Response( $data ); }
function wp_upload_dir( $time = null, $create = true ) { return array( 'basedir' => $GLOBALS['dashless_test_uploads'] ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( trim( (string) $value ), FILTER_SANITIZE_EMAIL ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function get_option( $key, $fallback = false ) { return $GLOBALS['dashless_test_options'][ $key ] ?? $fallback; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['dashless_test_options'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['dashless_test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $expiration ) { $GLOBALS['dashless_test_transients'][ $key ] = $value; return true; }
function wp_salt( $scheme = 'auth' ) { return 'dashless-test-salt-' . $scheme; }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function get_post( $post_id ) { return $GLOBALS['dashless_test_posts'][ $post_id ] ?? null; }
function get_post_type( $post_id ) { $post = get_post( $post_id ); return $post['post_type'] ?? false; }
function get_post_status( $post_id ) { $post = get_post( $post_id ); return $post['post_status'] ?? false; }
function get_the_title( $post_id ) { $post = get_post( $post_id ); return $post['post_title'] ?? '' ; }
function get_permalink( $post_id ) { return 'https://teddy.blog/stories/' . $post_id . '/'; }
function home_url( $path = '/' ) { return 'https://teddy.blog' . '/' . ltrim( (string) $path, '/' ); }
function rest_url( $path = '' ) { return 'https://teddy.blog/wp-json/' . ltrim( (string) $path, '/' ); }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function get_bloginfo( $show ) { return 'name' === $show ? $GLOBALS['dashless_test_site_name'] : ''; }
function wp_specialchars_decode( $value, $quote_style = ENT_NOQUOTES ) { return html_entity_decode( $value, $quote_style ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wp_mail( $to, $subject, $message, $headers = '' ) {
	$GLOBALS['dashless_test_mail'][] = compact( 'to', 'subject', 'message', 'headers' );
	return (bool) $GLOBALS['dashless_test_mail_success'];
}
function wp_cache_flush() { return true; }
function do_action( $hook, ...$args ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_http_validate_url( $value ) { return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : false; }
function wp_safe_remote_get( $url, $args = array() ) { return array( 'response' => array( 'code' => $GLOBALS['dashless_test_remote_code'] ), 'body' => $GLOBALS['dashless_test_remote_body'] ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }

require dirname( __DIR__, 2 ) . '/wordpress/dashless-wpcloud.php';

$action = $input['action'] ?? '';
if ( 'activate' === $action ) {
	$request = new WP_REST_Request( 'POST', '/dashless/v1/release/activate' );
	$request->set_param( 'release_id', $input['release_id'] ?? '' );
	$request->set_param( 'public_url', $input['public_url'] ?? '' );
	if ( array_key_exists( 'content_generation', $input ) ) {
		$request->set_param( 'content_generation', $input['content_generation'] );
	}
	$result = dashless_wpcloud_activate_release( $request );
} elseif ( 'rollback' === $action ) {
	$request = null;
	if ( array_key_exists( 'expected_release_id', $input ) ) {
		$request = new WP_REST_Request( 'POST', '/dashless/v1/release/rollback' );
		$request->set_param( 'expected_release_id', $input['expected_release_id'] );
	}
	$result = dashless_wpcloud_rollback_release( $request );
} elseif ( 'resolve' === $action ) {
	$file = dashless_wpcloud_resolve_file( $input['release_directory'] ?? '', $input['request_path'] ?? '/' );
	$result = new WP_REST_Response( array( 'file' => false === $file ? null : $file ) );
} elseif ( 'sitemap_redirect' === $action ) {
	$target = dashless_wpcloud_sitemap_redirect_target( $input['request_path'] ?? '/' );
	$result = new WP_REST_Response( array( 'target' => false === $target ? null : $target ) );
} elseif ( 'mailbox' === $action ) {
	$request = new WP_REST_Request( 'POST', '/dashless/v1/mailbox' );
	foreach ( array( 'post', 'author_name', 'author_email', 'content', 'website' ) as $param ) {
		$request->set_param( $param, $input[ $param ] ?? '' );
	}
	$result = dashless_mailbox_deliver( $request );
} elseif ( 'receiver_request' === $action ) {
	$request = new WP_REST_Request( 'POST', '/dashless/v1/receiver-list' );
	foreach ( array( 'email', 'website', 'source' ) as $param ) {
		$request->set_param( $param, $input[ $param ] ?? '' );
	}
	$result = dashless_receiver_list_request( $request );
} elseif ( 'receiver_confirm' === $action ) {
	$request = new WP_REST_Request( 'GET', '/dashless/v1/receiver-list/confirm' );
	$request->set_param( 'token', $input['token'] ?? '' );
	$request->set_param( 'action', $input['receiver_action'] ?? 'confirm' );
	$result = dashless_receiver_list_confirm( $request );
} elseif ( 'reaction' === $action ) {
	$request = new WP_REST_Request( 'POST', '/dashless/v1/reaction' );
	$request->set_param( 'post', $input['post'] ?? 0 );
	$request->set_param( 'reaction', $input['reaction'] ?? '' );
	$result = dashless_private_reaction_deliver( $request );
} elseif ( 'webmention_receive' === $action ) {
	$request = new WP_REST_Request( 'POST', '/dashless/v1/webmention' );
	$request->set_param( 'source', $input['source'] ?? '' );
	$request->set_param( 'target', $input['target'] ?? '' );
	$result = dashless_webmention_receive( $request );
} elseif ( 'webmention_list' === $action ) {
	$request = new WP_REST_Request( 'GET', '/dashless/v1/webmention' );
	$request->set_param( 'target', $input['target'] ?? '' );
	$result = dashless_webmention_list( $request );
} elseif ( 'webmention_moderate' === $action ) {
	$request = new WP_REST_Request( 'GET', '/dashless/v1/webmention/moderate' );
	$request->set_param( 'token', $input['token'] ?? '' );
	$request->set_param( 'action', $input['moderation_action'] ?? 'approve' );
	$result = dashless_webmention_moderate( $request );
} else {
	fwrite( STDERR, "Unknown harness action.\n" );
	exit( 2 );
}

if ( is_wp_error( $result ) ) {
	$output = array(
		'ok'      => false,
		'error'   => array( 'code' => $result->code, 'message' => $result->message, 'data' => $result->data ),
		'options' => $GLOBALS['dashless_test_options'],
		'transients' => $GLOBALS['dashless_test_transients'],
		'mail'    => $GLOBALS['dashless_test_mail'],
	);
} else {
	$output = array(
		'ok'      => true,
		'data'    => $result->get_data(),
		'options' => $GLOBALS['dashless_test_options'],
		'transients' => $GLOBALS['dashless_test_transients'],
		'mail'    => $GLOBALS['dashless_test_mail'],
		'status'  => $result->status ?? 200,
		'headers' => $result->headers ?? array(),
	);
}

echo json_encode( $output, JSON_UNESCAPED_SLASHES ) . "\n";
