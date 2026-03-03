<?php
/**
 * Plugin constants.
 *
 * Included by both the main plugin file and the PHPStan bootstrap
 * so that values are maintained in a single place.
 *
 * @package Two_Factor
 */

if ( ! defined( 'TWO_FACTOR_DIR' ) ) {
	define( 'TWO_FACTOR_DIR', __DIR__ . '/' );
}

if ( ! defined( 'TWO_FACTOR_VERSION' ) ) {
	define( 'TWO_FACTOR_VERSION', '0.15.0' );
}
