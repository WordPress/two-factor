<?php
/**
 * Class for creating a backup codes provider.
 *
 * @package Two_Factor
 */

/**
 * Class for creating a backup codes provider.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_Backup_Codes extends Two_Factor_Provider {

	/**
	 * The user meta backup codes key.
	 *
	 * @type string
	 */
	const BACKUP_CODES_META_KEY = '_two_factor_backup_codes';

	/**
	 * The number backup codes.
	 *
	 * @type int
	 */
	const NUMBER_OF_CODES = 10;

	/**
	 * The default number of remaining codes at or below which the user is
	 * warned to regenerate, before they run out entirely.
	 *
	 * @type int
	 */
	const LOW_CODES_THRESHOLD = 2;

	/**
	 * Class constructor.
	 *
	 * @since 0.1-dev
	 *
	 * @codeCoverageIgnore
	 */
	protected function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'two_factor_user_options_' . __CLASS__, array( $this, 'user_options' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'two_factor_user_authenticated', array( $this, 'maybe_redirect_to_regenerate_codes' ), 10, 2 );
		add_action( 'two_factor_user_revalidated', array( $this, 'maybe_redirect_to_regenerate_codes' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * Enqueue scripts for backup codes.
	 *
	 * @since 0.10.0
	 *
	 * @codeCoverageIgnore
	 *
	 * @param string $hook_suffix Optional. The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix = '' ) {
		wp_register_script(
			'two-factor-backup-codes-admin',
			plugins_url( 'js/backup-codes-admin.js', __FILE__ ),
			array( 'jquery', 'wp-api-request' ),
			TWO_FACTOR_VERSION,
			true
		);
	}

	/**
	 * Register the rest-api endpoints required for this provider.
	 *
	 * @since 0.8.0
	 *
	 * @codeCoverageIgnore
	 */
	public function register_rest_routes() {
		register_rest_route(
			Two_Factor_Core::REST_NAMESPACE,
			'/generate-backup-codes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_generate_codes' ),
				'permission_callback' => function ( $request ) {
					return Two_Factor_Core::rest_api_can_edit_user_and_update_two_factor_options( $request['user_id'] );
				},
				'args'                => array(
					'user_id'         => array(
						'required' => true,
						'type'     => 'integer',
					),
					'enable_provider' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);
	}

	/**
	 * Displays an admin notice when backup codes have run out, or are running low.
	 *
	 * @since 0.1-dev
	 *
	 * @codeCoverageIgnore
	 */
	public function admin_notices() {
		$user = wp_get_current_user();

		// Return if the provider is not enabled.
		if ( ! in_array( __CLASS__, Two_Factor_Core::get_enabled_providers_for_user( $user->ID ), true ) ) {
			return;
		}

		$count          = self::codes_remaining_for_user( $user );
		$regenerate_url = esc_url( get_edit_user_link( $user->ID ) . '#two-factor-backup-codes' );

		// Out of codes: show an error and bail.
		if ( 0 === $count ) {
			?>
			<div class="error">
				<p>
					<span>
						<?php
						echo wp_kses(
							sprintf(
							/* translators: %s: URL for code regeneration */
								__( 'Two-Factor: You are out of recovery codes and need to <a href="%s">regenerate!</a>', 'two-factor' ),
								$regenerate_url
							),
							array( 'a' => array( 'href' => true ) )
						);
						?>
					</span>
				</p>
			</div>
			<?php
			return;
		}

		// Running low: warn the user before they hit zero.
		if ( $count <= self::get_low_codes_threshold( $user ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<span>
						<?php
						echo wp_kses(
							sprintf(
							/* translators: 1: number of recovery codes remaining, 2: URL for code regeneration */
								_n(
									'Two-Factor: You only have %1$s recovery code left. <a href="%2$s">Regenerate your codes</a> now before you run out.',
									'Two-Factor: You only have %1$s recovery codes left. <a href="%2$s">Regenerate your codes</a> now before you run out.',
									$count,
									'two-factor'
								),
								number_format_i18n( $count ),
								$regenerate_url
							),
							array( 'a' => array( 'href' => true ) )
						);
						?>
					</span>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Get the number of remaining codes at or below which the user is warned to regenerate.
	 *
	 * @since 0.18.0
	 *
	 * @param WP_User $user User object.
	 *
	 * @return int
	 */
	private static function get_low_codes_threshold( $user ) {
		/**
		 * Filters the number of remaining recovery codes at or below which the
		 * user is warned to regenerate, before they run out entirely.
	 *
		 * Applies to both the admin notice and the email sent when a code is used.
		 *
		 * @since 0.17.0
		 *
		 * @param int     $threshold Number of remaining codes that triggers the warning. Default 2.
		 * @param WP_User $user      User object.
		 */
		return (int) apply_filters( 'two_factor_backup_codes_low_threshold', self::LOW_CODES_THRESHOLD, $user );
	}

	/**
	 * Get the URL of the recovery codes section on the user's own profile screen.
	 *
	 * Uses get_edit_profile_url() rather than get_edit_user_link(), which checks the current
	 * user's capabilities and so returns an empty string during login, before the current user is set.
	 *
	 * @since 0.18.0
	 *
	 * @param WP_User $user User object.
	 *
	 * @return string
	 */
	private static function get_regenerate_codes_url( $user ) {
		return get_edit_profile_url( $user->ID ) . '#two-factor-backup-codes';
	}

	/**
	 * Send the user straight to the recovery codes section after they log in with their last code.
	 *
	 * Runs on `two_factor_user_authenticated` and `two_factor_user_revalidated`, which both fire
	 * right before the `login_redirect` filter is applied. Interim (modal) logins don't redirect,
	 * so those users still get the admin notice instead.
	 *
	 * @since 0.18.0
	 *
	 * @param WP_User             $user     The user who just authenticated.
	 * @param Two_Factor_Provider $provider The provider the user authenticated with.
	 */
	public function maybe_redirect_to_regenerate_codes( $user, $provider ) {
		if ( ! $provider instanceof Two_Factor_Provider || $this->get_key() !== $provider->get_key() ) {
			return;
		}

		if ( 0 < self::codes_remaining_for_user( $user ) ) {
			return;
		}

		add_filter(
			'login_redirect',
			static function () use ( $user ) {
				return self::get_regenerate_codes_url( $user );
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Notify a user by email that they are running low on, or have run out of, recovery codes.
	 *
	 * Sent each time a code is used while the user is at or below the threshold, matching the
	 * notices in `admin_notices()`, so users are warned even if they don't visit wp-admin.
	 *
	 * @since 0.18.0
	 *
	 * @param WP_User $user The user who just used a recovery code.
	 *
	 * @return bool `true` if the email was sent, `false` if there was nothing to report or it failed.
	 */
	public static function notify_user_codes_running_low( $user ) {
		if ( ! in_array( __CLASS__, Two_Factor_Core::get_enabled_providers_for_user( $user->ID ), true ) ) {
			return false;
		}

		$count = self::codes_remaining_for_user( $user );

		if ( 0 < $count && $count > self::get_low_codes_threshold( $user ) ) {
			return false;
		}

		if ( 0 === $count ) {
			/* translators: %s: site name. */
			$subject = __( '[%s] You are out of recovery codes', 'two-factor' );
			$status  = __( 'You have no recovery codes left.', 'two-factor' );
		} else {
			/* translators: %s: site name. */
			$subject = __( '[%s] You are running low on recovery codes', 'two-factor' );
			$status  = sprintf(
				/* translators: %s: number of recovery codes remaining. */
				_n( 'You only have %s recovery code left.', 'You only have %s recovery codes left.', $count, 'two-factor' ),
				number_format_i18n( $count )
			);
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$message = sprintf(
			/* translators: 1: username, 2: site URL, 3: sentence stating how many recovery codes are left, 4: URL to regenerate recovery codes, 5: site name. */
			__(
				'Hello %1$s,

A recovery code was just used on your account at %2$s. %3$s

Generate new recovery codes now to avoid being locked out of your account: %4$s

If you did NOT use a recovery code, someone else may have access to your account. Please change your password immediately and generate new recovery codes.

This is an automated notification. If you would like to speak to a site administrator, please contact them directly.

Regards,
All at %5$s
%2$s',
				'two-factor'
			),
			esc_html( $user->user_login ),
			home_url(),
			$status,
			self::get_regenerate_codes_url( $user ),
			$site_name
		);
		$message = str_replace( "\t", '', $message );

		$email = array(
			'to'      => $user->user_email,
			'subject' => sprintf( $subject, $site_name ),
			'message' => $message,
			'headers' => '',
		);

		/**
		 * Filters the email sent to a user when they are running low on, or have run out of, recovery codes.
		 *
		 * This can only change the email's content, not whether it is sent. Use the
		 * `two_factor_backup_codes_low_threshold` filter to change when it is sent.
		 *
		 * @since 0.18.0
		 *
		 * @param array   $email Used to build wp_mail(). Contains 'to', 'subject', 'message', and 'headers'.
		 * @param WP_User $user  The user who used a recovery code.
		 * @param int     $count Number of recovery codes the user has left.
		 */
		$email = apply_filters( 'two_factor_backup_codes_low_email', $email, $user, $count );

		return wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Plugin sends a single transactional security email to the affected user.
	}

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	public function get_label() {
		return _x( 'Recovery Codes', 'Provider Label', 'two-factor' );
	}

	/**
	 * Returns the "continue with" text provider for the login screen.
	 *
	 * @since 0.9.0
	 */
	public function get_alternative_provider_label() {
		return __( 'Use a recovery code', 'two-factor' );
	}

	/**
	 * Whether this Two Factor provider is configured and codes are available for the user specified.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		// Does this user have available codes?
		if ( 0 < self::codes_remaining_for_user( $user ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function user_options( $user ) {
		wp_localize_script(
			'two-factor-backup-codes-admin',
			'twoFactorBackupCodes',
			array(
				'restPath' => Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes',
				'userId'   => $user->ID,
			)
		);
		wp_enqueue_script( 'two-factor-backup-codes-admin' );

		$count = self::codes_remaining_for_user( $user );
		?>
		<div id="two-factor-backup-codes">
			<p class="description two-factor-backup-codes-count">
			<?php
				echo esc_html(
					sprintf(
						/* translators: %s: count */
						_n( '%s unused code remaining, each recovery code can only be used once.', '%s unused codes remaining, each recovery code can only be used once.', $count, 'two-factor' ),
						$count
					)
				);
			?>
			</p>
			<p>
				<button type="button" class="button button-two-factor-backup-codes-generate button-secondary hide-if-no-js">
					<?php esc_html_e( 'Generate new recovery codes', 'two-factor' ); ?>
				</button>

				<em><?php esc_html_e( 'This invalidates all currently stored codes.', 'two-factor' ); ?></em>
			</p>
		</div>
		<div class="two-factor-backup-codes-wrapper" style="display:none;">
			<div class="two-factor-backup-codes-list-wrap">
				<ol class="two-factor-backup-codes-unused-codes"></ol>
			</div>
			<p class="description"><?php esc_html_e( 'Write these down! Once you navigate away from this page, you will not be able to view these codes again.', 'two-factor' ); ?></p>
			<p>
				<button type="button" class="button button-two-factor-backup-codes-copy button-secondary hide-if-no-js" id="two-factor-backup-codes-copy-link"><?php esc_html_e( 'Copy Codes', 'two-factor' ); ?></button>
				<a class="button button-two-factor-backup-codes-download button-secondary hide-if-no-js" href="#" id="two-factor-backup-codes-download-link" download="two-factor-backup-codes.txt"><?php esc_html_e( 'Download Codes', 'two-factor' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Get the backup code length for a user.
	 *
	 * @since 0.11.0
	 *
	 * @param WP_User $user User object.
	 *
	 * @return int Number of characters.
	 */
	private function get_backup_code_length( $user ) {
		/**
		 * Filters the character count of the backup codes.
		 *
		 * @since 0.11.0
		 *
		 * @param int     $code_length Length of the backup code. Default 8.
		 * @param WP_User $user        User object.
		 */
		$code_length = (int) apply_filters( 'two_factor_backup_code_length', 8, $user );

		return $code_length;
	}

	/**
	 * Generates backup codes & updates the user meta.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param array   $args Optional arguments for assigning new codes.
	 * @return array
	 */
	public function generate_codes( $user, $args = array() ) {
		$codes        = array();
		$codes_hashed = array();

		// Check for arguments.
		if ( isset( $args['number'] ) ) {
			$num_codes = (int) $args['number'];
		} else {
			$num_codes = self::NUMBER_OF_CODES;
		}

		// Append or replace (default).
		if ( isset( $args['method'] ) && 'append' === $args['method'] ) {
			$codes_hashed = self::get_backup_codes_for_user( $user->ID );
		}

		$code_length = $this->get_backup_code_length( $user );

		for ( $i = 0; $i < $num_codes; $i++ ) {
			$code           = $this->get_code( $code_length );
			$codes_hashed[] = wp_hash_password( $code );
			$codes[]        = $code;
			unset( $code );
		}

		update_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, $codes_hashed );

		// Unhashed.
		return $codes;
	}

	/**
	 * Generates Backup Codes for returning through the WordPress Rest API.
	 *
	 * @since 0.8.0
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 */
	public function rest_generate_codes( $request ) {
		$user_id = $request['user_id'];
		$user    = get_user_by( 'id', $user_id );

		// Hardcode these, the user shouldn't be able to choose them.
		$args = array(
			'number' => self::NUMBER_OF_CODES,
			'method' => 'replace',
		);

		// Setup the return data.
		$codes = $this->generate_codes( $user, $args );
		$count = self::codes_remaining_for_user( $user );
		$title = sprintf(
			/* translators: %s: the site's domain */
			__( 'Two-Factor Recovery Codes for %s', 'two-factor' ),
			str_replace( array( 'http://', 'https://' ), '', home_url() ) // Account for sub-directory multisites by not using wp_parse_url() to extract the hostname.
		);

		/**
		 * Filters the title in the backup codes download file.
		 *
		 * @since 0.17.0
		 *
		 * @param string  $title Title for the backup codes download file.
		 * @param WP_User $user  User for whom the backup codes were generated.
		 */
		$title = apply_filters( 'two_factor_backup_codes_download_title', $title, $user );

		// Generate the codes text, shared by the copy and download actions.
		$codes_text = "{$title}\r\n\r\n";
		$i          = 1;
		foreach ( $codes as $code ) {
			$codes_text .= "{$i}. {$code}\r\n";
			++$i;
		}
		$codes_text .= "\r\n";
		$codes_text .= __( 'Each code can only be used once.', 'two-factor' ) . "\r\n";
		$codes_text .= __( 'These codes are the only way to recover your account if you lose access to your authentication app, or other two-factor method.', 'two-factor' ) . "\r\n";

		$download_link = 'data:application/text;charset=utf-8,' . rawurlencode( $codes_text );

		$i18n = array(
			/* translators: %s: count */
			'count' => esc_html( sprintf( _n( '%s unused code remaining, each recovery code can only be used once.', '%s unused codes remaining, each recovery code can only be used once.', $count, 'two-factor' ), $count ) ),
		);

		if ( $request->get_param( 'enable_provider' ) && ! Two_Factor_Core::enable_provider_for_user( $user_id, 'Two_Factor_Backup_Codes' ) ) {
			return new WP_Error( 'db_error', __( 'Unable to enable recovery codes for this user.', 'two-factor' ), array( 'status' => 500 ) );
		}

		return array(
			'codes'         => $codes,
			'codes_text'    => $codes_text,
			'download_link' => $download_link,
			'remaining'     => $count,
			'i18n'          => $i18n,
		);
	}

	/**
	 * Get the sanitized list of hashed backup codes for a user.
	 *
	 * Earlier versions could store an empty string entry in the hashed codes
	 * list, e.g. when appending codes for a user with no existing codes. Such
	 * entries can never match a real code and only pollute the stored list:
	 * they inflate codes_remaining_for_user() and keep the provider offered
	 * at login without any usable code backing it. This filters them out on
	 * every read so the counts and availability are always accurate.
	 *
	 * @since 0.18.0
	 *
	 * @param int $user_id User ID.
	 * @return array List of hashed backup codes without empty entries.
	 */
	public static function get_backup_codes_for_user( int $user_id ) {
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		if ( ! is_array( $backup_codes ) ) {
			return array();
		}

		// Remove any empty or non-string entries from the backup codes list.
		$backup_codes = array_filter(
			$backup_codes,
			static function ( $code ) {
				return is_string( $code ) && '' !== trim( $code );
			}
		);

		return array_values( $backup_codes );
	}

	/**
	 * Returns the number of unused codes for the specified user
	 *
	 * @since 0.2.0
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return int $int  The number of unused codes remaining
	 */
	public static function codes_remaining_for_user( $user ): int {
		return count( self::get_backup_codes_for_user( $user->ID ) );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		require_once ABSPATH . '/wp-admin/includes/template.php';

		$code_length      = $this->get_backup_code_length( $user );
		$code_placeholder = str_repeat( 'X', $code_length );

		?>
		<?php
		/**
		 * Fires before the two-factor authentication prompt text.
		 *
		 * @since 0.15.0
		 *
		 * @param Two_Factor_Provider $provider The two-factor provider instance.
		 */
		do_action( 'two_factor_before_authentication_prompt', $this );
		?>
		<p class="two-factor-prompt"><?php esc_html_e( 'Enter a recovery code.', 'two-factor' ); ?></p>
		<?php
		/**
		 * Fires after the two-factor authentication prompt text.
		 *
		 * @since 0.15.0
		 *
		 * @param Two_Factor_Provider $provider The two-factor provider instance.
		 */
		do_action( 'two_factor_after_authentication_prompt', $this );
		?>
		<p>
			<label for="authcode"><?php esc_html_e( 'Recovery Code:', 'two-factor' ); ?></label>
			<input type="text" inputmode="numeric" name="two-factor-backup-code" id="authcode" class="input authcode" value="" size="20" pattern="[0-9 ]*" placeholder="<?php echo esc_attr( $code_placeholder ); ?>" autocomplete="one-time-code" data-digits="<?php echo esc_attr( (string) $code_length ); ?>">
		</p>
		<?php
		/**
		 * Fires after the two-factor authentication input field.
		 *
		 * @since 0.15.0
		 *
		 * @param Two_Factor_Provider $provider The two-factor provider instance.
		 */
		do_action( 'two_factor_after_authentication_input', $this );
		?>
		<?php
		submit_button( __( 'Verify', 'two-factor' ) );
	}

	/**
	 * Validates the users input token.
	 *
	 * In this class we just return true.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function validate_authentication( $user ) {
		$backup_code = $this->sanitize_code_from_request( 'two-factor-backup-code' );
		if ( ! $backup_code ) {
			return false;
		}

		return $this->validate_code( $user, $backup_code );
	}

	/**
	 * Validates a backup code.
	 *
	 * Backup Codes are single use and are deleted upon a successful validation.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param string  $code The backup code.
	 * @return boolean
	 */
	public function validate_code( $user, $code ) {
		$backup_codes = self::get_backup_codes_for_user( $user->ID );

		foreach ( $backup_codes as $code_hashed ) {
			if ( wp_check_password( $code, $code_hashed, $user->ID ) ) {
				$this->delete_code( $user, $code_hashed );
				self::notify_user_codes_running_low( $user );

				return true;
			}
		}

		return false;
	}

	/**
	 * Deletes a backup code.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param string  $code_hashed The hashed the backup code.
	 */
	public function delete_code( $user, $code_hashed ) {
		$backup_codes = self::get_backup_codes_for_user( $user->ID );

		// Delete the current code from the list since it's been used.
		$backup_codes = array_flip( $backup_codes );
		unset( $backup_codes[ $code_hashed ] );
		$backup_codes = array_values( array_flip( $backup_codes ) );

		// Update the backup code master list.
		update_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, $backup_codes );
	}

	/**
	 * Return user meta keys to delete during plugin uninstall.
	 *
	 * @since 0.10.0
	 *
	 * @return array
	 */
	public static function uninstall_user_meta_keys() {
		return array(
			self::BACKUP_CODES_META_KEY,
		);
	}
}
