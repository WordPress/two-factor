<?php
/**
 * Storage adapter for the WordPress Secrets API.
 *
 * @package Two_Factor
 */

/**
 * The only place Two-Factor talks to the Secrets API.
 *
 * Secrets are always network scoped, so the same code path serves single site
 * and multisite installs. A per-user marker in user meta records which network
 * holds the secret; the marker is what makes "not yet migrated" distinguishable
 * from "migrated but unreadable".
 *
 * @since 0.18.0
 */
class Two_Factor_Secrets {

	/**
	 * Test seam overrides.
	 *
	 * @internal Test seam only; keys set, get, delete, writable.
	 *
	 * @since 0.18.0
	 *
	 * @var array<string, callable>
	 */
	public static $test_overrides = array();

	/**
	 * Whether the Secrets API is available right now.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function is_api_present() {
		$present = function_exists( 'wp_get_network_secret' )
			&& function_exists( 'wp_set_network_secret' )
			&& function_exists( 'wp_delete_network_secret' )
			&& function_exists( 'wp_secrets_provider_is_writable' )
			&& function_exists( 'wp_secrets_provider_label' )
			&& function_exists( 'wp_secrets_memzero' )
			&& class_exists( 'WP_Secret' );

		/**
		 * Filters whether the Secrets API is treated as present.
		 *
		 * @internal Test seam only; not a public extension point. Can only force false.
		 *
		 * @param bool $present Whether the API is present.
		 */
		$filtered = (bool) apply_filters( 'two_factor_secrets_api_present', true );

