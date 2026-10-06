<?php
/**
 * Static entry point for storing secrets with the WordPress Secrets API.
 *
 * @package Two_Factor
 */

/**
 * What the rest of the plugin calls to store a secret.
 *
 * The logic lives in Two_Factor_Secrets_Manager and the calls to the Secrets API
 * in Two_Factor_Secrets_Api_Store. This class only forwards to a shared manager,
 * because the rest of the plugin is static. See the manager for what each method
 * returns when the Secrets API is absent.
 *
 * @since 0.18.0
 */
class Two_Factor_Secrets {

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
	 * The shared manager.
	 *
	 * @since 0.18.0
	 *
	 * @var Two_Factor_Secrets_Manager|null
	 */
	private static $instance = null;

	/**
	 * Get the shared manager, building one over the Secrets API on first use.
	 *
	 * @since 0.18.0
	 *
	 * @return Two_Factor_Secrets_Manager
	 */
	public static function instance(): Two_Factor_Secrets_Manager {
		if ( null === self::$instance ) {
			self::$instance = new Two_Factor_Secrets_Manager( new Two_Factor_Secrets_Api_Store() );
		}

		return self::$instance;
	}

	/**
	 * Replace the shared manager.
	 *
	 * @internal For tests, which pass a manager built over an in-memory store.
	 *
	 * @since 0.18.0
	 *
	 * @param Two_Factor_Secrets_Manager $instance The manager to use.
	 * @return void
	 */
	public static function set_instance( Two_Factor_Secrets_Manager $instance ): void {
		self::$instance = $instance;
	}

	/**
	 * Forget the shared manager, so the next call builds one over the Secrets API.
	 *
	 * @internal For tests.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Whether the store is available right now.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function is_api_present(): bool {
		return self::instance()->is_api_present();
	}

	/**
	 * Whether the store accepts writes.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function is_provider_writable(): bool {
		return self::instance()->is_provider_writable();
	}

	/**
	 * Label of whatever backs the store, or an empty string when it is unavailable.
	 *
	 * @since 0.18.0
	 *
	 * @return string
	 */
	public static function provider_label(): string {
		return self::instance()->provider_label();
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
		return self::instance()->is_opted_in();
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
		self::instance()->set_opted_in( $opted_in );
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
		return self::instance()->is_enabled( $user_id );
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
		return self::instance()->can_write( $user_id );
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
		return self::instance()->get_secret_name( $user_id, $slug );
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
		return self::instance()->get_marker_meta_key( $slug );
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
		return self::instance()->get_user_secret( $user_id, $slug );
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
		return self::instance()->set_user_secret( $user_id, $slug, $value );
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
		return self::instance()->delete_user_secret( $user_id, $slug );
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
}
