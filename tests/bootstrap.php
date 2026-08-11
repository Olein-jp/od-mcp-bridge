<?php
/**
 * WordPress integration test bootstrap.
 *
 * @package OdMcpBridge
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	printf( "WP_TESTS_DIR is not set. Run the tests through wp-env.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/od-mcp-bridge.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
