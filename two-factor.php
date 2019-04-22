<?php
/**
 * Plugin Name: Two Factor
 * Plugin URI: https://wordpress.org/plugins/two-factor/
 * Description: A prototype extensible core to enable Two-Factor Authentication.
 * Author: George Stephanis
 * Version: 0.4.4
 * Author URI: https://stephanis.info
 * Network: True
 * Text Domain: two-factor
 */

/**
 * Shortcut constant to the path of this file.
 */
define( 'TWO_FACTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once( TWO_FACTOR_DIR . 'providers/class.two-factor-provider.php' );
require_once( TWO_FACTOR_DIR . 'class.two-factor-plugin.php' );
require_once( TWO_FACTOR_DIR . 'class.two-factor-core.php' );

$two_factor_plugin = new Two_Factor_Plugin( __FILE__ );
$two_factor = new Two_Factor_Core( $two_factor_plugin );
$two_factor->add_hooks();
