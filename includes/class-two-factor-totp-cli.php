<?php
/**
 * WP-CLI commands for Two Factor TOTP.
 *
 * @package Two_Factor
 */

/**
 * Manage Two-Factor TOTP secrets.
 */
class Two_Factor_Totp_Cli {

	/**
	 * The secret class to use for encryption operations.
	 *
	 * @var string
	 */
	protected $secret_class = 'Two_Factor_Totp_Secret';

	/**
	 * Encrypt all plaintext TOTP secrets in the database.
	 *
	 * Finds all users with a plaintext `_two_factor_totp_key` and encrypts
	 * them using the configured TWO_FACTOR_TOTP_ENCRYPTION_KEY constant.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be encrypted without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview which secrets would be encrypted.
	 *     $ wp two-factor totp encrypt-secrets --dry-run
	 *
	 *     # Encrypt all plaintext TOTP secrets.
	 *     $ wp two-factor totp encrypt-secrets
	 *
	 * @subcommand encrypt-secrets
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function encrypt_secrets( $args, $assoc_args ) {
		$dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$secret_class = $this->secret_class;

		if ( ! $secret_class::is_encryption_available() ) {
			WP_CLI::error( 'Encryption is not available. Ensure TWO_FACTOR_TOTP_ENCRYPTION_KEY is defined in wp-config.php and AES-256-GCM hardware support is present.' );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
				'_two_factor_totp_key'
			)
		);

		if ( empty( $results ) ) {
			WP_CLI::success( 'No TOTP secrets found in the database.' );
			return;
		}

		$encrypted_count = 0;
		$skipped_count   = 0;
		$error_count     = 0;

		foreach ( $results as $row ) {
			$user_id = (int) $row->user_id;
			$value   = $row->meta_value;

			if ( $secret_class::is_encrypted( $value ) ) {
				$skipped_count++;
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::log( sprintf( 'Would encrypt secret for user %d.', $user_id ) );
				$encrypted_count++;
				continue;
			}

			$encrypted = $secret_class::encrypt( $value, $user_id );
			if ( false === $encrypted ) {
				WP_CLI::warning( sprintf( 'Failed to encrypt secret for user %d.', $user_id ) );
				$error_count++;
				continue;
			}

			update_user_meta( $user_id, '_two_factor_totp_key', $encrypted );
			$encrypted_count++;
		}

		if ( $dry_run ) {
			WP_CLI::success(
				sprintf(
					'Dry run complete. %d secret(s) would be encrypted, %d already encrypted.',
					$encrypted_count,
					$skipped_count
				)
			);
		} else {
			WP_CLI::success(
				sprintf(
					'Done. %d secret(s) encrypted, %d already encrypted, %d error(s).',
					$encrypted_count,
					$skipped_count,
					$error_count
				)
			);
		}
	}
}
