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
	 * Site transient caching whether any user's stored secret is unreachable.
	 *
	 * @since 0.18.0
	 *
	 * @var string
	 */
	const AFFECTED_USERS_TRANSIENT = 'two_factor_secrets_affected_users';

	/**
	 * How long, in seconds, the unreachable-secrets result is cached.
	 *
	 * @since 0.18.0
	 *
	 * @var int
	 */
	const AFFECTED_USERS_CACHE_TTL = 300;

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
	 * Read a secret a provider keeps for a user, wherever it is stored.
	 *
	 * A plaintext value in the provider's own user meta wins, and is moved into the
	 * Secrets API on the way past when that is allowed. Otherwise the Secrets API is
	 * consulted when the user has a marker.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id  User ID.
	 * @param string $slug     Secret slug.
	 * @param string $meta_key User meta key that holds the plaintext value.
	 * @param bool   $migrate  Optional. Whether to migrate a plaintext value. Default true.
	 * @return string|null|WP_Error The value, null when the user has none, or a WP_Error when one exists but cannot be read.
	 */
	public function get_stored_secret_state( int $user_id, string $slug, string $meta_key, bool $migrate = true ) {
		$plaintext = (string) get_user_meta( $user_id, $meta_key, true );

		if ( '' !== $plaintext ) {
			if ( $migrate && $this->can_write( $user_id ) ) {
				$this->migrate_stored_secret( $user_id, $slug, $meta_key );
			}

			return $plaintext;
		}

		if ( '' === (string) get_user_meta( $user_id, $this->get_marker_meta_key( $slug ), true ) ) {
			return null;
		}

		return $this->get_user_secret( $user_id, $slug );
	}

	/**
	 * Move a plaintext secret from user meta into the Secrets API.
	 *
	 * The plaintext is removed only after the stored secret has been read back and
	 * matches. Nothing ever moves a secret the other way.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id  User ID.
	 * @param string $slug     Secret slug.
	 * @param string $meta_key User meta key that holds the plaintext value.
	 * @return true|null|WP_Error True when migrated, null when there is nothing to migrate, WP_Error on failure.
	 */
	public function migrate_stored_secret( int $user_id, string $slug, string $meta_key ) {
		$plaintext = (string) get_user_meta( $user_id, $meta_key, true );

		if ( '' === $plaintext ) {
			return null;
		}

		if ( ! $this->can_write( $user_id ) ) {
			return new WP_Error(
				'two_factor_secrets_not_writable',
				__( 'The Secrets API cannot be written to, so the secret was not migrated.', 'two-factor' )
			);
		}

		$result = $this->set_user_secret( $user_id, $slug, $plaintext );

		if ( is_wp_error( $result ) ) {
			$this->fire_migration_failed( $user_id, $slug, $result );
			return $result;
		}

		$readback = $this->get_user_secret( $user_id, $slug );

		if ( ! is_string( $readback ) || ! hash_equals( $plaintext, $readback ) ) {
			$error = is_wp_error( $readback )
				? $readback
				: new WP_Error(
					'two_factor_secrets_migration_mismatch',
					__( 'The stored secret did not match the original, so the migration was rolled back.', 'two-factor' )
				);

			$this->delete_user_secret( $user_id, $slug );
			$this->fire_migration_failed( $user_id, $slug, $error );

			return $error;
		}

		delete_user_meta( $user_id, $meta_key );
		Two_Factor_Secrets::memzero( $readback );
		$this->clear_affected_users_cache();

		/**
		 * Fires after a user's secret was moved into the Secrets API.
		 *
		 * @since 0.18.0
		 *
		 * @param int    $user_id User ID.
		 * @param string $slug    Secret slug, such as "totp".
		 */
		do_action( 'two_factor_secrets_migrated', $user_id, $slug );

		return true;
	}

	/**
	 * Fire the migration failure action.
	 *
	 * @since 0.18.0
	 *
	 * @param int      $user_id User ID.
	 * @param string   $slug    Secret slug.
	 * @param WP_Error $error   The failure.
	 * @return void
	 */
	private function fire_migration_failed( int $user_id, string $slug, WP_Error $error ): void {
		/**
		 * Fires when moving a user's secret into the Secrets API failed.
		 *
		 * The plaintext copy is kept, so the user can still log in. The error never contains the secret.
		 *
		 * @since 0.18.0
		 *
		 * @param int      $user_id User ID.
		 * @param string   $slug    Secret slug, such as "totp".
		 * @param WP_Error $error   The failure.
		 */
		do_action( 'two_factor_secrets_migration_failed', $user_id, $slug, $error );
	}

	/**
	 * Store a secret a provider keeps for a user.
	 *
	 * Writes to the Secrets API when that is allowed, verifying by read-back, and to
	 * the provider's user meta otherwise. A Secrets API failure never results in a
	 * plaintext write. The value must be a single non-empty string.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id  User ID.
	 * @param string $slug     Secret slug.
	 * @param string $meta_key User meta key that holds the plaintext value.
	 * @param string $value    Secret value.
	 * @return int|bool|WP_Error True, or what update_user_meta() returned for a plaintext write, or a WP_Error when the Secrets API write or its read-back failed.
	 */
	public function save_stored_secret( int $user_id, string $slug, string $meta_key, string $value ) {
		if ( $this->can_write( $user_id ) ) {
			$result = $this->set_user_secret( $user_id, $slug, $value );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$readback = $this->get_user_secret( $user_id, $slug );

			if ( ! is_string( $readback ) || ! hash_equals( $value, $readback ) ) {
				$this->delete_user_secret( $user_id, $slug );

				return is_wp_error( $readback )
					? $readback
					: new WP_Error(
						'two_factor_secrets_write_mismatch',
						__( 'The stored secret did not match the original, so it was removed.', 'two-factor' )
					);
			}

			Two_Factor_Secrets::memzero( $readback );
			delete_user_meta( $user_id, $meta_key );
			$this->clear_affected_users_cache();

			return true;
		}

		$result = update_user_meta( $user_id, $meta_key, $value );

		// Clear any stale marker or secret so the plaintext value is unambiguous.
		$this->delete_user_secret( $user_id, $slug );
		$this->clear_affected_users_cache();

		return $result;
	}

	/**
	 * Delete a secret a provider keeps for a user, from user meta and the Secrets API.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id  User ID.
	 * @param string $slug     Secret slug.
	 * @param string $meta_key User meta key that holds the plaintext value.
	 * @return bool Whether nothing of the secret is left behind.
	 */
	public function delete_stored_secret( int $user_id, string $slug, string $meta_key ): bool {
		delete_user_meta( $user_id, $meta_key );

		$deleted = $this->delete_user_secret( $user_id, $slug );
		$this->clear_affected_users_cache();

		return true === $deleted
			&& '' === (string) get_user_meta( $user_id, $meta_key, true )
			&& '' === (string) get_user_meta( $user_id, $this->get_marker_meta_key( $slug ), true );
	}

	/**
	 * Get where a secret a provider keeps for a user is stored. Never migrates.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id  User ID.
	 * @param string $slug     Secret slug.
	 * @param string $meta_key User meta key that holds the plaintext value.
	 * @return string One of 'plaintext', 'secrets-api', 'unavailable' or 'none'.
	 */
	public function get_stored_secret_storage( int $user_id, string $slug, string $meta_key ): string {
		if ( '' !== (string) get_user_meta( $user_id, $meta_key, true ) ) {
			return 'plaintext';
		}

		if ( '' === (string) get_user_meta( $user_id, $this->get_marker_meta_key( $slug ), true ) ) {
			return 'none';
		}

		$secret = $this->get_user_secret( $user_id, $slug );

		if ( is_string( $secret ) ) {
			Two_Factor_Secrets::memzero( $secret );
			return 'secrets-api';
		}

		return 'unavailable';
	}

	/**
	 * Whether a user has a secret that can be reached from here. Does not decrypt anything.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id  User ID.
	 * @param string $slug     Secret slug.
	 * @param string $meta_key User meta key that holds the plaintext value.
	 * @return bool
	 */
	public function is_stored_secret_reachable( int $user_id, string $slug, string $meta_key ): bool {
		if ( '' !== (string) get_user_meta( $user_id, $meta_key, true ) ) {
			return true;
		}

		$marker = (string) get_user_meta( $user_id, $this->get_marker_meta_key( $slug ), true );

		return '' !== $marker
			&& $this->is_api_present()
			&& get_current_network_id() === (int) $marker;
	}

	/**
	 * Whether a user has a secret in the Secrets API that cannot be reached from here.
	 *
	 * True when the Secrets API is missing or the secret belongs to another network.
	 * Does not decrypt anything.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id  User ID.
	 * @param string $slug     Secret slug.
	 * @param string $meta_key User meta key that holds the plaintext value.
	 * @return bool
	 */
	public function is_stored_secret_unreachable( int $user_id, string $slug, string $meta_key ): bool {
		if ( '' !== (string) get_user_meta( $user_id, $meta_key, true ) ) {
			return false;
		}

		$marker = (string) get_user_meta( $user_id, $this->get_marker_meta_key( $slug ), true );

		if ( '' === $marker ) {
			return false;
		}

		return ! $this->is_api_present() || get_current_network_id() !== (int) $marker;
	}

	/**
	 * Whether any user has a secret in the Secrets API that this site cannot currently reach.
	 *
	 * The result is cached briefly in a site transient.
	 *
	 * @since 0.18.0
	 *
	 * @param string[] $slugs Secret slugs to look for.
	 * @return bool
	 */
	public function has_affected_users( array $slugs ): bool {
		$cached = get_site_transient( self::AFFECTED_USERS_TRANSIENT );

		if ( 'yes' === $cached || 'no' === $cached ) {
			return 'yes' === $cached;
		}

		$affected = false;

		// With the API present on a single site every marker names the only network, so nothing can be out of reach.
		if ( ! $this->is_api_present() || is_multisite() ) {
			foreach ( $slugs as $slug ) {
				$args = array(
					'blog_id'      => 0,
					'meta_key'     => $this->get_marker_meta_key( $slug ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single-row lookup, result cached.
					'meta_compare' => 'EXISTS',
					'number'       => 1,
					'fields'       => 'ID',
					'count_total'  => false,
				);

				if ( $this->is_api_present() ) {
					$args['meta_value']   = (string) get_current_network_id(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single-row lookup, result cached.
					$args['meta_compare'] = '!=';
				}

				if ( ! empty( get_users( $args ) ) ) {
					$affected = true;
					break;
				}
			}
		}

		set_site_transient( self::AFFECTED_USERS_TRANSIENT, $affected ? 'yes' : 'no', self::AFFECTED_USERS_CACHE_TTL );

		return $affected;
	}

	/**
	 * Forget the cached unreachable-secrets result.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public function clear_affected_users_cache(): void {
		delete_site_transient( self::AFFECTED_USERS_TRANSIENT );
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
