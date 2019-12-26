<?php
/**
 * Plugin Name: Two Factor
 * Plugin URI: https://wordpress.org/plugins/two-factor/
 * Description: A prototype extensible core to enable Two-Factor Authentication.
 * Author: Plugin Contributors
 * Version: 0.4.7
 * Author URI: https://github.com/wordpress/two-factor/graphs/contributors
 * Network: True
 * Text Domain: two-factor
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
