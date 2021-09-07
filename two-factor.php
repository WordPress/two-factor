<?php
/**
 * Two Factor
 *
 * @package     Two_Factor
 * @author      Plugin Contributors
 * @copyright   2020 Plugin Contributors
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Two Factor
 * Plugin URI: https://wordpress.org/plugins/two-factor/
 * Description: Two-Factor Authentication using time-based one-time passwords, Universal 2nd Factor (FIDO U2F), email and backup verification codes.
 * Author: Plugin Contributors
 * Version: 0.7.1
 * Author URI: https://github.com/wordpress/two-factor/graphs/contributors
 * Network: True
 * Text Domain: two-factor
 */

/**
 * Shortcut constant to the path of this file.
 */
define( 'TWO_FACTOR_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Version of the plugin.
 */
define( 'TWO_FACTOR_VERSION', '0.7.1' );

/**
 * Include the base class here, so that other plugins can also extend it.
 */
require_once TWO_FACTOR_DIR . 'providers/class-two-factor-provider.php';

/**
 * Include the core that handles the common bits.
 */
require_once TWO_FACTOR_DIR . 'class-two-factor-core.php';

/**
 * A compatability layer for some of the most-used plugins out there.
 */
require_once TWO_FACTOR_DIR . 'class-two-factor-compat.php';

$two_factor_compat = new Two_Factor_Compat();

Two_Factor_Core::add_hooks( $two_factor_compat );
