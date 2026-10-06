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
 * When the Secrets API is absent, what a method returns depends on what it is
 * for. Predicates (the `is_*()` methods and `can_write()`) answer a yes/no
 * question and return false. Reading or writing a secret returns a WP_Error, so
 * the caller knows why it failed and can fail closed; a user with no marker has
 * nothing to read and gets null either way. Deleting clears the marker and
 * returns true, leaving any stored secret behind.
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
	 * Network option recording that an administrator turned Secrets API storage on.
	 *
	 * A network option because secrets are network scoped: one site of a network
	 * cannot opt in on its own.
	 *
	 * @since 0.18.0
	 *
	 * @var string
	 */
	const OPT_IN_OPTION_KEY = 'two_factor_secrets_api_enabled';

	/**
	 * Whether the Secrets API is available right now.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function is_api_present(): bool {
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
	 * Whether an error means the Secrets API cannot use its encryption key.
	 *
	 * The Secrets API wraps a single root key with the site key (WP_SECRETS_KEY, or one
	 * derived from the salts). When the site key changes without the API's own rotation,
	 * the root key cannot be unwrapped and every read and write fails, including writes
	 * of brand-new secrets, until the original key is restored.
	 *
	 * @since 0.18.0
	 *
	 * @param mixed $error Value to check.
	 *
	 * @return bool
	 */
	public static function is_key_unavailable_error( $error ): bool {
		$code = defined( 'WP_SECRETS_ERROR_KEY_UNAVAILABLE' ) ? WP_SECRETS_ERROR_KEY_UNAVAILABLE : 'secret_key_unavailable';

		return is_wp_error( $error ) && $code === $error->get_error_code();
	}

	/**
	 * Whether the active secrets provider accepts writes.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function is_provider_writable(): bool {
		if ( ! self::is_api_present() ) {
			return false;
		}

		if ( isset( self::$test_overrides['writable'] ) ) {
			return (bool) call_user_func( self::$test_overrides['writable'] );
		}

		return (bool) wp_secrets_provider_is_writable();
	}

	/**
	 * Whether an administrator has turned Secrets API storage on.
	 *
	 * Storage is opt-in: moving a secret into the Secrets API is one-directional, and
	 * a changed encryption key makes every stored secret unreadable, so an administrator
	 * has to acknowledge that before anything is written.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function is_opted_in(): bool {
		return '1' === (string) get_site_option( self::OPT_IN_OPTION_KEY, '' );
	}

	/**
	 * Record whether an administrator has turned Secrets API storage on.
	 *
	 * Turning it off stops new writes and migration only. Secrets that were already
	 * stored stay in the Secrets API and are still read from it.
	 *
	 * @since 0.18.0
	 *
	 * @param bool $opted_in Whether Secrets API storage is turned on.
	 * @return void
	 */
	public static function set_opted_in( bool $opted_in ): void {
		if ( $opted_in ) {
			update_site_option( self::OPT_IN_OPTION_KEY, '1' );
			return;
		}

		delete_site_option( self::OPT_IN_OPTION_KEY );
	}

	/**
	 * Whether Two-Factor should write secrets to the Secrets API.
	 *
	 * This is the administrator's opt-in, as adjusted by the filter. It says nothing
	 * about whether the API is present or writable; see can_write() for that.
	 *
	 * @since 0.18.0
	 *
	 * @param int $user_id User ID; may be 0 when no user is in context.
	 * @return bool
	 */
	public static function is_enabled( int $user_id = 0 ): bool {
		/**
		 * Filters whether Two-Factor writes secrets to the Secrets API.
		 *
		 * This controls writes and migration only. Users whose secret was already
		 * migrated are still read from the Secrets API while it is present.
		 *
		 * Returning true turns storage on without the administrator opting in on the
		 * settings screen, so only do that where the encryption key is managed for
		 * the site.
		 *
		 * @since 0.18.0
		 *
		 * @param bool $enabled Whether to use the Secrets API. Defaults to the administrator's
		 *                      opt-in on the settings screen, which is off until it is saved.
		 * @param int  $user_id User ID, which may be 0 when no user is in context.
		 */
		return (bool) apply_filters( 'two_factor_use_secrets_api', self::is_opted_in(), $user_id );
	}

	/**
	 * Whether new secrets may be written to the Secrets API.
	 *
	 * @since 0.18.0
	 *
	 * @param int $user_id User ID; may be 0 when no user is in context.
	 * @return bool
	 */
	public static function can_write( int $user_id = 0 ): bool {
		if ( ! self::is_api_present() ) {
			return false;
		}

		if ( ! self::is_enabled( $user_id ) ) {
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
	public static function get_secret_name( int $user_id, string $slug ): string {
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
	public static function get_marker_meta_key( string $slug ): string {
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
	public static function get_user_secret( int $user_id, string $slug ) {
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
	public static function set_user_secret( int $user_id, string $slug, string $value ) {
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
	public static function delete_user_secret( int $user_id, string $slug ) {
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
	public static function provider_label(): string {
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
	public static function memzero( &$value ): void {
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
	private static function assert_slug( string $slug ): void {
		if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $slug ) ) {
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
	private static function api_set( string $name, string $value ) {
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
	private static function api_get( string $name ) {
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
	private static function api_delete( string $name ) {
		if ( isset( self::$test_overrides['delete'] ) ) {
			return call_user_func( self::$test_overrides['delete'], $name );
		}

		return wp_delete_network_secret( $name );
	}
}
