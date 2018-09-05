<?php
/**
 * Plugin Name: Two Factor
 * Plugin URI: https://github.com/georgestephanis/two-factor/
 * Description: A prototype extensible core to enable Two-Factor Authentication.
 * Author: George Stephanis
 * Version: 0.1-dev
 * Author URI: https://stephanis.info
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
require_once( TWO_FACTOR_DIR . 'class.two-factor-force.php' );

Two_Factor_Core::add_hooks();
Two_Factor_Force::add_hooks();
