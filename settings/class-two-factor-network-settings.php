<?php
/**
 * Network Admin settings UI for the Two-Factor plugin.
 * Provides a network-wide settings screen for disabling individual Two-Factor
 * providers and controlling whether subsites may override the network list.
 *
 * @since 0.17.0
 *
 * @package Two_Factor
 */

/**
 * Network settings screen renderer for Two-Factor.
 *
 * @since 0.17.0
 */
class Two_Factor_Network_Settings {

	/**
	 * Render the network settings page.
	 * Also handles saving of settings when the form is submitted.
	 *
	 * @since 0.17.0
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		// Build provider list for display and validation using public core API.
		$provider_instances = array();
		if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'get_providers' ) ) {
			$provider_instances = Two_Factor_Core::get_providers();
			if ( ! is_array( $provider_instances ) ) {
				$provider_instances = array();
			}
		}
		$all_provider_keys = array_keys( $provider_instances );

		// Handle save.
		if ( isset( $_POST['two_factor_network_settings_submit'] ) ) {
			check_admin_referer( 'two_factor_save_network_settings', 'two_factor_network_settings_nonce' );

			$posted = isset( $_POST['two_factor_network_enabled_providers'] ) && is_array( $_POST['two_factor_network_enabled_providers'] ) ? wp_unslash( $_POST['two_factor_network_enabled_providers'] ) : array();

			// Sanitize posted values immediately.
			$posted = array_map( 'sanitize_text_field', (array) $posted );
			// Remove empty values and keys that are not registered providers.
			$enabled = array_values(
				array_filter(
					array_unique( $posted ),
					function ( $key ) use ( $all_provider_keys ) {
						return strlen( $key ) && in_array( $key, $all_provider_keys, true );
					}
				)
			);

			update_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, $enabled );
			update_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, ! empty( $_POST['two_factor_network_allow_site_override'] ) ? 1 : 0 );

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'two-factor' ) . '</p></div>';
		}

		// Default to all providers enabled when the option has never been saved.
		$saved_enabled  = get_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, $all_provider_keys );
		$allow_override = (bool) get_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, false );

		echo '<div class="wrap two-factor-network-settings">';
		echo '<h1>' . esc_html__( 'Two-Factor Network Settings', 'two-factor' ) . '</h1>';
		echo '<h2>' . esc_html__( 'Enabled Providers', 'two-factor' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Choose which Two-Factor providers are available across the network. All providers are enabled by default.', 'two-factor' ) . '</p>';
		echo '<form method="post" action="">';
		wp_nonce_field( 'two_factor_save_network_settings', 'two_factor_network_settings_nonce' );

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

				echo '<p class="provider-item"><label for="network_provider_' . esc_attr( $provider_key ) . '">';
				echo '<input type="checkbox" name="two_factor_network_enabled_providers[]" id="network_provider_' . esc_attr( $provider_key ) . '" value="' . esc_attr( $provider_key ) . '" ' . checked( in_array( $provider_key, (array) $saved_enabled, true ), true, false ) . ' /> ';
				echo esc_html( $label );
				echo '</label></p>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</fieldset>';

		echo '<h2>' . esc_html__( 'Subsite Override', 'two-factor' ) . '</h2>';
		echo '<table class="form-table"><tbody><tr><td>';
		echo '<p class="provider-item"><label for="two_factor_network_allow_site_override">';
		echo '<input type="checkbox" name="two_factor_network_allow_site_override" id="two_factor_network_allow_site_override" value="1" ' . checked( $allow_override, true, false ) . ' /> ';
		echo esc_html__( 'Allow subsites to override the network provider list', 'two-factor' );
		echo '</label></p>';
		echo '<p class="description">' . esc_html__( 'When enabled, a subsite can only narrow the network list. Subsites cannot enable providers that are disabled here.', 'two-factor' ) . '</p>';
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Save Settings', 'two-factor' ), 'primary', 'two_factor_network_settings_submit' );
		echo '</form>';

		echo '</div>';
	}
}
