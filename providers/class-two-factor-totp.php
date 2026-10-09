<?php
/**
 * Class for creating a Time Based One-Time Password provider.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Totp
 *
 * @since 0.2.0
 */
class Two_Factor_Totp extends Two_Factor_Provider {

	/**
	 * The user meta key for the TOTP Secret key.
	 *
	 * @var string
	 */
	const SECRET_META_KEY = '_two_factor_totp_key';

	/**
	 * The user meta key marking which network holds the user's secret in the Secrets API.
	 *
	 * @since 0.18.0
	 *
	 * @var string
	 */
	const SECRET_NETWORK_META_KEY = '_two_factor_totp_key_network';

	/**
	 * The slug used for this provider's entries in the Secrets API.
	 *
	 * @since 0.18.0
	 *
	 * @var string
	 */
	const SECRET_SLUG = 'totp';

	/**
	 * The user meta key for the last successful TOTP token timestamp logged in with.
	 *
	 * @var string
	 */
	const LAST_SUCCESSFUL_LOGIN_META_KEY = '_two_factor_totp_last_successful_login';

	const DEFAULT_KEY_BIT_SIZE        = 160;
	const DEFAULT_CRYPTO              = 'sha1';
	const DEFAULT_DIGIT_COUNT         = 6;
	const DEFAULT_TIME_STEP_SEC       = 30;
	const DEFAULT_TIME_STEP_ALLOWANCE = 4;

