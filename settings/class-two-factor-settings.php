<?php
/**
 * Admin settings UI for the Two-Factor plugin.
 * Provides a site-wide (network-wide on multisite) settings screen for disabling individual Two-Factor providers.
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
	 * The network admin `edit.php` action that saves the settings on multisite.
	 *
	 * @since 0.18.0
	 *
	 * @var string
	 */
	const NETWORK_SAVE_ACTION = 'two_factor_settings';

	/**
	 * Render the settings page.
	 * Also handles saving of settings when the form is submitted on single site installs;
	 * on multisite the form is saved by save_network_settings().
	 *
	 * @since 0.16
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( Two_Factor_Core::policy_capability() ) ) {
			return;
		}

		// Handle save.
		if ( ! is_multisite() && isset( $_POST['two_factor_settings_submit'] ) ) {
			check_admin_referer( 'two_factor_save_settings', 'two_factor_settings_nonce' );

			Two_Factor_Core::update_enabled_providers_policy( self::get_posted_providers() );

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'two-factor' ) . '</p></div>';
		}

		// Saved by save_network_settings(), which redirects back here.
		if ( is_multisite() ) {
			$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only displays a notice; no state change occurs here.

			if ( 'no_providers' === $error ) {
				echo '<div class="error"><p>' . esc_html__( 'At least one provider must be enabled. No changes were saved.', 'two-factor' ) . '</p></div>';
			} elseif ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only displays a notice; no state change occurs here.
				echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'two-factor' ) . '</p></div>';
			}
		}

		// Build provider list for display using public core API.
		$provider_instances = Two_Factor_Core::get_providers();
		if ( ! is_array( $provider_instances ) ) {
			$provider_instances = array();
		}

		// Default to all providers enabled when the option has never been saved.
		$all_provider_keys = array_keys( $provider_instances );
		$saved_enabled     = Two_Factor_Core::get_enabled_providers_policy();
		if ( null === $saved_enabled ) {
			$saved_enabled = $all_provider_keys;
		}

		if ( is_multisite() ) {
			$description = __( 'Choose which Two-Factor providers are available on all sites in this network. All providers are enabled by default.', 'two-factor' );
			$form_action = network_admin_url( 'edit.php?action=' . self::NETWORK_SAVE_ACTION );
		} else {
			$description = __( 'Choose which Two-Factor providers are available on this site. All providers are enabled by default.', 'two-factor' );
			$form_action = '';
		}

		echo '<div class="wrap two-factor-settings">';
		echo '<h1>' . esc_html__( 'Two-Factor Settings', 'two-factor' ) . '</h1>';
		echo '<h2>' . esc_html__( 'Enabled Providers', 'two-factor' ) . '</h2>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
		echo '<form method="post" action="' . esc_url( $form_action ) . '">';
		wp_nonce_field( 'two_factor_save_settings', 'two_factor_settings_nonce' );

		self::render_providers_fieldset( $provider_instances, $saved_enabled, false );

		submit_button( __( 'Save Settings', 'two-factor' ), 'primary', 'two_factor_settings_submit' );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render the settings read-only, for the sites of a multisite network.
	 *
	 * On multisite the settings apply to the whole network and can only be changed in the network admin.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function render_network_managed_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$provider_instances = self::get_all_providers();
		$enabled            = Two_Factor_Core::get_enabled_providers_policy();
		if ( null === $enabled ) {
			$enabled = array_keys( $provider_instances );
		}

		echo '<div class="wrap two-factor-settings">';
		echo '<h1>' . esc_html__( 'Two-Factor Settings', 'two-factor' ) . '</h1>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The Two-Factor providers are managed for all sites in this network by the network administrators.', 'two-factor' ) . '</p></div>';

		if ( current_user_can( Two_Factor_Core::policy_capability() ) ) {
			echo '<p><a href="' . esc_url( network_admin_url( 'settings.php?page=two-factor-settings' ) ) . '">' . esc_html__( 'Change the network settings', 'two-factor' ) . '</a></p>';
		}

		echo '<h2>' . esc_html__( 'Enabled Providers', 'two-factor' ) . '</h2>';

		self::render_providers_fieldset( $provider_instances, $enabled, true );

		echo '</div>';
	}

	/**
	 * Render the provider checkboxes.
	 *
	 * @since 0.18.0
	 *
	 * @param Two_Factor_Provider[] $provider_instances Providers to list, indexed by provider key.
	 * @param array                 $enabled            Keys of the enabled providers.
	 * @param bool                  $read_only          Whether to render disabled checkboxes that aren't submitted.
	 * @return void
	 */
	private static function render_providers_fieldset( $provider_instances, $enabled, $read_only ) {
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
				echo '<input type="checkbox" ' . ( $read_only ? '' : 'name="two_factor_enabled_providers[]" ' ) . 'id="provider_' . esc_attr( $provider_key ) . '" value="' . esc_attr( $provider_key ) . '" ' . checked( in_array( $provider_key, (array) $enabled, true ), true, false ) . disabled( $read_only, true, false ) . '> ';
				echo esc_html( $label );
				echo '</label></p>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</fieldset>';
	}

	/**
	 * Save the settings submitted from the network admin, then redirect back to the settings page.
	 *
	 * Runs on the `network_admin_edit_two_factor_settings` action.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function save_network_settings() {
		if ( ! current_user_can( Two_Factor_Core::policy_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'two-factor' ), 403 );
		}

		check_admin_referer( 'two_factor_save_settings', 'two_factor_settings_nonce' );

		// Only keep registered providers, including those the current settings disable.
		$enabled  = array_values( array_intersect( self::get_posted_providers(), array_keys( self::get_all_providers() ) ) );
		$redirect = array( 'page' => 'two-factor-settings' );

		// Without any provider, every user with two-factor enabled would be locked out of the network.
		if ( empty( $enabled ) ) {
			$redirect['error'] = 'no_providers';
		} else {
			Two_Factor_Core::update_enabled_providers_policy( $enabled );
			$redirect['updated'] = 'true';
		}

		wp_safe_redirect( add_query_arg( $redirect, network_admin_url( 'settings.php' ) ) );
		exit;
	}

	/**
	 * Get all registered providers, including those disabled in the settings.
	 *
	 * @since 0.18.0
	 *
	 * @return Two_Factor_Provider[] Provider instances indexed by provider key.
	 */
	private static function get_all_providers() {
		$priority = has_filter( 'two_factor_providers', 'two_factor_filter_enabled_providers' );

		if ( false === $priority ) {
			return Two_Factor_Core::get_providers();
		}

		remove_filter( 'two_factor_providers', 'two_factor_filter_enabled_providers', (int) $priority );

		try {
			return Two_Factor_Core::get_providers();
		} finally {
			add_filter( 'two_factor_providers', 'two_factor_filter_enabled_providers', (int) $priority );
		}
	}

	/**
	 * Get the provider keys submitted with the settings form.
	 *
	 * The caller must verify the nonce first.
	 *
	 * @since 0.18.0
	 *
	 * @return string[]
	 */
	private static function get_posted_providers() {
		$posted = isset( $_POST['two_factor_enabled_providers'] ) && is_array( $_POST['two_factor_enabled_providers'] ) ? wp_unslash( $_POST['two_factor_enabled_providers'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by the caller; array values sanitized immediately below.

		// Sanitize posted values immediately.
		$posted = array_map( 'sanitize_text_field', (array) $posted );

		// Remove empty values.
		return array_values(
			array_filter(
				$posted,
				static function ( $value ) {
					return '' !== $value;
				}
			)
		);
	}
}
