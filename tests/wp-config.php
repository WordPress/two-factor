<?php
/**
 * WP config for PHP unit tests.
 *
 * @package two-factor
 *
 * phpcs:disable WordPress.PHP.DisallowShortTernary.Found
 */

// Use our local WordPress source code which can be adjusted through Composer.
define( 'ABSPATH', dirname( __DIR__ ) . '/wordpress/' );

// Test with the default theme.
define( 'WP_DEFAULT_THEME', 'default' );

// Test with WordPress debug mode (default).
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WORDPRESS_DB_NAME' ) ?: 'root' );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: '' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = getenv( 'WORDPRESS_TABLE_PREFIX' ) ?: 'wpphpunittests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );
