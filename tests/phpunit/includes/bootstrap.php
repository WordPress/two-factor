<?php
/**
 * Bootstrap the WordPress unit testing environment.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

// Travis testing.
if ( empty( $_tests_dir ) ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Command line testing in Core.
if ( ! file_exists( $_tests_dir . '/includes/' ) ) {
	$_tests_dir = '../../../../tests/phpunit';
	if ( ! file_exists( $_tests_dir . '/includes/' ) ) {
		trigger_error( 'Unable to locate wordpress-tests-lib', E_USER_ERROR );
	}
}

require_once $_tests_dir . '/includes/functions.php';

// Activate the plugins.
if ( defined( 'WP_TEST_ACTIVATED_PLUGINS' ) ) {
	$GLOBALS['wp_tests_options']['active_plugins'] = explode( ',', WP_TEST_ACTIVATED_PLUGINS );
}

require $_tests_dir . '/includes/bootstrap.php';