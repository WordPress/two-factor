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
 * Requires at least: 6.8
 * Version:           0.15.0
 * Requires PHP:      7.2
 * Author:            WordPress.org Contributors
 * Author URI:        https://github.com/wordpress/two-factor/graphs/contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       two-factor
 * Network:           True
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shortcut constant to the path of this file.
 */
define( 'TWO_FACTOR_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Version of the plugin.
 */
define( 'TWO_FACTOR_VERSION', '0.15.0' );

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

// Load settings UI class so the settings page can be rendered.
require_once TWO_FACTOR_DIR . 'settings/class-two-factor-settings.php';

$two_factor_compat = new Two_Factor_Compat();

Two_Factor_Core::add_hooks( $two_factor_compat );

// Delete our options and user meta during uninstall.
register_uninstall_hook( __FILE__, array( Two_Factor_Core::class, 'uninstall' ) );

/**
 * Register admin menu and plugin action links.
 */
function two_factor_register_admin_hooks() {
	if ( is_admin() ) {
		add_action( 'admin_menu', 'two_factor_add_settings_page' );
	}

	// Load settings page assets when in admin.
	// Settings assets handled inline via standard markup; no extra CSS enqueued.

	/* Enforcement filters: restrict providers based on saved disabled option. */
	add_filter( 'two_factor_providers', 'two_factor_filter_disabled_providers' );
	add_filter( 'two_factor_enabled_providers_for_user', 'two_factor_filter_disabled_enabled_providers_for_user', 10, 2 );
}

add_action( 'init', 'two_factor_register_admin_hooks' );

/**
 * Add the Two Factor settings page under Settings.
 */
function two_factor_add_settings_page() {
	add_options_page(
		__( 'Two-Factor Settings', 'two-factor' ),
		__( 'Two-Factor', 'two-factor' ),
		'manage_options',
		'two-factor-settings',
		'two_factor_render_settings_page'
	);
}


/**
 * Render the settings page via the settings class if available.
 */
function two_factor_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Prefer new settings class (keeps main file small).
	if ( class_exists( 'Two_Factor_Settings' ) && is_callable( array( 'Two_Factor_Settings', 'render_settings_page' ) ) ) {
		Two_Factor_Settings::render_settings_page();
		return;
	}

	// Fallback: no UI available.
	echo '<div class="wrap"><h1>' . esc_html__( 'Two-Factor Settings', 'two-factor' ) . '</h1>';
	echo '<p>' . esc_html__( 'Settings not available.', 'two-factor' ) . '</p></div>';
}


/**
 * Helper: retrieve disabled providers option as an array of classnames.
 * Empty array / missing option means none disabled (all allowed).
 *
 * @return array
 */
function two_factor_get_disabled_providers_option() {
	$disabled = get_option( 'two_factor_disabled_providers', array() );
	if ( empty( $disabled ) || ! is_array( $disabled ) ) {
		return array();
	}
	return $disabled;
}


/**
 * Filter the registered providers according to the saved disabled providers option.
 * This filter receives the providers in the same shape as core: classname => path.
 */
function two_factor_filter_disabled_providers( $providers ) {
	$disabled = two_factor_get_disabled_providers_option();

	// Empty disabled list means allow all providers.
	if ( empty( $disabled ) ) {
		return $providers;
	}

	// If we are rendering the settings page, do not filter so admins may re-enable providers.
	if ( is_admin() && isset( $_GET['page'] ) && 'two-factor-settings' === $_GET['page'] ) {
		return $providers;
	}

	foreach ( $providers as $key => $path ) {
		if ( in_array( $key, $disabled, true ) ) {
			unset( $providers[ $key ] );
		}
	}

	return $providers;
}


/**
 * Filter the supported providers for a specific user (instances keyed by provider key).
 */
function two_factor_filter_disabled_providers_for_user( $providers, $user ) {
	$disabled = two_factor_get_disabled_providers_option();
	if ( empty( $disabled ) ) {
		return $providers;
	}

	if ( is_admin() && isset( $_GET['page'] ) && 'two-factor-settings' === $_GET['page'] ) {
		return $providers;
	}

	foreach ( $providers as $key => $instance ) {
		if ( in_array( $key, $disabled, true ) ) {
			unset( $providers[ $key ] );
		}
	}

	return $providers;
}


/**
 * Filter enabled providers for a user (classnames array) to enforce disabled list.
 */
function two_factor_filter_disabled_enabled_providers_for_user( $enabled, $user_id ) {
	$disabled = two_factor_get_disabled_providers_option();
	if ( empty( $disabled ) ) {
		return $enabled;
	}

	return array_values( array_diff( (array) $enabled, $disabled ) );
}
