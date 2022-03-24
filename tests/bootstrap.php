<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * The `WP_PHPUNIT__DIR` constant is defined in the phpunit.xml file
 * in the project root directory.
 */

// Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

// Activate the plugin.
tests_add_filter(
	'muplugins_loaded',
	function() {
		require dirname( __DIR__ ) . '/two-factor.php';
	}
);

// Start up the WP testing environment.
require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
