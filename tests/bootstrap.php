<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * The `WP_PHPUNIT__DIR` constant is defined in the phpunit.xml file
 * in the project root directory.
 *
 * @package two-factor
 */

/**
 * Enforce our custom WP_PHPUNIT__TESTS_CONFIG defined in phpunit.xml.dist
 * from being replaced by wp-env environment variables.
 *
 * @see https://github.com/WordPress/gutenberg/blob/936ce3a79ac9f34cc12492e7df5f3320eaf2a6ca/packages/env/lib/build-docker-compose-config.js#L259-L260
 */
if ( false === strpos( getenv( 'WP_PHPUNIT__TESTS_CONFIG' ), '/two-factor/' ) ) {
	putenv( sprintf( 'WP_PHPUNIT__TESTS_CONFIG=%s/wp-config.php', __DIR__ ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
}

// Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';
require_once dirname( __DIR__ ) . '/includes/function.login-header.php';
require_once dirname( __DIR__ ) . '/includes/function.login-footer.php';

// Activate the plugin.
tests_add_filter(
	'muplugins_loaded',
	function() {
		require_once dirname( __DIR__ ) . '/two-factor.php';
	}
);

// Start up the WP testing environment.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