		return $present && $filtered;
	}

	/**
	 * Whether the active secrets provider accepts writes.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function is_provider_writable() {
		if ( ! self::is_api_present() ) {
			return false;
		}

		if ( isset( self::$test_overrides['writable'] ) ) {
			return (bool) call_user_func( self::$test_overrides['writable'] );
		}

		return (bool) wp_secrets_provider_is_writable();
	}

	/**
	 * Whether new secrets may be written to the Secrets API.
	 *
	 * @since 0.18.0
	 *
	 * @param int $user_id User ID; may be 0 when no user is in context.
	 * @return bool
	 */
	public static function can_write( $user_id = 0 ) {
		if ( ! self::is_api_present() ) {
			return false;
		}

		/**
		 * Filters whether Two-Factor writes secrets to the Secrets API.
		 *
		 * This controls writes and migration only. Users whose secret was already
		 * migrated are still read from the Secrets API while it is present.
		 *
		 * @since 0.18.0
		 *
		 * @param bool $enabled Whether to use the Secrets API. Default true.
		 * @param int  $user_id User ID, which may be 0 when no user is in context.
		 */
		if ( ! (bool) apply_filters( 'two_factor_use_secrets_api', true, (int) $user_id ) ) {
			return false;
		}

		return self::is_provider_writable();
	}

	/**
	 * Build the secret name for a user.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $slug    Secret slug, such as "totp".
	 * @return string
	 *
	 * @throws InvalidArgumentException When the slug is invalid.
	 */
	public static function get_secret_name( $user_id, $slug ) {
		self::assert_slug( $slug );

		return "two-factor/{$slug}-{$user_id}";
	}

	/**
	 * Get the user meta key holding the network marker.
	 *
	 * @since 0.18.0
	 *
	 * @param string $slug Secret slug.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the slug is invalid.
	 */
	public static function get_marker_meta_key( $slug ) {
		self::assert_slug( $slug );

		return "_two_factor_{$slug}_key_network";
	}

	/**
	 * Read a user's secret.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $slug    Secret slug.
	 * @return string|null|WP_Error Null when no secret has been migrated for the user.
	 */
	public static function get_user_secret( $user_id, $slug ) {
		$marker = get_user_meta( $user_id, self::get_marker_meta_key( $slug ), true );

		if ( '' === $marker || false === $marker || null === $marker ) {
			return null;
		}

		if ( ! self::is_api_present() ) {
			return new WP_Error(
				'two_factor_secrets_api_missing',
				__( 'The Secrets API is not available, so this secret cannot be read.', 'two-factor' )
			);
		}

		if ( (int) get_current_network_id() !== (int) $marker ) {
			return new WP_Error(
				'two_factor_secret_wrong_network',
				__( 'This secret is stored for a different network.', 'two-factor' )
			);
		}

		$value = self::api_get( self::get_secret_name( $user_id, $slug ) );

		if ( null === $value ) {
			return new WP_Error(
				'two_factor_secret_missing',
				__( 'The stored secret could not be found.', 'two-factor' )
			);
		}

		return $value;
	}

	/**
	 * Store a user's secret.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $slug    Secret slug.
	 * @param string $value   Secret value.
	 * @return true|WP_Error
	 */
	public static function set_user_secret( $user_id, $slug, $value ) {
		if ( ! self::is_api_present() ) {
			return new WP_Error(
				'two_factor_secrets_api_missing',
				__( 'The Secrets API is not available, so this secret cannot be stored.', 'two-factor' )
			);
		}

		$result = self::api_set( self::get_secret_name( $user_id, $slug ), $value );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_user_meta( $user_id, self::get_marker_meta_key( $slug ), (string) get_current_network_id() );

		return true;
	}

	/**
	 * Delete a user's secret and its marker.
	 *
	 * A missing secret counts as success.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $slug    Secret slug.
	 * @return true|WP_Error
	 */
	public static function delete_user_secret( $user_id, $slug ) {
		$error = null;

		if ( self::is_api_present() ) {
			$result = self::api_delete( self::get_secret_name( $user_id, $slug ) );
			if ( is_wp_error( $result ) ) {
				$error = $result;
			}
		}

		delete_user_meta( $user_id, self::get_marker_meta_key( $slug ) );

		return $error ? $error : true;
	}

	/**
	 * Label of the active secrets provider.
	 *
	 * @since 0.18.0
	 *
	 * @return string
	 */
	public static function provider_label() {
		if ( self::is_api_present() ) {
			return (string) wp_secrets_provider_label();
		}

		return '';
	}

	/**
	 * Wipe a value from memory where possible.
	 *
	 * @since 0.18.0
	 *
	 * @param string $value Value to wipe.
	 * @return void
	 */
	public static function memzero( &$value ) {
		if ( function_exists( 'wp_secrets_memzero' ) ) {
			wp_secrets_memzero( $value );
			return;
		}

		$value = '';
	}

	/**
	 * Validate a slug.
	 *
	 * @since 0.18.0
	 *
	 * @param string $slug Secret slug.
	 * @return void
	 *
	 * @throws InvalidArgumentException When the slug is invalid.
	 */
	private static function assert_slug( $slug ) {
		if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $slug ) ) {
			throw new InvalidArgumentException( 'Invalid secret slug.' );
		}
	}

	/**
	 * Write to the Secrets API.
	 *
	 * @since 0.18.0
	 *
	 * @param string $name  Secret name.
	 * @param string $value Secret value.
	 * @return true|WP_Error
	 */
	private static function api_set( $name, $value ) {
		if ( isset( self::$test_overrides['set'] ) ) {
			return call_user_func( self::$test_overrides['set'], $name, $value );
		}

		return wp_set_network_secret( $name, $value );
	}

	/**
	 * Read and reveal from the Secrets API.
	 *
	 * @since 0.18.0
	 *
	 * @param string $name Secret name.
	 * @return string|null|WP_Error
	 */
	private static function api_get( $name ) {
		if ( isset( self::$test_overrides['get'] ) ) {
			return call_user_func( self::$test_overrides['get'], $name );
		}

		$secret = wp_get_network_secret( $name );

		if ( is_wp_error( $secret ) || null === $secret ) {
			return $secret;
		}

		return $secret->reveal();
	}

	/**
	 * Delete from the Secrets API.
	 *
	 * @since 0.18.0
	 *
	 * @param string $name Secret name.
	 * @return true|WP_Error
	 */
	private static function api_delete( $name ) {
		if ( isset( self::$test_overrides['delete'] ) ) {
			return call_user_func( self::$test_overrides['delete'], $name );
		}

		return wp_delete_network_secret( $name );
	}
}