	/**
	 * Characters used in base32 encoding.
	 *
	 * @var string
	 */
	private static $base_32_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * Class constructor. Sets up hooks, etc.
	 *
	 * @since 0.2.0
	 *
	 * @codeCoverageIgnore
	 */
	protected function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'two_factor_user_options_' . __CLASS__, array( $this, 'user_two_factor_options' ) );

		parent::__construct();
	}

	/**
	 * Timestamp returned by time()
	 *
	 * @var int $now
	 */
	private static $now;

	/**
	 * Override time() in the current object for testing.
	 *
	 * @since 0.15.0
	 *
	 * @return int
	 */
	private static function time() {
		return self::$now ? self::$now : time();
	}

	/**
	 * Set up the internal state of time() invocations for deterministic generation.
	 *
	 * @since 0.15.0
	 *
	 * @param int $now Timestamp to use when overriding time().
	 */
	public static function set_time( $now ) {
		self::$now = $now;
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
			'/totp',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'rest_delete_totp' ),
					'permission_callback' => function ( $request ) {
						return Two_Factor_Core::rest_api_can_edit_user_and_update_two_factor_options( $request['user_id'] );
					},
					'args'                => array(
						'user_id' => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_setup_totp' ),
					'permission_callback' => function ( $request ) {
						return Two_Factor_Core::rest_api_can_edit_user_and_update_two_factor_options( $request['user_id'] );
					},
					'args'                => array(
						'user_id'         => array(
							'required' => true,
							'type'     => 'integer',
						),
						'key'             => array(
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => null, // Note: validation handled in ::rest_setup_totp().
						),
						'code'            => array(
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => null, // Note: validation handled in ::rest_setup_totp().
						),
						'enable_provider' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.2.0
	 */
	public function get_label() {
		return _x( 'Authenticator App', 'Provider Label', 'two-factor' );
	}

	/**
	 * Returns the "continue with" text provider for the login screen.
	 *
	 * @since 0.9.0
	 */
	public function get_alternative_provider_label() {
		return __( 'Use your authenticator app for time-based one-time passwords (TOTP)', 'two-factor' );
	}

	/**
	 * Enqueue scripts
	 *
	 * @since 0.8.0
	 *
	 * @codeCoverageIgnore
	 * @param string $hook_suffix Hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$environment_prefix = file_exists( TWO_FACTOR_DIR . '/dist' ) ? '/dist' : '';

		wp_register_script(
			'two-factor-qr-code-generator',
			plugins_url( $environment_prefix . '/includes/qrcode-generator/qrcode.js', __DIR__ ),
			array(),
			TWO_FACTOR_VERSION,
			true
		);

		wp_register_script(
			'two-factor-totp-qrcode',
			plugins_url( 'js/totp-admin-qrcode.js', __FILE__ ),
			array( 'two-factor-qr-code-generator' ),
			TWO_FACTOR_VERSION,
			true
		);

		wp_register_script(
			'two-factor-totp-admin',
			plugins_url( 'js/totp-admin.js', __FILE__ ),
			array( 'jquery', 'wp-api-request', 'two-factor-qr-code-generator' ),
			TWO_FACTOR_VERSION,
			true
		);
	}

	/**
	 * Rest API endpoint for handling deactivation of TOTP.
	 *
	 * @since 0.8.0
	 *
	 * @param WP_REST_Request $request The Rest Request object.
	 * @return WP_Error|array Array of data on success, WP_Error on error.
	 */
	public function rest_delete_totp( $request ) {
		$user_id = $request['user_id'];
		$user    = get_user_by( 'id', $user_id );

		// A reset cannot be followed by re-enrollment while the Secrets API key is unusable,
		// and it would leave the user without a second factor in the meantime.
		if ( Two_Factor_Secrets::is_key_unavailable_error( $this->get_user_totp_key_state( $user_id, false ) ) ) {
			return new WP_Error(
				'two_factor_secrets_key_unavailable',
				__( 'The authenticator app cannot be reset because the WordPress Secrets API cannot use its encryption key. Restore the site\'s original secrets key (WP_SECRETS_KEY) first.', 'two-factor' ),
				array( 'status' => 503 )
			);
		}

		if ( ! Two_Factor_Core::disable_provider_for_user( $user_id, 'Two_Factor_Totp' ) ) {
			return new WP_Error( 'db_error', __( 'Unable to disable TOTP provider for this user.', 'two-factor' ), array( 'status' => 500 ) );
		}

		$this->delete_user_totp_key( $user_id );

		ob_start();
		$this->user_two_factor_options( $user );
		$html = ob_get_clean();

		return array(
			'success' => true,
			'html'    => $html,
		);
	}

	/**
	 * REST API endpoint for setting up TOTP.
	 *
	 * @since 0.8.0
	 *
	 * @param WP_REST_Request $request The Rest Request object.
	 * @return WP_Error|array Array of data on success, WP_Error on error.
	 */
	public function rest_setup_totp( $request ) {
		$user_id = $request['user_id'];
		$user    = get_user_by( 'id', $user_id );

		$key  = $request['key'];
		$code = preg_replace( '/\s+/', '', $request['code'] );

		if ( ! $this->is_valid_key( $key ) ) {
			return new WP_Error( 'invalid_key', __( 'Invalid Two Factor Authentication secret key.', 'two-factor' ), array( 'status' => 400 ) );
		}

		if ( ! $this->is_valid_authcode( $key, $code ) ) {
			return new WP_Error( 'invalid_key_code', __( 'Invalid Two Factor Authentication code.', 'two-factor' ), array( 'status' => 400 ) );
		}

		$saved = $this->save_user_totp_key( $user_id, $key );

		if ( Two_Factor_Secrets::is_key_unavailable_error( $saved ) ) {
			return new WP_Error(
				'two_factor_secrets_key_unavailable',
				__( 'The authenticator app could not be set up because the WordPress Secrets API cannot use its encryption key. Nothing was saved. An administrator needs to restore the site\'s original secrets key (WP_SECRETS_KEY) before authenticator apps can be set up.', 'two-factor' ),
				array( 'status' => 503 )
			);
		}

		if ( is_wp_error( $saved ) ) {
			return new WP_Error(
				'two_factor_secrets_write_failed',
				__( 'The authenticator app could not be set up because the WordPress Secrets API could not store its secret. Nothing was saved. Please try again, or ask an administrator to check the Secrets API.', 'two-factor' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $saved ) {
			return new WP_Error( 'db_error', __( 'Unable to save Two Factor Authentication code. Please re-scan the QR code and enter the code provided by your application.', 'two-factor' ), array( 'status' => 500 ) );
		}

		if ( $request->get_param( 'enable_provider' ) && ! Two_Factor_Core::enable_provider_for_user( $user_id, 'Two_Factor_Totp' ) ) {
			return new WP_Error( 'db_error', __( 'Unable to enable TOTP provider for this user.', 'two-factor' ), array( 'status' => 500 ) );
		}

		ob_start();
		$this->user_two_factor_options( $user );
		$html = ob_get_clean();

		return array(
			'success' => true,
			'html'    => $html,
		);
	}

	/**
	 * Generates a URL that can be used to create a QR code.
	 *
	 * @since 0.8.0
	 *
	 * @param WP_User $user       The user to generate a URL for.
	 * @param string  $secret_key The secret key.
	 *
	 * @return string
	 */
	public static function generate_qr_code_url( $user, $secret_key ) {
		$issuer = get_bloginfo( 'name', 'display' );

		/**
		 * Filters the Issuer for the TOTP.
		 *
		 * Must follow the TOTP format for a "issuer". Do not URL Encode.
		 *
		 * @since 0.8.0
		 *
		 * @see https://github.com/google/google-authenticator/wiki/Key-Uri-Format#issuer
		 *
		 * @param string $issuer The issuer for TOTP.
		 */
		$issuer = apply_filters( 'two_factor_totp_issuer', $issuer );

		/**
		 * Filters the Label for the TOTP.
		 *
		 * Must follow the TOTP format for a "label". Do not URL Encode.
		 *
		 * @since 0.4.7
		 *
		 * @see https://github.com/google/google-authenticator/wiki/Key-Uri-Format#label
		 *
		 * @param string  $totp_title The label for the TOTP.
		 * @param WP_User $user       The User object.
		 * @param string  $issuer     The issuer of the TOTP. This should be the prefix of the result.
		 */
		$totp_title = apply_filters( 'two_factor_totp_title', $issuer . ':' . $user->user_login, $user, $issuer );

		$totp_url = add_query_arg(
			array(
				'secret' => rawurlencode( $secret_key ),
				'issuer' => rawurlencode( $issuer ),
			),
			'otpauth://totp/' . rawurlencode( $totp_title )
		);

		/**
		 * Filters the TOTP generated URL.
		 *
		 * Must follow the TOTP format. Do not URL Encode.
		 *
		 * @since 0.8.0
		 *
		 * @see https://github.com/google/google-authenticator/wiki/Key-Uri-Format
		 *
		 * @param string  $totp_url The TOTP URL.
		 * @param WP_User $user     The user object.
		 */
		$totp_url = apply_filters( 'two_factor_totp_url', $totp_url, $user );
		$totp_url = esc_url_raw( $totp_url, array( 'otpauth' ) );

		return $totp_url;
	}

	/**
	 * Display TOTP options on the user settings page.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_User $user The current user being edited.
	 * @return void
	 *
	 * @codeCoverageIgnore
	 */
	public function user_two_factor_options( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		$state = $this->get_user_totp_key_state( $user->ID );

		if ( is_wp_error( $state ) ) {
			$this->report_unavailable( $user->ID, $state );
		}

		$key = is_string( $state ) ? $state : '';

		wp_localize_script(
			'two-factor-totp-admin',
			'twoFactorTotpAdmin',
			array(
				'restPath'        => Two_Factor_Core::REST_NAMESPACE . '/totp',
				'userId'          => $user->ID,
				'qrCodeAriaLabel' => __( 'Authenticator App QR Code', 'two-factor' ),
			)
		);
		wp_enqueue_script( 'two-factor-totp-admin' );

		?>
		<div id="two-factor-totp-options">
		<?php
		if ( Two_Factor_Secrets::is_key_unavailable_error( $state ) ) :
			// Resetting cannot help: no new secret can be stored until the key is restored,
			// and the reset would leave the user without a second factor meanwhile.
			wp_admin_notice(
				esc_html__( 'The stored authenticator app secret cannot be read because the WordPress Secrets API cannot use its encryption key. An administrator needs to restore the site\'s original secrets key (WP_SECRETS_KEY). Resetting the authenticator app will not help until then.', 'two-factor' ),
				array(
					'type'               => 'error',
					'additional_classes' => array( 'inline', 'two-factor-totp-key-unavailable' ),
				)
			);
		elseif ( is_wp_error( $state ) ) :
			wp_admin_notice(
				esc_html__( 'The stored authenticator app secret cannot be read. Reset the authenticator app to set it up again.', 'two-factor' ),
				array(
					'type'               => 'error',
					'additional_classes' => array( 'inline' ),
				)
			);
			?>
			<p>
				<button type="button" class="button button-secondary reset-totp-key hide-if-no-js">
					<?php esc_html_e( 'Reset authenticator app', 'two-factor' ); ?>
				</button>
			</p>
			<?php
		elseif ( empty( $key ) ) :
			$key      = $this->generate_key();
			$totp_url = $this->generate_qr_code_url( $user, $key );
			?>
			<p class="description">
				<?php esc_html_e( 'Please follow these steps in order to complete setup:', 'two-factor' ); ?>
			</p>
			<ol class="totp-steps">
				<li>
					<?php esc_html_e( 'Install an authenticator app on your desktop/laptop and/or phone. Popular examples are Microsoft Authenticator, Google Authenticator and Authy.', 'two-factor' ); ?>
				</li>
				<li>
					<?php esc_html_e( 'Scan this QR code using the app you installed:', 'two-factor' ); ?>
					<p id="two-factor-qr-code">
						<a href="<?php echo esc_url( $totp_url, array( 'otpauth' ) ); ?>">
							<?php esc_html_e( 'Loading…', 'two-factor' ); ?>
							<img src="<?php echo esc_url( admin_url( 'images/spinner.gif' ) ); ?>" alt="">
						</a>
					</p>
					<p>
						<?php
							esc_html_e(
								'If scanning isn\'t possible or doesn\'t work, click on the QR code or use the secret key shown below to add the account to your chosen app:',
								'two-factor'
							);
						?>
					</p>
					<p>
						<code><?php echo esc_html( $key ); ?></code>
					</p>
				</li>
				<li>
					<p><?php esc_html_e( 'Enter the code generated by the Authenticator app to complete the setup:', 'two-factor' ); ?></p>
					<p>
						<input type="hidden" id="two-factor-totp-key" name="two-factor-totp-key" value="<?php echo esc_attr( $key ); ?>">
						<label for="two-factor-totp-authcode">
							<?php esc_html_e( 'Authentication Code:', 'two-factor' ); ?>
							<?php
								/* translators: Example auth code. */
								$placeholder = sprintf( __( 'eg. %s', 'two-factor' ), '123456' );
							?>
							<input type="text" inputmode="numeric" name="two-factor-totp-authcode" id="two-factor-totp-authcode" class="input" value="" size="20" pattern="[0-9 ]*" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off">
						</label>
						<input type="submit" class="button totp-submit" name="two-factor-totp-submit" value="<?php esc_attr_e( 'Verify', 'two-factor' ); ?>">
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: 1: server date and time */
							esc_html__( 'If the code is rejected, check that your web server time is accurate: %1$s. Your device and server times must match.', 'two-factor' ),
							sprintf(
								'<time class="two-factor-server-datetime-epoch" datetime="%1$s">%2$s (%3$s)</time>',
								esc_attr( wp_date( 'c' ) ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
								esc_html( wp_timezone_string() )
							)
						);
						?>
					</p>
				</li>
			</ol>
			<?php
			wp_localize_script(
				'two-factor-totp-qrcode',
				'twoFactorTotpQrcode',
				array(
					'totpUrl'     => $totp_url,
					'qrCodeLabel' => __( 'Authenticator App QR Code', 'two-factor' ),
				)
			);
			wp_enqueue_script( 'two-factor-totp-qrcode' );
			?>
		<?php else : ?>
			<?php Two_Factor_Secrets::memzero( $key ); ?>
			<p class="description success">
				<?php esc_html_e( 'An authenticator app is currently configured. You will need to re-scan the QR code on all devices if reset.', 'two-factor' ); ?>
			</p>
			<p>
				<button type="button" class="button button-secondary reset-totp-key hide-if-no-js">
					<?php esc_html_e( 'Reset authenticator app', 'two-factor' ); ?>
				</button>
			</p>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get the TOTP secret key for a user.
	 *
	 * Returns an empty string when there is no usable key. Use get_user_totp_key_state() to tell
	 * "no key" apart from "a key exists but cannot be read".
	 *
	 * @since 0.2.0
	 *
	 * @param  int $user_id User ID.
	 *
	 * @return string
	 */
	public function get_user_totp_key( $user_id ) {
		$state = $this->get_user_totp_key_state( $user_id );

		return is_string( $state ) ? $state : '';
	}

	/**
	 * Get the TOTP secret key for a user, distinguishing "none" from "unreadable".
	 *
	 * Precedence: a plaintext user meta value wins (and is lazily migrated when the Secrets API can
	 * be written to); otherwise the Secrets API is consulted when the user has a location marker.
	 *
	 * @since 0.18.0
	 *
	 * @param int  $user_id User ID.
	 * @param bool $migrate Optional. Whether to lazily migrate a plaintext key. Default true.
	 *
	 * @return string|null|WP_Error The key, null when the user has none, or a WP_Error when a key exists but cannot be read.
	 */
	public function get_user_totp_key_state( $user_id, $migrate = true ) {
		return Two_Factor_Secrets::get_stored_secret_state( (int) $user_id, self::SECRET_SLUG, self::SECRET_META_KEY, (bool) $migrate );
	}

	/**
	 * Move a plaintext TOTP key into the Secrets API.
	 *
	 * The plaintext is removed only after the stored secret has been read back and matches.
	 *
	 * @since 0.18.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return true|null|WP_Error True when migrated, null when there is nothing to migrate, WP_Error on failure.
	 */
	public function migrate_user_totp_key( $user_id ) {
		return Two_Factor_Secrets::migrate_stored_secret( (int) $user_id, self::SECRET_SLUG, self::SECRET_META_KEY );
	}

	/**
	 * Set the TOTP secret key for a user.
	 *
	 * Stores into the Secrets API when it is available and writable, verifying by read-back; falls
	 * back to user meta only when the Secrets API is not usable. A Secrets API failure never
	 * results in a plaintext write.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $key TOTP secret key.
	 *
	 * @return int|bool Meta ID if the key did not exist, true on update or Secrets API write, false on failure.
	 */
	public function set_user_totp_key( $user_id, $key ) {
		$result = $this->save_user_totp_key( $user_id, $key );

		return is_wp_error( $result ) ? false : $result;
	}

	/**
	 * Store the TOTP secret key for a user, reporting why a Secrets API write failed.
	 *
	 * Same behavior as set_user_totp_key(), which wraps it.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     TOTP secret key.
	 *
	 * @return int|bool|WP_Error As set_user_totp_key(), or a WP_Error when the Secrets API write or its read-back failed.
	 */
	private function save_user_totp_key( $user_id, $key ) {
		if ( '' === (string) $key ) {
			return $this->delete_user_totp_key( $user_id );
		}

		return Two_Factor_Secrets::save_stored_secret( (int) $user_id, self::SECRET_SLUG, self::SECRET_META_KEY, (string) $key );
	}

	/**
	 * Delete the TOTP secret key for a user.
	 *
	 * @since 0.2.0
	 *
	 * @param  int $user_id User ID.
	 *
	 * @return boolean If the key was deleted successfully.
	 */
	public function delete_user_totp_key( $user_id ) {
		delete_user_meta( $user_id, self::LAST_SUCCESSFUL_LOGIN_META_KEY );

		return Two_Factor_Secrets::delete_stored_secret( (int) $user_id, self::SECRET_SLUG, self::SECRET_META_KEY );
	}

	/**
	 * Get where a user's TOTP key is stored.
	 *
	 * Never migrates.
	 *
	 * @since 0.18.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string One of 'plaintext', 'secrets-api', 'unavailable' or 'none'.
	 */
	public function get_user_totp_key_storage( $user_id ) {
		return Two_Factor_Secrets::get_stored_secret_storage( (int) $user_id, self::SECRET_SLUG, self::SECRET_META_KEY );
	}

	/**
	 * Check if the TOTP secret key has a proper format.
	 *
	 * @since 0.2.0
	 *
	 * @param  string $key TOTP secret key.
	 *
	 * @return boolean
	 */
	public function is_valid_key( $key ) {
		$check = sprintf( '/^[%s]+$/', self::$base_32_chars );

		if ( 1 === preg_match( $check, $key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Validates authentication.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 *
	 * @return bool Whether the user gave a valid code
	 */
	public function validate_authentication( $user ) {
		$code = $this->sanitize_code_from_request( 'authcode', self::DEFAULT_DIGIT_COUNT );
		if ( ! $code ) {
			return false;
		}

		return $this->validate_code_for_user( $user, $code );
	}

	/**
	 * Validates an authentication code for a given user, preventing re-use and older TOTP keys.
	 *
	 * @since 0.8.0
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param string  $code The TOTP token to validate.
	 *
	 * @return bool Whether the code is valid for the user and a newer code has not been used.
	 */
	public function validate_code_for_user( $user, $code ) {
		$key = $this->get_user_totp_key_state( $user->ID );

		if ( is_wp_error( $key ) ) {
			$this->report_unavailable( $user->ID, $key );
			return false;
		}

		if ( null === $key || '' === $key ) {
			return false;
		}

		$valid_timestamp = $this->get_authcode_valid_ticktime( $key, $code );

		Two_Factor_Secrets::memzero( $key );

		if ( ! $valid_timestamp ) {
			return false;
		}

		$last_totp_login = (int) get_user_meta( $user->ID, self::LAST_SUCCESSFUL_LOGIN_META_KEY, true );

		// The TOTP authentication is not valid, if we've seen the same or newer code.
		if ( $last_totp_login && $last_totp_login >= $valid_timestamp ) {
			return false;
		}

		update_user_meta( $user->ID, self::LAST_SUCCESSFUL_LOGIN_META_KEY, $valid_timestamp );

		return true;
	}


	/**
	 * Checks if a given code is valid for a given key, allowing for a certain amount of time drift.
	 *
	 * @since 0.15.0
	 *
	 * @param string $key      The share secret key to use.
	 * @param string $authcode The code to test.
	 * @param string $hash      The hash used to calculate the code.
	 * @param int    $time_step The size of the time step.
	 *
	 * @return bool Whether the code is valid within the time frame.
	 */
	public static function is_valid_authcode( $key, $authcode, $hash = self::DEFAULT_CRYPTO, $time_step = self::DEFAULT_TIME_STEP_SEC ) {
		return (bool) self::get_authcode_valid_ticktime( $key, $authcode, $hash, $time_step );
	}

	/**
	 * Checks if a given code is valid for a given key, allowing for a certain amount of time drift.
	 *
	 * @since 0.15.0
	 *
	 * @param string $key      The share secret key to use.
	 * @param string $authcode The code to test.
	 * @param string $hash      The hash used to calculate the code.
	 * @param int    $time_step The size of the time step.
	 *
	 * @return false|int Returns the timestamp of the authcode on success, False otherwise.
	 */
	public static function get_authcode_valid_ticktime( $key, $authcode, $hash = self::DEFAULT_CRYPTO, $time_step = self::DEFAULT_TIME_STEP_SEC ) {
		/**
		 * Filter the maximum ticks to allow when checking valid codes.
		 *
		 * Ticks are the allowed offset from the correct time in 30 second increments,
		 * so the default of 4 allows codes that are two minutes to either side of server time.
		 *
		 * @since 0.2.0
		 * @deprecated 0.7.0 Use {@see 'two_factor_totp_time_step_allowance'} instead.
		 *
		 * @param int $max_ticks Max ticks of time correction to allow. Default 4.
		 */
		$max_ticks = apply_filters_deprecated( 'two-factor-totp-time-step-allowance', array( self::DEFAULT_TIME_STEP_ALLOWANCE ), '0.7.0', 'two_factor_totp_time_step_allowance' );

		/**
		 * Filters the maximum ticks to allow when checking valid codes.
		 *
		 * Ticks are the allowed offset from the correct time in 30 second increments,
		 * so the default of 4 allows codes that are two minutes to either side of server time.
		 *
		 * @since 0.7.0
		 *
		 * @param int $max_ticks Max ticks of time correction to allow. Default 4.
		 */
		$max_ticks = apply_filters( 'two_factor_totp_time_step_allowance', self::DEFAULT_TIME_STEP_ALLOWANCE );

		// Array of all ticks to allow, sorted using absolute value to test closest match first.
		$ticks = range( - $max_ticks, $max_ticks );
		usort( $ticks, array( __CLASS__, 'abssort' ) );

		$time = (int) floor( self::time() / $time_step );

		$digits = strlen( $authcode );

		foreach ( $ticks as $offset ) {
			$log_time = (int) ( $time + $offset );
			if ( hash_equals( self::calc_totp( $key, $log_time, $digits, $hash, $time_step ), $authcode ) ) {
				// Return the tick timestamp.
				return (int) ( $log_time * self::DEFAULT_TIME_STEP_SEC );
			}
		}

		return false;
	}

	/**
	 * Generates key
	 *
	 * @since 0.2.0
	 *
	 * @param int $bitsize Nume of bits to use for key.
	 *
	 * @return string $bitsize long string composed of available base32 chars.
	 */
	public static function generate_key( $bitsize = self::DEFAULT_KEY_BIT_SIZE ) {
		$bytes  = ceil( $bitsize / 8 );
		$secret = wp_generate_password( (int) $bytes, true, true );

		return self::base32_encode( $secret );
	}

	/**
	 * Pack stuff. We're currently only using this to pack integers, however the generic `pack` method can handle mixed.
	 *
	 * @since 0.2.0
	 *
	 * @param int $value The value to be packed.
	 *
	 * @return string Binary packed string.
	 */
	public static function pack64( int $value ): string {
		// Native 64-bit support (modern PHP on 64-bit builds).
		if ( 8 === PHP_INT_SIZE ) {
			return pack( 'J', $value );
		}

		// 32-bit PHP fallback
		$higher = ( $value >> 32 ) & 0xFFFFFFFF;
		$lower  = $value & 0xFFFFFFFF;

		return pack( 'NN', $higher, $lower );
	}

	/**
	 * Pad a short secret with bytes from the same until it's the correct length
	 * for hashing.
	 *
	 * @since 0.15.0
	 *
	 * @param string $secret Secret key to pad.
	 * @param int    $length Byte length of the desired padded secret.
	 *
	 * @throws InvalidArgumentException If the secret or length are invalid.
	 *
	 * @return string
	 */
	protected static function pad_secret( $secret, $length ) {
		if ( empty( $secret ) ) {
			throw new InvalidArgumentException( 'Secret must be non-empty!' );
		}

		$length = intval( $length );
		if ( $length <= 0 ) {
			throw new InvalidArgumentException( 'Padding length must be non-zero' );
		}

		return str_pad( $secret, $length, $secret, STR_PAD_RIGHT );
	}

	/**
	 * Calculate a valid code given the shared secret key
	 *
	 * @since 0.2.0
	 *
	 * @param string $key        The shared secret key to use for calculating code.
	 * @param mixed  $step_count The time step used to calculate the code, which is the floor of time() divided by step size.
	 * @param int    $digits     The number of digits in the returned code.
	 * @param string $hash       The hash used to calculate the code.
	 * @param int    $time_step  The size of the time step.
	 *
	 * @throws InvalidArgumentException If the hash type is invalid.
	 *
	 * @return string The totp code
	 */
	public static function calc_totp( $key, $step_count = false, $digits = self::DEFAULT_DIGIT_COUNT, $hash = self::DEFAULT_CRYPTO, $time_step = self::DEFAULT_TIME_STEP_SEC ) {
		$secret = self::base32_decode( $key );

		switch ( $hash ) {
			case 'sha1':
				$secret = self::pad_secret( $secret, 20 );
				break;
			case 'sha256':
				$secret = self::pad_secret( $secret, 32 );
				break;
			case 'sha512':
				$secret = self::pad_secret( $secret, 64 );
				break;
			default:
				throw new InvalidArgumentException( 'Invalid hash type specified!' );
		}

		if ( false === $step_count ) {
			$step_count = floor( self::time() / $time_step );
		}

		$timestamp = self::pack64( $step_count );

		$hash = hash_hmac( $hash, $timestamp, $secret, true );

		$offset = ord( $hash[ strlen( $hash ) - 1 ] ) & 0xf;

		$code = (
				( ( ord( $hash[ $offset + 0 ] ) & 0x7f ) << 24 ) |
				( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) |
				( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 ) |
				( ord( $hash[ $offset + 3 ] ) & 0xff )
			) % pow( 10, $digits );

		return str_pad( (string) $code, $digits, '0', STR_PAD_LEFT );
	}

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 *
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		// Available if a plaintext key is saved, or the key lives in a Secrets API network we can reach.
		return Two_Factor_Secrets::is_stored_secret_reachable( (int) $user->ID, self::SECRET_SLUG, self::SECRET_META_KEY );
	}

	/**
	 * Announce that a user's stored secret cannot be used.
	 *
	 * @since 0.18.0
	 *
	 * @param int      $user_id User ID.
	 * @param WP_Error $error   Why the secret is unavailable.
	 *
	 * @return void
	 */
	private function report_unavailable( $user_id, WP_Error $error ) {
		/**
		 * Fires when a user's TOTP secret exists but cannot be read.
		 *
		 * Neither the secret nor its plaintext is passed to listeners.
		 *
		 * @since 0.18.0
		 *
		 * @param int      $user_id User ID.
		 * @param string   $slug    Secret slug, "totp".
		 * @param WP_Error $error   Why the secret is unavailable.
		 */
		do_action( 'two_factor_secret_unavailable', (int) $user_id, self::SECRET_SLUG, $error );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 *
	 * @codeCoverageIgnore
	 */
	public function authentication_page( $user ) {
		require_once ABSPATH . '/wp-admin/includes/template.php';

		$state = $this->get_user_totp_key_state( $user->ID );

		if ( is_wp_error( $state ) ) {
			$this->report_unavailable( $user->ID, $state );
		} elseif ( is_string( $state ) ) {
			Two_Factor_Secrets::memzero( $state );
		}
		?>
		<?php
		/** This action is documented in providers/class-two-factor-backup-codes.php */
		do_action( 'two_factor_before_authentication_prompt', $this );

		if ( is_wp_error( $state ) ) {
			/** This action is documented in providers/class-two-factor-backup-codes.php */
			do_action( 'two_factor_after_authentication_prompt', $this );
			?>
			<p class="two-factor-prompt two-factor-totp-unavailable">
				<?php esc_html_e( 'Your authenticator app secret is currently unavailable on this site. Please use another method, such as a recovery code, or contact a site administrator.', 'two-factor' ); ?>
			</p>
			<?php
			/** This action is documented in providers/class-two-factor-backup-codes.php */
			do_action( 'two_factor_after_authentication_input', $this );
			return;
		}
		?>
		<p class="two-factor-prompt">
			<?php esc_html_e( 'Enter the code generated by your authenticator app.', 'two-factor' ); ?>
		</p>
		<?php
		/** This action is documented in providers/class-two-factor-backup-codes.php */
		do_action( 'two_factor_after_authentication_prompt', $this );
		?>
		<p>
			<label for="authcode"><?php esc_html_e( 'Authentication Code:', 'two-factor' ); ?></label>
			<input type="text" inputmode="numeric" name="authcode" id="authcode" class="input authcode" value="" size="20" pattern="[0-9 ]*" placeholder="123 456" autocomplete="one-time-code" data-digits="<?php echo esc_attr( (string) self::DEFAULT_DIGIT_COUNT ); ?>">
		</p>
		<?php
		/** This action is documented in providers/class-two-factor-backup-codes.php */
		do_action( 'two_factor_after_authentication_input', $this );
		?>
		<?php
		wp_enqueue_script( 'two-factor-login' );

		submit_button( __( 'Verify', 'two-factor' ) );
	}

	/**
	 * Returns a base32 encoded string.
	 *
	 * @since 0.2.0
	 *
	 * @param string $input String to be encoded using base32.
	 *
	 * @return string base32 encoded string without padding.
	 */
	public static function base32_encode( $input ) {
		if ( empty( $input ) ) {
			return '';
		}

		$binary_string = '';

		foreach ( str_split( $input ) as $character ) {
			$binary_string .= str_pad( base_convert( (string) ord( $character ), 10, 2 ), 8, '0', STR_PAD_LEFT );
		}

		$five_bit_sections = str_split( $binary_string, 5 );
		$base32_string     = '';

		foreach ( $five_bit_sections as $five_bit_section ) {
			$base32_string .= self::$base_32_chars[ (int) base_convert( str_pad( $five_bit_section, 5, '0' ), 2, 10 ) ];
		}

		return $base32_string;
	}

	/**
	 * Decode a base32 string and return a binary representation
	 *
	 * @since 0.2.0
	 *
	 * @param string $base32_string The base 32 string to decode.
	 *
	 * @throws Exception If string contains non-base32 characters.
	 *
	 * @return string Binary representation of decoded string
	 */
	public static function base32_decode( $base32_string ) {

		$base32_string = strtoupper( $base32_string );

		if ( ! preg_match( '/^[' . self::$base_32_chars . ']+$/', $base32_string, $match ) ) {
			throw new Exception( 'Invalid characters in the base32 string.' );
		}

		$l      = strlen( $base32_string );
		$n      = 0;
		$j      = 0;
		$binary = '';

		for ( $i = 0; $i < $l; $i++ ) {

			$n  = $n << 5; // Move buffer left by 5 to make room.
			$n  = $n + strpos( self::$base_32_chars, $base32_string[ $i ] );    // Add value into buffer.
			$j += 5; // Keep track of number of bits in buffer.

			if ( $j >= 8 ) {
				$j      -= 8;
				$binary .= chr( ( $n & ( 0xFF << $j ) ) >> $j );
			}
		}

		return $binary;
	}

	/**
	 * Used with usort to sort an array by distance from 0
	 *
	 * @since 0.2.0
	 *
	 * @param int $a First array element.
	 * @param int $b Second array element.
	 *
	 * @return int -1, 0, or 1 as needed by usort
	 */
	private static function abssort( $a, $b ) {
		$a = abs( $a );
		$b = abs( $b );
		if ( $a === $b ) {
			return 0;
		}
		return ( $a < $b ) ? -1 : 1;
	}

	/**
	 * Declare the TOTP key as a secret, so core stores it with the Secrets API when it can.
	 *
	 * @since 0.18.0
	 *
	 * @return array<string, string> Secret slug => user meta key holding the plaintext value.
	 */
	public static function user_secret_meta_keys() {
		return array( self::SECRET_SLUG => self::SECRET_META_KEY );
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
			self::SECRET_META_KEY,
			self::SECRET_NETWORK_META_KEY,
			self::LAST_SUCCESSFUL_LOGIN_META_KEY,
		);
	}
}
