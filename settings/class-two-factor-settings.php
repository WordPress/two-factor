<?php
/**
 * Admin settings UI for the Two-Factor plugin.
 * Provides a site-wide settings screen for disabling individual Two-Factor providers.
 *
 * @since 0.16
 *
 * @package Two_Factor
 */

/**
 * Settings screen renderer for Two-Factor.
 *
 * @since 0.16
 */
class Two_Factor_Settings {

	/**
	 * Render the settings page.
	 * Also handles saving of settings when the form is submitted.
	 *
	 * @since 0.16
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle save.
		if ( isset( $_POST['two_factor_settings_submit'] ) ) {
			check_admin_referer( 'two_factor_save_settings', 'two_factor_settings_nonce' );

			$posted = isset( $_POST['two_factor_enabled_providers'] ) && is_array( $_POST['two_factor_enabled_providers'] ) ? wp_unslash( $_POST['two_factor_enabled_providers'] ) : array();

			// Sanitize posted values immediately.
			$posted = array_map( 'sanitize_text_field', (array) $posted );
			// Remove empty values.
			$enabled = array_values( array_filter( $posted, 'strlen' ) );

			update_option( 'two_factor_enabled_providers', array_values( array_unique( $enabled ) ) );

			$enforced_roles_posted = isset( $_POST['two_factor_enforced_roles'] ) && is_array( $_POST['two_factor_enforced_roles'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['two_factor_enforced_roles'] ) )
				: array();
			update_option( 'two_factor_enforced_roles', array_values( array_unique( $enforced_roles_posted ) ) );

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'two-factor' ) . '</p></div>';
		}

		// Show a warning when enforcement is active but the Email provider is disabled,
		// because enforcement relies on Email being available for users not yet enrolled.
		$_enforced = (array) get_option( 'two_factor_enforced_roles', array() );
		if ( ! empty( $_enforced ) ) {
			$_site_enabled = function_exists( 'two_factor_get_enabled_providers_option' )
				? two_factor_get_enabled_providers_option()
				: null;
			if ( null !== $_site_enabled && ! in_array( 'Two_Factor_Email', $_site_enabled, true ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Two-Factor enforcement is active, but the Email provider is disabled. Users in enforced roles who have not yet set up 2FA will not be challenged on login. Enable the Email provider to ensure enforcement works.', 'two-factor' ) . '</p></div>';
			}
		}

		// Build provider list for display using public core API.
		$provider_instances = array();
		if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'get_providers' ) ) {
			$provider_instances = Two_Factor_Core::get_providers();
			if ( ! is_array( $provider_instances ) ) {
				$provider_instances = array();
			}
		}

		// Default to all providers enabled when the option has never been saved.
		$all_provider_keys = array_keys( $provider_instances );
		$saved_enabled = get_option( 'two_factor_enabled_providers', $all_provider_keys );

		echo '<div class="wrap two-factor-settings">';
		echo '<h1>' . esc_html__( 'Two-Factor Settings', 'two-factor' ) . '</h1>';
		echo '<h2>' . esc_html__( 'Enabled Providers', 'two-factor' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Choose which Two-Factor providers are available on this site. All providers are enabled by default.', 'two-factor' ) . '</p>';
		echo '<form method="post" action="">';
		wp_nonce_field( 'two_factor_save_settings', 'two_factor_settings_nonce' );

		echo '<fieldset class="two-factor-providers"><legend class="screen-reader-text">' . esc_html__( 'Providers', 'two-factor' ) . '</legend>';
		echo '<table class="form-table"><tbody>';

		if ( empty( $provider_instances ) ) {
			echo '<tr><td>' . esc_html__( 'No providers found.', 'two-factor' ) . '</td></tr>';
		} else {
			// Render a compact stacked list of provider checkboxes below the title/description.
			echo '<tr>';
			echo '<td>';
			foreach ( $provider_instances as $provider_key => $instance ) {
				$label = method_exists( $instance, 'get_label' ) ? $instance->get_label() : $provider_key;

				echo '<p class="provider-item"><label for="provider_' . esc_attr( $provider_key ) . '">';
				echo '<input type="checkbox" name="two_factor_enabled_providers[]" id="provider_' . esc_attr( $provider_key ) . '" value="' . esc_attr( $provider_key ) . '" ' . checked( in_array( $provider_key, (array) $saved_enabled, true ), true, false ) . ' /> ';
				echo esc_html( $label );
				echo '</label></p>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '</fieldset>';

		// --- Enforcement section ---
		$saved_enforced_roles = (array) get_option( 'two_factor_enforced_roles', array() );
		$all_roles            = wp_roles()->get_names();

		echo '<h2>' . esc_html__( 'Two-Factor Enforcement', 'two-factor' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Require Two-Factor authentication for specific user roles. Users in enforced roles who have not yet set up 2FA will be challenged via the Email provider on login. This requires the Email provider to be enabled above. New users in enforced roles will also have the Email provider enabled on registration.', 'two-factor' ) . '</p>';

		echo '<fieldset class="two-factor-enforcement"><legend class="screen-reader-text">' . esc_html__( 'Enforced Roles', 'two-factor' ) . '</legend>';
		echo '<table class="form-table"><tbody>';

		if ( empty( $all_roles ) ) {
			echo '<tr><td>' . esc_html__( 'No roles found.', 'two-factor' ) . '</td></tr>';
		} else {
			echo '<tr><td>';
			foreach ( $all_roles as $role_slug => $role_name ) {
				$role_slug = sanitize_key( $role_slug );
				echo '<p class="provider-item"><label for="role_' . esc_attr( $role_slug ) . '">';
				echo '<input type="checkbox" name="two_factor_enforced_roles[]" id="role_' . esc_attr( $role_slug ) . '" value="' . esc_attr( $role_slug ) . '" ' . checked( in_array( $role_slug, $saved_enforced_roles, true ), true, false ) . ' /> ';
				echo esc_html( translate_user_role( $role_name ) );
				echo '</label></p>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</fieldset>';

		submit_button( __( 'Save Settings', 'two-factor' ), 'primary', 'two_factor_settings_submit' );
		echo '</form>';

		echo '</div>';
	}

}
