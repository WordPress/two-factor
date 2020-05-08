<?php

// Ensure this is run only once.
if ( defined( 'ABSPATH' ) ) {
	return;
}

define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_DEBUG', true );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

define( 'ABSPATH', __DIR__ . '/src/' );
