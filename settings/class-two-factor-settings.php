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

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'two-factor' ) . '</p></div>';
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
		$saved_enabled     = get_option( 'two_factor_enabled_providers', $all_provider_keys );

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

		submit_button( __( 'Save Settings', 'two-factor' ), 'primary', 'two_factor_settings_submit' );
		echo '</form>';

		echo '</div>';
	}
}
