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


/*
 * Warning: Changing this value will break decryption for existing users, and prevent
 * them from logging in with this factor. If you change this you must create a constant
 * to facilitate migration:
 *
 * define( 'TWO_FACTOR_TOTP_ENCRYPTION_SALT_MIGRATE', 'place the old value here' );
 *
 * See {@TODO support article URL} for more information.
 */
define( 'TWO_FACTOR_TOTP_ENCRYPTION_SALT', '4N:v{FDL,s?:UM[[1>?.:Dq?=Iwh5%z]!f,2-6rDyv0/-za<03;q`J-YV:QOu;&3' );
define( 'SECURE_AUTH_SALT',     '389lrsuytneiarsm39p80talurynetim32ta790stjuynareitm3298pluynatri' );


define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );
