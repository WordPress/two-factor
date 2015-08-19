<?php
/**
 * Plugin Name: Two Factor
 * Plugin URI: http://github.com/georgestephanis/two-factor/
 * Description: A prototype extensible core to enable Two-Factor Authentication.
 * Author: George Stephanis
 * Version: 0.1-dev
 * Author URI: http://stephanis.info
 */

/**
 * Shortcut constant to the path of this file.
 */
define( 'TWO_FACTOR_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Include the base class here, so that other plugins can also extend it.
 */
require_once( TWO_FACTOR_DIR . 'providers/class.two-factor-provider.php' );

/**
 * Include the core that handles the common bits.
 */
require_once( TWO_FACTOR_DIR . 'class.two-factor-core.php' );
Two_Factor_Core::add_hooks();

if ( version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
	/**
	 * Remove FIDO U2F if PHP 5.2.
	 *
	 * @param array $providers Array of providers.
	 * @return array Array of providers.
	 */
	function remove_fido_u2f_support( $providers ) {
		unset( $providers['Two_Factor_FIDO_U2F'] );
		return $providers;
	}

	add_filter( 'two_factor_providers', 'remove_fido_u2f_support' );
}

/**
 * Include the application passwords system.
 */
require_once( TWO_FACTOR_DIR . 'class.application-passwords.php' );
Application_Passwords::add_hooks();
