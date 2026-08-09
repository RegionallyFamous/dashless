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

$GLOBALS['dashless_test_uploads'] = $input['uploads_basedir'] ?? sys_get_temp_dir();
$GLOBALS['dashless_test_options'] = $input['options'] ?? array();

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
	public function __construct( $data ) { $this->data = $data; }
	public function get_data() { return $this->data; }
	public function set_data( $data ) { $this->data = $data; }
}

function add_action( $hook, $callback, $priority = 10 ) {}
function current_user_can( $capability ) { return true; }
function rest_ensure_response( $data ) { return new WP_REST_Response( $data ); }
function wp_upload_dir( $time = null, $create = true ) { return array( 'basedir' => $GLOBALS['dashless_test_uploads'] ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function get_option( $key, $fallback = false ) { return $GLOBALS['dashless_test_options'][ $key ] ?? $fallback; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['dashless_test_options'][ $key ] = $value; return true; }
function wp_cache_flush() { return true; }
function do_action( $hook, ...$args ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

require dirname( __DIR__, 2 ) . '/wordpress/dashless-wpcloud.php';

$action = $input['action'] ?? '';
if ( 'activate' === $action ) {
	$request = new WP_REST_Request( 'POST', '/dashless/v1/release/activate' );
	$request->set_param( 'release_id', $input['release_id'] ?? '' );
	$request->set_param( 'public_url', $input['public_url'] ?? '' );
	$result = dashless_wpcloud_activate_release( $request );
} elseif ( 'rollback' === $action ) {
	$result = dashless_wpcloud_rollback_release();
} elseif ( 'resolve' === $action ) {
	$file = dashless_wpcloud_resolve_file( $input['release_directory'] ?? '', $input['request_path'] ?? '/' );
	$result = new WP_REST_Response( array( 'file' => false === $file ? null : $file ) );
} else {
	fwrite( STDERR, "Unknown harness action.\n" );
	exit( 2 );
}

if ( is_wp_error( $result ) ) {
	$output = array(
		'ok'      => false,
		'error'   => array( 'code' => $result->code, 'message' => $result->message, 'data' => $result->data ),
		'options' => $GLOBALS['dashless_test_options'],
	);
} else {
	$output = array(
		'ok'      => true,
		'data'    => $result->get_data(),
		'options' => $GLOBALS['dashless_test_options'],
	);
}

echo json_encode( $output, JSON_UNESCAPED_SLASHES ) . "\n";
