<?php
/**
 * Decides whether, when and under what name Two-Factor stores a secret.
 *
 * @package Two_Factor
 */

/**
 * The logic behind Two_Factor_Secrets, with the store it writes to passed in.
 *
 * A per-user marker in user meta records which network holds the secret; the
 * marker is what makes "not yet migrated" distinguishable from "migrated but
 * unreadable".
 *
 * When the store is unavailable, what a method returns depends on what it is
 * for. Predicates (the `is_*()` methods and `can_write()`) answer a yes/no
 * question and return false. Reading or writing a secret returns a WP_Error, so
 * the caller knows why it failed and can fail closed; a user with no marker has
 * nothing to read and gets null either way. Deleting clears the marker and
 * returns true, leaving any stored secret behind.
 *
 * Plugin code calls the static Two_Factor_Secrets facade rather than holding
 * one of these.
 *
 * @since 0.18.0
 */
class Two_Factor_Secrets_Manager {

	/**
	 * Where secrets are kept.
	 *
	 * @since 0.18.0
	 *
	 * @var Two_Factor_Secrets_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @since 0.18.0
	 *
	 * @param Two_Factor_Secrets_Store $store Where secrets are kept.
	 */
	public function __construct( Two_Factor_Secrets_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Whether the store is available right now.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public function is_api_present(): bool {
		return $this->store->is_available();
	}

	/**
	 * Whether the store accepts writes.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public function is_provider_writable(): bool {
		return $this->store->is_available() && $this->store->is_writable();
	}

	/**
	 * Label of whatever backs the store, or an empty string when it is unavailable.
	 *
	 * @since 0.18.0
	 *
	 * @return string
	 */
	public function provider_label(): string {
		return $this->store->is_available() ? $this->store->get_label() : '';
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
	public function is_opted_in(): bool {
		return '1' === (string) get_site_option( Two_Factor_Secrets::OPT_IN_OPTION_KEY, '' );
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
	public function set_opted_in( bool $opted_in ): void {
		if ( $opted_in ) {
			update_site_option( Two_Factor_Secrets::OPT_IN_OPTION_KEY, '1' );
			return;
		}

		delete_site_option( Two_Factor_Secrets::OPT_IN_OPTION_KEY );
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
	public function is_enabled( int $user_id = 0 ): bool {
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
		return (bool) apply_filters( 'two_factor_use_secrets_api', $this->is_opted_in(), $user_id );
	}

	/**
	 * Whether new secrets may be written to the Secrets API.
	 *
	 * @since 0.18.0
	 *
	 * @param int $user_id User ID; may be 0 when no user is in context.
	 * @return bool
	 */
	public function can_write( int $user_id = 0 ): bool {
		if ( ! $this->is_api_present() ) {
			return false;
		}

		if ( ! $this->is_enabled( $user_id ) ) {
			return false;
		}

		return $this->is_provider_writable();
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
	public function get_secret_name( int $user_id, string $slug ): string {
		$this->assert_slug( $slug );

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
	public function get_marker_meta_key( string $slug ): string {
		$this->assert_slug( $slug );

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
	public function get_user_secret( int $user_id, string $slug ) {
		$marker = get_user_meta( $user_id, $this->get_marker_meta_key( $slug ), true );

		if ( '' === $marker || false === $marker || null === $marker ) {
			return null;
		}

		if ( ! $this->is_api_present() ) {
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

		$value = $this->store->get( $this->get_secret_name( $user_id, $slug ) );

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
	public function set_user_secret( int $user_id, string $slug, string $value ) {
		if ( ! $this->is_api_present() ) {
			return new WP_Error(
				'two_factor_secrets_api_missing',
				__( 'The Secrets API is not available, so this secret cannot be stored.', 'two-factor' )
			);
		}

		$result = $this->store->set( $this->get_secret_name( $user_id, $slug ), $value );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_user_meta( $user_id, $this->get_marker_meta_key( $slug ), (string) get_current_network_id() );

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
	public function delete_user_secret( int $user_id, string $slug ) {
		$error = null;

		if ( $this->is_api_present() ) {
			$result = $this->store->delete( $this->get_secret_name( $user_id, $slug ) );
			if ( is_wp_error( $result ) ) {
				$error = $result;
			}
		}

		delete_user_meta( $user_id, $this->get_marker_meta_key( $slug ) );

		return $error ? $error : true;
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
	private function assert_slug( string $slug ): void {
		if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $slug ) ) {
			throw new InvalidArgumentException( 'Invalid secret slug.' );
		}
	}
}
