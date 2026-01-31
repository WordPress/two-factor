<?php
class Two_Factor_Settings {

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle save.
		if ( isset( $_POST['two_factor_settings_submit'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'two-factor' ) );
			}
			check_admin_referer( 'two_factor_save_settings', 'two_factor_settings_nonce' );

			$posted = isset( $_POST['two_factor_disabled_providers'] ) && is_array( $_POST['two_factor_disabled_providers'] ) ? wp_unslash( $_POST['two_factor_disabled_providers'] ) : array();

			// Sanitize posted values immediately.
			$posted = array_map( 'sanitize_text_field', (array) $posted );
			// Remove empty values.
			$disabled = array_values( array_filter( $posted, 'strlen' ) );

			if ( ! empty( $disabled ) ) {
				update_option( 'two_factor_disabled_providers', array_values( array_unique( $disabled ) ) );
			} else {
				// Empty means none disabled (all allowed).
				delete_option( 'two_factor_disabled_providers' );
			}

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'two-factor' ) . '</p></div>';
		}

		// Build provider list for display using reflection (safe for private methods).
		$default_providers = self::get_core_default_providers();
		$all_providers = apply_filters( 'two_factor_providers', $default_providers );

		$provider_instances = array();
		foreach ( $all_providers as $provider_key => $provider_path ) {
			if ( ! empty( $provider_path ) && is_readable( $provider_path ) ) {
				require_once $provider_path;
			}

			$class = $provider_key;
			/** This filter mirrors core behavior for dynamic classname filters. */
			$class = apply_filters( "two_factor_provider_classname_{$provider_key}", $class, $provider_path );

			if ( class_exists( $class ) ) {
				try {
					$provider_instances[ $provider_key ] = call_user_func( array( $class, 'get_instance' ) );
				} catch ( Exception $e ) {
					// Skip providers that fail to instantiate.
				}
			}
		}

		$saved_disabled = get_option( 'two_factor_disabled_providers', array() );

		echo '<div class="wrap two-factor-settings">';
		echo '<h1>' . esc_html__( 'Two-Factor Settings', 'two-factor' ) . '</h1>';
		echo '<h2>' . esc_html__( 'Disable Providers', 'two-factor' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Disable any Two-Factor providers you do not want available on this site. By default all providers are available.', 'two-factor' ) . '</p>';
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
				echo '<input type="checkbox" name="two_factor_disabled_providers[]" id="provider_' . esc_attr( $provider_key ) . '" value="' . esc_attr( $provider_key ) . '" ' . checked( in_array( $provider_key, (array) $saved_disabled, true ), true, false ) . ' /> ';
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

	private static function get_core_default_providers() {
		$default_providers = array();
		if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'get_default_providers' ) ) {
			try {
				$rm = new ReflectionMethod( 'Two_Factor_Core', 'get_default_providers' );
				if ( ! $rm->isPublic() ) {
					$rm->setAccessible( true );
				}
				if ( $rm->isStatic() ) {
					$default_providers = $rm->invoke( null );
				} else {
					$instance = null;
					if ( method_exists( 'Two_Factor_Core', 'get_instance' ) ) {
						$instance = call_user_func( array( 'Two_Factor_Core', 'get_instance' ) );
					}
					if ( $instance ) {
						$default_providers = $rm->invoke( $instance );
					}
				}
			} catch ( Throwable $t ) {
				$default_providers = array();
			}
		}
		return is_array( $default_providers ) ? $default_providers : array();
	}
}
