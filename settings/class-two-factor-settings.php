<?php
/**
 * Admin settings UI for the Two-Factor plugin.
 * Provides a site-wide settings screen for disabling individual Two-Factor providers
 * and for turning on encrypted storage of authenticator app secrets.
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

			$posted = isset( $_POST['two_factor_enabled_providers'] ) && is_array( $_POST['two_factor_enabled_providers'] ) ? wp_unslash( $_POST['two_factor_enabled_providers'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; array values sanitized immediately below.

			// Sanitize posted values immediately.
			$posted = array_map( 'sanitize_text_field', (array) $posted );
			// Remove empty values.
			$enabled = array_values(
				array_filter(
					$posted,
					static function ( $value ) {
						return '' !== $value;
					}
				)
			);

			update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array_values( array_unique( $enabled ) ) );

			$secrets_saved = self::save_secrets_storage_setting();

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'two-factor' ) . '</p></div>';

			if ( ! $secrets_saved ) {
				echo '<div class="error"><p>' . esc_html__( 'Encrypted storage was not turned on. Confirm that you understand how it depends on this site\'s secrets key, then save again.', 'two-factor' ) . '</p></div>';
			}
		}

		// Build provider list for display using public core API.
		$provider_instances = Two_Factor_Core::get_providers();
		if ( ! is_array( $provider_instances ) ) {
			$provider_instances = array();
		}

		// Default to all providers enabled when the option has never been saved.
		$all_provider_keys = array_keys( $provider_instances );
		$saved_enabled     = get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, $all_provider_keys );

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
				echo '<input type="checkbox" name="two_factor_enabled_providers[]" id="provider_' . esc_attr( $provider_key ) . '" value="' . esc_attr( $provider_key ) . '" ' . checked( in_array( $provider_key, (array) $saved_enabled, true ), true, false ) . '> ';
				echo esc_html( $label );
				echo '</label></p>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</fieldset>';

		self::render_secrets_storage_section();

		submit_button( __( 'Save Settings', 'two-factor' ), 'primary', 'two_factor_settings_submit' );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Whether the current user may change the Secrets API storage setting.
	 *
	 * Secrets are network scoped, so on multisite the choice belongs to the network.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	private static function current_user_can_manage_secrets_storage() {
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * Save the Secrets API storage setting from the submitted settings form.
	 *
	 * Turning storage on requires the acknowledgement checkbox. The caller has
	 * already verified the nonce.
	 *
	 * @since 0.18.0
	 *
	 * @return bool False when turning storage on was refused for want of the acknowledgement.
	 */
	private static function save_secrets_storage_setting() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by render_settings_page() before this is called.
		// The marker field is only rendered with an editable checkbox, so an absent checkbox means "off" only when it is present.
		if ( ! isset( $_POST['two_factor_secrets_api_setting'] ) || ! self::current_user_can_manage_secrets_storage() ) {
			return true;
		}

		$wanted       = ! empty( $_POST['two_factor_secrets_api_enabled'] );
		$acknowledged = ! empty( $_POST['two_factor_secrets_api_acknowledged'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( Two_Factor_Secrets::is_opted_in() === $wanted ) {
			return true;
		}

		if ( $wanted && ! $acknowledged ) {
			return false;
		}

		Two_Factor_Secrets::set_opted_in( $wanted );

		return true;
	}

	/**
	 * Render the section where an administrator turns Secrets API storage on.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	private static function render_secrets_storage_section() {
		$opted_in = Two_Factor_Secrets::is_opted_in();

		echo '<h2>' . esc_html__( 'Authenticator App Secret Storage', 'two-factor' ) . '</h2>';

		if ( ! Two_Factor_Secrets::is_api_present() ) {
			echo '<p class="description">' . esc_html__( 'Authenticator app secrets are stored in user meta. When the WordPress Secrets API is available on this site, you can choose to store them encrypted instead.', 'two-factor' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'By default, authenticator app secrets are stored in user meta, where anyone with a copy of the database can read them. The WordPress Secrets API can store them encrypted instead.', 'two-factor' ) . '</p>';

		$can_manage = self::current_user_can_manage_secrets_storage();

		echo '<fieldset class="two-factor-secrets-storage"><legend class="screen-reader-text">' . esc_html__( 'Authenticator app secret storage', 'two-factor' ) . '</legend>';

		if ( $can_manage ) {
			echo '<input type="hidden" name="two_factor_secrets_api_setting" value="1">';
		}

		echo '<p><label for="two_factor_secrets_api_enabled">';
		echo '<input type="checkbox" name="two_factor_secrets_api_enabled" id="two_factor_secrets_api_enabled" value="1" ' . checked( $opted_in, true, false ) . ' ' . disabled( $can_manage, false, false ) . '> ';
		echo esc_html__( 'Store authenticator app secrets encrypted with the WordPress Secrets API', 'two-factor' );
		echo '</label></p>';

		if ( ! $can_manage ) {
			echo '<p class="description">' . esc_html__( 'Secrets are stored for the whole network, so only a network administrator can change this setting.', 'two-factor' ) . '</p>';
		} elseif ( ! $opted_in ) {
			echo '<ul class="ul-disc">';
			echo '<li>' . esc_html__( 'This is a one-way change. Existing secrets move to the Secrets API as users log in, and are never moved back into user meta.', 'two-factor' ) . '</li>';
			echo '<li>' . esc_html__( 'Stored secrets can only be read with this site\'s secrets key: the WP_SECRETS_KEY constant, or a key derived from your salts when it is not defined. If that key changes outside the Secrets API\'s own rotation, for example when a plugin regenerates your salts, nobody can use or set up an authenticator app until the original key is restored.', 'two-factor' ) . '</li>';
			echo '<li>' . esc_html__( 'If the Secrets API is later removed, affected users cannot use their authenticator app until it is restored or an administrator resets their authenticator app.', 'two-factor' ) . '</li>';
			echo '</ul>';

			echo '<p><label for="two_factor_secrets_api_acknowledged">';
			echo '<input type="checkbox" name="two_factor_secrets_api_acknowledged" id="two_factor_secrets_api_acknowledged" value="1"> ';
			echo esc_html__( 'I understand that changing or losing this site\'s secrets key makes stored authenticator app secrets unreadable.', 'two-factor' );
			echo '</label></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Turning this off stops new secrets from being stored with the Secrets API. Secrets that are already stored there stay there and are still used.', 'two-factor' ) . '</p>';
		}

		if ( $opted_in && ! Two_Factor_Secrets::is_enabled() ) {
			echo '<p class="description">' . esc_html__( 'The two_factor_use_secrets_api filter is currently turning this off.', 'two-factor' ) . '</p>';
		} elseif ( ! $opted_in && Two_Factor_Secrets::is_enabled() ) {
			echo '<p class="description">' . esc_html__( 'The two_factor_use_secrets_api filter is currently turning this on, whatever is selected here.', 'two-factor' ) . '</p>';
		}

		echo '</fieldset>';
	}
}
