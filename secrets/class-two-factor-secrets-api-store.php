<?php
/**
 * Secrets store backed by the WordPress Secrets API.
 *
 * @package Two_Factor
 */

/**
 * The only place Two-Factor calls the WordPress Secrets API.
 *
 * Secrets are always network scoped, so the same code path serves single site
 * and multisite installs.
 *
 * @since 0.18.0
 */
class Two_Factor_Secrets_Api_Store implements Two_Factor_Secrets_Store {

	/**
	 * Whether the Secrets API is loaded.
	 *
	 * Checked on every call rather than once, because the feature plugin can be
	 * deactivated while Two-Factor stays active.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'wp_get_network_secret' )
			&& function_exists( 'wp_set_network_secret' )
			&& function_exists( 'wp_delete_network_secret' )
			&& function_exists( 'wp_secrets_provider_is_writable' )
			&& function_exists( 'wp_secrets_provider_label' )
			&& function_exists( 'wp_secrets_memzero' )
			&& class_exists( 'WP_Secret' );
	}

	/**
	 * Whether the active secrets provider accepts writes.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public function is_writable(): bool {
		return (bool) wp_secrets_provider_is_writable();
	}

	/**
	 * Label of the active secrets provider.
	 *
	 * @since 0.18.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return (string) wp_secrets_provider_label();
	}

	/**
	 * Read and reveal a secret.
	 *
	 * @since 0.18.0
	 *
	 * @param string $name Secret name.
	 * @return string|null|WP_Error
	 */
	public function get( string $name ) {
		$secret = wp_get_network_secret( $name );

		if ( is_wp_error( $secret ) || null === $secret ) {
			return $secret;
		}

		return $secret->reveal();
	}

	/**
	 * Write a secret.
	 *
	 * @since 0.18.0
	 *
	 * @param string $name  Secret name.
	 * @param string $value Secret value.
	 * @return true|WP_Error
	 */
	public function set( string $name, string $value ) {
		return wp_set_network_secret( $name, $value );
	}

	/**
	 * Delete a secret.
	 *
	 * @since 0.18.0
	 *
	 * @param string $name Secret name.
	 * @return true|WP_Error
	 */
	public function delete( string $name ) {
		return wp_delete_network_secret( $name );
	}
}
