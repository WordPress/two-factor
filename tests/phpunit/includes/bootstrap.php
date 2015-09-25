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

function _manually_load_plugin() {
	if ( defined( 'WP_TEST_ACTIVATED_PLUGINS' ) ) {
		$active_plugins = get_option( 'active_plugins', array() );
		$force_plugins = explode( ',', WP_TEST_ACTIVATED_PLUGINS );

		foreach( $force_plugins as $plugin ) {
			require dirname( __FILE__ ) . '/../../../../' . $plugin;

			$active_plugins[] = $plugin;
		}

		update_option( 'active_plugins', $active_plugins );
	}
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';