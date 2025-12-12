<?php
/**
 * Two Factor
 *
 * @package     Two_Factor
 * @author      WordPress.org Contributors
 * @copyright   2020 Plugin Contributors
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Two Factor
 * Plugin URI:        https://wordpress.org/plugins/two-factor/
 * Description:       Enable Two-Factor Authentication using time-based one-time passwords, Universal 2nd Factor (FIDO U2F, YubiKey), email, and backup verification codes.
 * Requires at least: 6.7
 * Version:           0.14.2
 * Requires PHP:      7.2
 * Author:            WordPress.org Contributors
 * Author URI:        https://github.com/wordpress/two-factor/graphs/contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       two-factor
 * Network:           True
 */

/**
 * Shortcut constant to the path of this file.
 */
define( 'TWO_FACTOR_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Version of the plugin.
 */
define( 'TWO_FACTOR_VERSION', '0.14.2' );

/**
 * Include the base class here, so that other plugins can also extend it.
 */
require_once TWO_FACTOR_DIR . 'providers/class-two-factor-provider.php';

/**
 * Include the core that handles the common bits.
 */
require_once TWO_FACTOR_DIR . 'class-two-factor-core.php';

/**
 * A compatibility layer for some of the most-used plugins out there.
 */
require_once TWO_FACTOR_DIR . 'class-two-factor-compat.php';

$two_factor_compat = new Two_Factor_Compat();

Two_Factor_Core::add_hooks( $two_factor_compat );

// Delete our options and user meta during uninstall.
register_uninstall_hook( __FILE__, array( Two_Factor_Core::class, 'uninstall' ) );


/**
 * Add "Settings" link to the plugin action links on the Plugins screen.
 *
 * @since 0.14.3
 *
 * @param string[] $links An array of plugin action links.
 * @return string[] Modified array with the Settings link added.
 */
function two_factor_add_settings_action_link( $links ) {
	$settings_url  = admin_url( 'profile.php#application-passwords-section' );
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $settings_url ),
		esc_html__( 'Settings', 'two-factor' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	'two_factor_add_settings_action_link'
);
