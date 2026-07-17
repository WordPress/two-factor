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

		// Build provider list for display and validation using public core API.
		$provider_instances = array();
		if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'get_providers' ) ) {
			$provider_instances = Two_Factor_Core::get_providers();
			if ( ! is_array( $provider_instances ) ) {
				$provider_instances = array();
			}
		}
		$all_provider_keys = array_keys( $provider_instances );

		// Determine whether the site is operating under a network-level policy.
		$network_enabled  = null;
		$network_override = false;
		if ( function_exists( 'two_factor_is_network_mode' ) && two_factor_is_network_mode() ) {
			$network_enabled = get_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, null );
			if ( null !== $network_enabled ) {
				$network_override = (bool) get_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, false );
			}
		}
		$network_managed = null !== $network_enabled && ! $network_override;

		// Handle save. Site-level values are only meaningful when the network
		// allows subsite overrides or when the network has not configured a list.
		if ( ! $network_managed && isset( $_POST['two_factor_settings_submit'] ) ) {
			check_admin_referer( 'two_factor_save_settings', 'two_factor_settings_nonce' );

			$posted = isset( $_POST['two_factor_enabled_providers'] ) && is_array( $_POST['two_factor_enabled_providers'] ) ? wp_unslash( $_POST['two_factor_enabled_providers'] ) : array();

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

			// When the network allows overrides, a subsite cannot expand beyond the network list.
			if ( $network_override && is_array( $network_enabled ) ) {
				$enabled = array_values( array_intersect( $enabled, $network_enabled ) );
			}

			update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, $enabled );

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'two-factor' ) . '</p></div>';
		}

		// Default to all providers enabled when the site option has never been saved.
		$saved_enabled = get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, $all_provider_keys );
		if ( $network_managed ) {
			// Managed by network: show the network list, regardless of any stale site option.
			$saved_enabled = $network_enabled;
		} elseif ( $network_override && is_array( $network_enabled ) ) {
			// Override allowed: show the effective intersection so disabled providers are unchecked.
			$saved_enabled = array_values( array_intersect( (array) $saved_enabled, $network_enabled ) );
		}

		echo '<div class="wrap two-factor-settings">';
		echo '<h1>' . esc_html__( 'Two-Factor Settings', 'two-factor' ) . '</h1>';
		echo '<h2>' . esc_html__( 'Enabled Providers', 'two-factor' ) . '</h2>';

		if ( $network_managed ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Provider settings are managed at the network level.', 'two-factor' ) . '</p></div>';
			echo '<p class="description">' . esc_html__( 'The network administrator has chosen the providers available on this site.', 'two-factor' ) . '</p>';
		} elseif ( $network_override ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The network has enabled the following providers. This site can only narrow the list.', 'two-factor' ) . '</p></div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Choose which Two-Factor providers are available on this site. All providers are enabled by default.', 'two-factor' ) . '</p>';
		}

		if ( ! $network_managed ) {
			echo '<form method="post" action="">';
			wp_nonce_field( 'two_factor_save_settings', 'two_factor_settings_nonce' );
		}

		echo '<fieldset class="two-factor-providers"><legend class="screen-reader-text">' . esc_html__( 'Providers', 'two-factor' ) . '</legend>';
		echo '<table class="form-table"><tbody>';

		if ( empty( $provider_instances ) ) {
			echo '<tr><td>' . esc_html__( 'No providers found.', 'two-factor' ) . '</td></tr>';
		} else {
			// Render a compact stacked list of provider checkboxes below the title/description.
			echo '<tr>';
			echo '<td>';
			foreach ( $provider_instances as $provider_key => $instance ) {
				$label         = method_exists( $instance, 'get_label' ) ? $instance->get_label() : $provider_key;
				$is_in_network = is_array( $network_enabled ) && in_array( $provider_key, $network_enabled, true );
				$disabled      = $network_managed || ( $network_override && ! $is_in_network );

				echo '<p class="provider-item"><label for="provider_' . esc_attr( $provider_key ) . '">';
				echo '<input type="checkbox" ' . ( $network_managed ? '' : 'name="two_factor_enabled_providers[]" ' ) . 'id="provider_' . esc_attr( $provider_key ) . '" value="' . esc_attr( $provider_key ) . '" ' . checked( in_array( $provider_key, (array) $saved_enabled, true ), true, false ) . ( $disabled ? ' disabled="disabled"' : '' ) . ' /> ';
				echo esc_html( $label );
				echo '</label></p>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</fieldset>';

		if ( ! $network_managed ) {
			submit_button( __( 'Save Settings', 'two-factor' ), 'primary', 'two_factor_settings_submit' );
			echo '</form>';
		}

		echo '</div>';
	}
}
