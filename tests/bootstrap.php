<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * @package two-factor
 */

// Composer autoloader must be loaded before phpunit will be available.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Determine the tests directory (from a WP dev checkout).
// Try the WP_TESTS_DIR environment variable first.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

// See if we're installed inside an existing WP dev instance.
if ( ! $_tests_dir ) {
	$_try_tests_dir = __DIR__ . '/../../../../../tests/phpunit';
	if ( file_exists( $_try_tests_dir . '/includes/functions.php' ) ) {
		$_tests_dir = $_try_tests_dir;
	}
}

// Fallback.
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';
require_once dirname( __DIR__ ) . '/includes/function.login-header.php';
require_once dirname( __DIR__ ) . '/includes/function.login-footer.php';
require_once __DIR__ . '/class-two-factor-redirect-exception.php';

// Activate the plugin.
tests_add_filter(
	'muplugins_loaded',
	function () {
		// Load the Secrets API feature plugin when the tests environment provides it.
		$secrets_api = getenv( 'TWO_FACTOR_SECRETS_API_FILE' );
		if ( ! $secrets_api ) {
			$secrets_api = WP_PLUGIN_DIR . '/secrets-api/secrets-api.php';
		}
		if ( file_exists( $secrets_api ) ) {
			require_once $secrets_api;
		}

		require_once dirname( __DIR__ ) . '/two-factor.php';
	}
);

// Start up the WP testing environment.
require_once $_tests_dir . '/includes/bootstrap.php';

// The shared secrets test case extends WP_UnitTestCase, which exists only after the line above.
require_once __DIR__ . '/class-two-factor-secrets-test-case.php';
