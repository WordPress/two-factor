<?php
/**
 * WP-CLI commands for Two-Factor authentication management.
 *
 * @package Two_Factor
 */

/**
 * Manage two-factor authentication for users.
 *
 * All commands target a single, explicitly named user (by ID, login, or email).
 * On Multisite, user meta is network-global — a reset applies to the user's
 * account across every site in the network without needing --url.
 *
 * @package Two_Factor
 */
class Two_Factor_CLI_Command extends WP_CLI_Command {

	/**
	 * Resolve a user from an ID, login, or email address.
	 *
	 * ID is tried first when the identifier is numeric, then login, then email.
	 *
	 * @param string $identifier User ID, login, or email.
	 * @return WP_User|false WP_User on success, false if not found.
	 */
	private function resolve_user( $identifier ) {
		if ( ctype_digit( (string) $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
			if ( $user ) {
				return $user;
			}
		}

		$user = get_user_by( 'login', $identifier );
		if ( $user ) {
			return $user;
		}

		return get_user_by( 'email', $identifier );
	}

	/**
	 * Show two-factor authentication status for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show 2FA status for "admin"
	 *     $ wp two-factor status admin
	 *
	 *     # Output as JSON
	 *     $ wp two-factor status 1 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] );
		if ( ! $user ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: user identifier */
					__( 'User not found: %s', 'two-factor' ),
					$args[0]
				)
			);
		}

		$using_2fa         = Two_Factor_Core::is_user_using_two_factor( $user->ID );
		$enabled_providers = Two_Factor_Core::get_enabled_providers_for_user( $user );
		$primary           = Two_Factor_Core::get_primary_provider_for_user( $user->ID );

		$backup_codes_remaining = 0;
		if ( class_exists( 'Two_Factor_Backup_Codes' ) ) {
			$backup_codes_remaining = Two_Factor_Backup_Codes::codes_remaining_for_user( $user );
		}

		$items = array(
			array(
				'user_id'                => $user->ID,
				'user_login'             => $user->user_login,
				'using_2fa'              => $using_2fa ? 'true' : 'false',
				'primary_provider'       => $primary ? $primary->get_key() : '',
				'enabled_providers'      => implode( ', ', $enabled_providers ),
				'backup_codes_remaining' => $backup_codes_remaining,
			),
		);

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'user_id', 'user_login', 'using_2fa', 'primary_provider', 'enabled_providers', 'backup_codes_remaining' )
		);
	}

	/**
	 * Disable two-factor authentication for a user.
	 *
	 * Without a provider argument every factor is disabled and the user is
	 * returned to a clean, pre-2FA baseline (nonce, lockout timers, the
	 * password-was-reset flag, and all provider secrets are also cleared). With
	 * a provider argument only that single factor is removed and the others are
	 * left intact.
	 *
	 * The command is idempotent: disabling an already-disabled user or provider
	 * succeeds and makes no changes.
	 *
	 * On Multisite, user meta is network-global — this reset affects the user's
	 * account across every site in the network.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [<provider>]
	 * : Provider class name to disable (e.g. Two_Factor_Totp). Omit to disable all.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Fully disable 2FA for a locked-out user (no prompt)
	 *     $ wp two-factor disable admin --yes
	 *
	 *     # Remove only TOTP, leaving backup codes in place
	 *     $ wp two-factor disable admin Two_Factor_Totp
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function disable( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] );
		if ( ! $user ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: user identifier */
					__( 'User not found: %s', 'two-factor' ),
					$args[0]
				)
			);
		}

		if ( isset( $args[1] ) ) {
			$this->disable_single_provider( $user, $args[1], $assoc_args );
		} else {
			$this->disable_all_providers( $user, $assoc_args );
		}
	}

	/**
	 * Disable a single 2FA provider for a user.
	 *
	 * @param WP_User $user       Target user.
	 * @param string  $provider   Provider class name.
	 * @param array   $assoc_args CLI flags.
	 */
	private function disable_single_provider( $user, $provider, $assoc_args ) {
		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $user );

		if ( ! in_array( $provider, $enabled, true ) ) {
			WP_CLI::success(
				sprintf(
					/* translators: 1: provider class name, 2: user login */
					__( 'Provider %1$s is not enabled for %2$s — no changes made.', 'two-factor' ),
					$provider,
					$user->user_login
				)
			);
			return;
		}

		WP_CLI::confirm(
			sprintf(
				/* translators: 1: provider class name, 2: user login */
				__( 'Disable provider %1$s for user %2$s?', 'two-factor' ),
				$provider,
				$user->user_login
			),
			$assoc_args
		);

		if ( Two_Factor_Core::disable_provider_for_user( $user->ID, $provider ) ) {
			WP_CLI::success(
				sprintf(
					/* translators: 1: provider class name, 2: user login */
					__( 'Provider %1$s disabled for user %2$s.', 'two-factor' ),
					$provider,
					$user->user_login
				)
			);
		} else {
			WP_CLI::error(
				sprintf(
					/* translators: 1: provider class name, 2: user login */
					__( 'Could not disable provider %1$s for user %2$s.', 'two-factor' ),
					$provider,
					$user->user_login
				)
			);
		}
	}

	/**
	 * Disable all 2FA providers and clean up all residual state for a user.
	 *
	 * @param WP_User $user       Target user.
	 * @param array   $assoc_args CLI flags.
	 */
	private function disable_all_providers( $user, $assoc_args ) {
		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $user );
		$raw     = get_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );

		if ( empty( $enabled ) && empty( $raw ) ) {
			WP_CLI::success(
				sprintf(
					/* translators: %s: user login */
					__( 'Two-factor is already disabled for user %s — no changes made.', 'two-factor' ),
					$user->user_login
				)
			);
			return;
		}

		WP_CLI::confirm(
			sprintf(
				/* translators: %s: user login */
				__( 'Disable all two-factor authentication for user %s?', 'two-factor' ),
				$user->user_login
			),
			$assoc_args
		);

		// Disable each provider through the core API.
		$disabled = array();
		foreach ( $enabled as $provider_key ) {
			Two_Factor_Core::disable_provider_for_user( $user->ID, $provider_key );
			$disabled[] = $provider_key;
		}

		// Force-clear the authoritative switches to handle any stale raw meta not
		// covered by the loop above (e.g. a provider class that no longer exists).
		delete_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY );
		delete_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY );

		// Clear session and throttle state.
		delete_user_meta( $user->ID, Two_Factor_Core::USER_META_NONCE_KEY );
		delete_user_meta( $user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY );
		Two_Factor_Core::clear_login_rate_limit( $user );

		// Clear provider-specific secrets for a clean baseline.
		if ( class_exists( 'Two_Factor_Totp' ) ) {
			Two_Factor_Totp::get_instance()->delete_user_totp_key( $user->ID );
			delete_user_meta( $user->ID, Two_Factor_Totp::LAST_SUCCESSFUL_LOGIN_META_KEY );
		}
		if ( class_exists( 'Two_Factor_Backup_Codes' ) ) {
			delete_user_meta( $user->ID, Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY );
		}
		if ( class_exists( 'Two_Factor_Email' ) ) {
			delete_user_meta( $user->ID, Two_Factor_Email::TOKEN_META_KEY );
			delete_user_meta( $user->ID, Two_Factor_Email::TOKEN_META_KEY_TIMESTAMP );
		}

		// Guard: assert the fail-closed fallback did not silently re-enable email.
		$still_available = Two_Factor_Core::get_available_providers_for_user( $user );
		if ( ! empty( $still_available ) && ! is_wp_error( $still_available ) ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: user login */
					__( '2FA is still active for user %s after reset — manual inspection required.', 'two-factor' ),
					$user->user_login
				)
			);
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: comma-separated provider names, 2: user login */
				__( 'All 2FA disabled for user %2$s (providers removed: %1$s).', 'two-factor' ),
				$disabled ? implode( ', ', $disabled ) : __( 'none', 'two-factor' ),
				$user->user_login
			)
		);
	}

	/**
	 * List all registered two-factor authentication providers.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp two-factor list-providers
	 *     $ wp two-factor list-providers --format=json
	 *
	 * @subcommand list-providers
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_providers( $args, $assoc_args ) {
		$providers = Two_Factor_Core::get_providers();
		$items     = array();

		foreach ( $providers as $key => $provider ) {
			$items[] = array(
				'class' => $key,
				'label' => $provider->get_label(),
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::log( __( 'No providers registered.', 'two-factor' ) );
			return;
		}

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		WP_CLI\Utils\format_items( $format, $items, array( 'class', 'label' ) );
	}

	/**
	 * Enable a two-factor authentication provider for a user.
	 *
	 * Fully meaningful for providers that need no pre-shared secret, such as
	 * Two_Factor_Email. For providers that require a secret (Two_Factor_Totp)
	 * or generated material (Two_Factor_Backup_Codes) this command refuses with
	 * a pointer to the appropriate setup command.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * <provider>
	 * : Provider class name to enable (e.g. Two_Factor_Email).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp two-factor enable admin Two_Factor_Email
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function enable( $args, $assoc_args ) {
		if ( ! isset( $args[1] ) ) {
			WP_CLI::error( __( 'Usage: wp two-factor enable <user> <provider>', 'two-factor' ) );
		}

		$user = $this->resolve_user( $args[0] );
		if ( ! $user ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: user identifier */
					__( 'User not found: %s', 'two-factor' ),
					$args[0]
				)
			);
		}

		$provider = $args[1];

		// TOTP requires a pre-shared secret that cannot be set up from the CLI alone.
		if ( 'Two_Factor_Totp' === $provider ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: provider class name */
					__( 'Provider %s requires a pre-shared secret and cannot be enabled from the CLI. Set it up via the user profile page or the totp subcommand (Phase 3).', 'two-factor' ),
					$provider
				)
			);
		}

		// Backup codes must be generated first via the dedicated command.
		if ( 'Two_Factor_Backup_Codes' === $provider ) {
			WP_CLI::error(
				__( 'Use "wp two-factor backup-codes generate <user>" to generate and enable backup codes.', 'two-factor' )
			);
		}

		if ( Two_Factor_Core::enable_provider_for_user( $user->ID, $provider ) ) {
			WP_CLI::success(
				sprintf(
					/* translators: 1: provider class name, 2: user login */
					__( 'Provider %1$s enabled for user %2$s.', 'two-factor' ),
					$provider,
					$user->user_login
				)
			);
		} else {
			WP_CLI::error(
				sprintf(
					/* translators: 1: provider class name, 2: user login */
					__( 'Could not enable provider %1$s for user %2$s. Is it a registered provider?', 'two-factor' ),
					$provider,
					$user->user_login
				)
			);
		}
	}

	/**
	 * Manage backup recovery codes for a user.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform. Supported: generate.
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--count=<n>]
	 * : Number of codes to generate. Defaults to 10.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate 10 backup codes for "admin"
	 *     $ wp two-factor backup-codes generate admin
	 *
	 *     # Generate 5 backup codes
	 *     $ wp two-factor backup-codes generate admin --count=5
	 *
	 * @subcommand backup-codes
	 *
	 * @param array $args       Positional arguments: action, user.
	 * @param array $assoc_args Associative arguments.
	 */
	public function backup_codes( $args, $assoc_args ) {
		$action = array_shift( $args );

		if ( 'generate' !== $action ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: provided action */
					__( 'Unknown action "%s". Use: wp two-factor backup-codes generate <user>', 'two-factor' ),
					(string) $action
				)
			);
		}

		if ( empty( $args ) ) {
			WP_CLI::error( __( 'Usage: wp two-factor backup-codes generate <user> [--count=<n>]', 'two-factor' ) );
		}

		$user = $this->resolve_user( $args[0] );
		if ( ! $user ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: user identifier */
					__( 'User not found: %s', 'two-factor' ),
					$args[0]
				)
			);
		}

		if ( ! class_exists( 'Two_Factor_Backup_Codes' ) ) {
			WP_CLI::error( __( 'The Two_Factor_Backup_Codes provider is not available.', 'two-factor' ) );
		}

		$count    = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'count', Two_Factor_Backup_Codes::NUMBER_OF_CODES );
		$provider = Two_Factor_Backup_Codes::get_instance();
		$codes    = $provider->generate_codes(
			$user,
			array(
				'number' => $count,
				'method' => 'replace',
			) 
		);

		WP_CLI::log(
			sprintf(
				/* translators: 1: number of codes, 2: user login */
				__( 'Generated %1$d backup codes for %2$s. Store these somewhere safe — they will not be shown again:', 'two-factor' ),
				count( $codes ),
				$user->user_login
			)
		);

		foreach ( $codes as $code ) {
			WP_CLI::log( '  ' . $code );
		}

		WP_CLI::success( __( 'Backup codes generated and stored (existing codes replaced).', 'two-factor' ) );
	}

	/**
	 * Clear the login throttle for a user without modifying their 2FA setup.
	 *
	 * Use this when a user has been temporarily locked out by too many bad codes
	 * but still has their authenticator device available. For a full reset use
	 * "wp two-factor disable <user>".
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp two-factor unlock admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function unlock( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] );
		if ( ! $user ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: user identifier */
					__( 'User not found: %s', 'two-factor' ),
					$args[0]
				)
			);
		}

		$was_limited = Two_Factor_Core::is_user_rate_limited( $user );
		Two_Factor_Core::clear_login_rate_limit( $user );

		if ( $was_limited ) {
			WP_CLI::success(
				sprintf(
					/* translators: %s: user login */
					__( 'Login throttle cleared for user %s.', 'two-factor' ),
					$user->user_login
				)
			);
		} else {
			WP_CLI::success(
				sprintf(
					/* translators: %s: user login */
					__( 'User %s was not rate-limited — no changes made.', 'two-factor' ),
					$user->user_login
				)
			);
		}
	}
}
