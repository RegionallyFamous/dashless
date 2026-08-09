<?php

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

define( 'WP_UNINSTALL_PLUGIN', true );
$GLOBALS['dashless_deleted_options'] = array();

function delete_option( $name ) {
	$GLOBALS['dashless_deleted_options'][] = $name;
	return true;
}

require dirname( __DIR__, 2 ) . '/wordpress/uninstall.php';

echo json_encode( $GLOBALS['dashless_deleted_options'] ) . "\n";
