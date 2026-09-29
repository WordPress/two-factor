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
	 * The site transient caching whether any user has a secret that is currently unreachable.
	 *
	 * @since 0.18.0
	 *
	 * @var string
	 */
	const AFFECTED_USERS_TRANSIENT = 'two_factor_totp_affected_users';

	/**
	 * How long, in seconds, the affected-users result is cached.
	 *
	 * @since 0.18.0
	 *
	 * @var int
	 */
	const AFFECTED_USERS_CACHE_TTL = 300;

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
	 * Register the hooks that manage stored secrets for the lifetime of a user.
	 *
	 * These run whether or not TOTP is enabled on the site: a site that turns TOTP off
	 * after users enrolled still holds their secrets, and must still delete them with
	 * the user, warn about unreachable ones and report their storage in Site Health.
	 * They are static so that registering them does not instantiate the provider,
	 * which would expose its REST routes while it is disabled.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function register_secret_lifecycle_hooks() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice_secrets_api_missing' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'admin_notice_secrets_api_missing' ) );

		// On multisite, `delete_user` also fires when a user is only removed from one site, so wait for the network-level deletion.
		if ( is_multisite() ) {
			add_action( 'wpmu_delete_user', array( __CLASS__, 'delete_user_secrets_on_user_deletion' ) );
		} else {
			add_action( 'delete_user', array( __CLASS__, 'delete_user_secrets_on_user_deletion' ) );
		}
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

		if ( ! $this->set_user_totp_key( $user_id, $key ) ) {
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
		if ( is_wp_error( $state ) ) :
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
		$plaintext = (string) get_user_meta( $user_id, self::SECRET_META_KEY, true );

		if ( '' !== $plaintext ) {
			if ( $migrate && Two_Factor_Secrets::can_write( $user_id ) ) {
				$this->migrate_user_totp_key( $user_id );
			}

			return $plaintext;
		}

		if ( '' === (string) get_user_meta( $user_id, self::SECRET_NETWORK_META_KEY, true ) ) {
			return null;
		}

		return Two_Factor_Secrets::get_user_secret( $user_id, self::SECRET_SLUG );
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
		$plaintext = (string) get_user_meta( $user_id, self::SECRET_META_KEY, true );

		if ( '' === $plaintext ) {
			return null;
		}

		if ( ! Two_Factor_Secrets::can_write( $user_id ) ) {
			return new WP_Error(
				'two_factor_secrets_not_writable',
				__( 'The Secrets API cannot be written to, so the secret was not migrated.', 'two-factor' )
			);
		}

		$result = Two_Factor_Secrets::set_user_secret( $user_id, self::SECRET_SLUG, $plaintext );

		if ( is_wp_error( $result ) ) {
			$this->fire_migration_failed( $user_id, $result );
			return $result;
		}

		$readback = Two_Factor_Secrets::get_user_secret( $user_id, self::SECRET_SLUG );

		if ( ! is_string( $readback ) || ! hash_equals( $plaintext, $readback ) ) {
			$error = is_wp_error( $readback )
				? $readback
				: new WP_Error(
					'two_factor_secrets_migration_mismatch',
					__( 'The stored secret did not match the original, so the migration was rolled back.', 'two-factor' )
				);

			Two_Factor_Secrets::delete_user_secret( $user_id, self::SECRET_SLUG );
			$this->fire_migration_failed( $user_id, $error );

			return $error;
		}

		delete_user_meta( $user_id, self::SECRET_META_KEY );
		Two_Factor_Secrets::memzero( $readback );
		self::clear_affected_users_cache();

		/**
		 * Fires after a user's TOTP secret was moved into the Secrets API.
		 *
		 * @since 0.18.0
		 *
		 * @param int    $user_id User ID.
		 * @param string $slug    Secret slug, "totp".
		 */
		do_action( 'two_factor_secrets_migrated', $user_id, self::SECRET_SLUG );

		return true;
	}

	/**
	 * Fire the migration failure action.
	 *
	 * @since 0.18.0
	 *
	 * @param int      $user_id User ID.
	 * @param WP_Error $error   The failure.
	 *
	 * @return void
	 */
	private function fire_migration_failed( $user_id, $error ) {
		/**
		 * Fires when moving a user's TOTP secret into the Secrets API failed.
		 *
		 * The plaintext copy is kept, so the user can still log in. The error never contains the secret.
		 *
		 * @since 0.18.0
		 *
		 * @param int      $user_id User ID.
		 * @param string   $slug    Secret slug, "totp".
		 * @param WP_Error $error   The failure.
		 */
		do_action( 'two_factor_secrets_migration_failed', $user_id, self::SECRET_SLUG, $error );
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
		if ( '' === (string) $key ) {
			return $this->delete_user_totp_key( $user_id );
		}

		if ( Two_Factor_Secrets::can_write( $user_id ) ) {
			$result = Two_Factor_Secrets::set_user_secret( $user_id, self::SECRET_SLUG, $key );

			if ( is_wp_error( $result ) ) {
				return false;
			}

			$readback = Two_Factor_Secrets::get_user_secret( $user_id, self::SECRET_SLUG );

			if ( ! is_string( $readback ) || ! hash_equals( (string) $key, $readback ) ) {
				Two_Factor_Secrets::delete_user_secret( $user_id, self::SECRET_SLUG );
				return false;
			}

			Two_Factor_Secrets::memzero( $readback );
			delete_user_meta( $user_id, self::SECRET_META_KEY );
			self::clear_affected_users_cache();

			return true;
		}

		$result = update_user_meta( $user_id, self::SECRET_META_KEY, $key );

		// Clear any stale marker or secret so the plaintext value is unambiguous.
		Two_Factor_Secrets::delete_user_secret( $user_id, self::SECRET_SLUG );
		self::clear_affected_users_cache();

		return $result;
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
		delete_user_meta( $user_id, self::SECRET_META_KEY );

		$secret = Two_Factor_Secrets::delete_user_secret( $user_id, self::SECRET_SLUG );
		self::clear_affected_users_cache();

		return true === $secret
			&& '' === (string) get_user_meta( $user_id, self::SECRET_META_KEY, true )
			&& '' === (string) get_user_meta( $user_id, self::SECRET_NETWORK_META_KEY, true );
	}

	/**
	 * Delete a user's TOTP data when the user account is deleted.
	 *
	 * Wrapper around delete_user_totp_key() so the deletion actions get a callback that returns nothing.
	 *
	 * @since 0.18.0
	 *
	 * @param int $user_id ID of the user being deleted.
	 *
	 * @return void
	 */
	public static function delete_user_secrets_on_user_deletion( $user_id ) {
		self::get_instance()->delete_user_totp_key( $user_id );
	}

	/**
	 * Whether any user has a secret in the Secrets API that this site cannot currently reach.
	 *
	 * The result is cached briefly in a site transient.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function has_affected_users() {
		$cached = get_site_transient( self::AFFECTED_USERS_TRANSIENT );

		if ( 'yes' === $cached || 'no' === $cached ) {
			return 'yes' === $cached;
		}

		$args = array(
			'blog_id'      => 0,
			'meta_key'     => self::SECRET_NETWORK_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single-row lookup, result cached.
			'meta_compare' => 'EXISTS',
			'number'       => 1,
			'fields'       => 'ID',
			'count_total'  => false,
		);

		if ( Two_Factor_Secrets::is_api_present() ) {
			if ( is_multisite() ) {
				$args['meta_value']   = (string) get_current_network_id(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single-row lookup, result cached.
				$args['meta_compare'] = '!=';
				$affected             = ! empty( get_users( $args ) );
			} else {
				$affected = false;
			}
		} else {
			$affected = ! empty( get_users( $args ) );
		}

		set_site_transient( self::AFFECTED_USERS_TRANSIENT, $affected ? 'yes' : 'no', self::AFFECTED_USERS_CACHE_TTL );

		return $affected;
	}

	/**
	 * Forget the cached affected-users result.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function clear_affected_users_cache() {
		delete_site_transient( self::AFFECTED_USERS_TRANSIENT );
	}

	/**
	 * Warn administrators that some users' authenticator secrets are unreachable.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function admin_notice_secrets_api_missing() {
		$capability = is_network_admin() ? 'manage_network_options' : 'manage_options';

		if ( ! current_user_can( $capability ) || ! self::has_affected_users() ) {
			return;
		}

		wp_admin_notice(
			esc_html__( 'Authenticator app secrets for one or more users were stored with the WordPress Secrets API, which is no longer available on this site. Those users cannot use their authenticator app until it is restored. Re-activate the Secrets API, or reset those users\' authenticator app so they can set it up again.', 'two-factor' ),
			array(
				'type'        => 'error',
				'dismissible' => false,
			)
		);
	}

	/**
	 * Register the Site Health test for TOTP secret storage.
	 *
	 * @since 0.18.0
	 *
	 * @param array $tests Site Health tests.
	 *
	 * @return array
	 */
	public static function register_site_health_test( $tests ) {
		$tests['direct']['two_factor_totp_secret_storage'] = array(
			'label' => __( 'Authenticator app secret storage', 'two-factor' ),
			'test'  => array( __CLASS__, 'site_health_secret_storage' ),
		);

		return $tests;
	}

	/**
	 * Whether any user still has a plaintext TOTP secret in user meta.
	 *
	 * Not cached.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function has_plaintext_users() {
		$users = get_users(
			array(
				'blog_id'      => 0,
				'meta_key'     => self::SECRET_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single-row lookup for Site Health.
				'meta_value'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single-row lookup for Site Health.
				'meta_compare' => '!=',
				'number'       => 1,
				'fields'       => 'ID',
				'count_total'  => false,
			)
		);

		return ! empty( $users );
	}

	/**
	 * Site Health test result for TOTP secret storage.
	 *
	 * @since 0.18.0
	 *
	 * @return array
	 */
	public static function site_health_secret_storage() {
		$result = array(
			'label'       => '',
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'two-factor' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => 'two_factor_totp_secret_storage',
		);

		if ( self::has_affected_users() ) {
			$result['label']          = __( 'Some authenticator app secrets are unavailable', 'two-factor' );
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['description']    = sprintf(
				'<p>%s</p>',
				esc_html__( 'Some users\' authenticator app secrets were stored with the WordPress Secrets API, which this site cannot currently reach. Those users cannot use their authenticator app. Re-activate the Secrets API, or reset those users\' authenticator app so they can set it up again. Secrets are never moved back out of the Secrets API.', 'two-factor' )
			);

			return $result;
		}

		if ( ! Two_Factor_Secrets::is_api_present() ) {
			$result['label']       = __( 'Authenticator app secrets are stored in user meta', 'two-factor' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'TOTP secrets are stored in user meta; the WordPress Secrets API, when available, will be used automatically.', 'two-factor' )
			);

			return $result;
		}

		if ( ! self::has_plaintext_users() ) {
			$result['label']       = __( 'Authenticator app secrets are stored securely', 'two-factor' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %s: name of the secrets storage provider. */
					esc_html__( 'Authenticator app secrets are stored with the WordPress Secrets API (%s).', 'two-factor' ),
					esc_html( Two_Factor_Secrets::provider_label() )
				)
			);

			return $result;
		}

		if ( Two_Factor_Secrets::can_write() ) {
			$result['label']          = __( 'Some authenticator app secrets are not yet migrated', 'two-factor' );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['description']    = sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %s: WP-CLI migrate command. */
					esc_html__( 'Some authenticator app secrets are still stored in user meta. They move to the WordPress Secrets API when those users next log in, or you can run %s to migrate them all now.', 'two-factor' ),
					'<code>wp two-factor secrets migrate</code>'
				)
			);

			return $result;
		}

		$result['label']       = __( 'Authenticator app secrets remain in user meta', 'two-factor' );
		$result['description'] = sprintf(
			'<p>%s</p>',
			esc_html__( 'Migration to the WordPress Secrets API is disabled by the two_factor_use_secrets_api filter or a read-only secrets provider, so authenticator app secrets remain in user meta.', 'two-factor' )
		);

		return $result;
	}

	/**
	 * Count users by where their TOTP key is stored.
	 *
	 * @since 0.18.0
	 *
	 * @return array{plaintext: int, migrated: int, affected: int}
	 */
	public static function count_users_by_storage() {
		$count = function ( $meta_key, $compare, $value = null ) {
			$args = array(
				'blog_id'      => 0,
				'fields'       => 'ID',
				'number'       => 1,
				'count_total'  => true,
				'meta_key'     => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- CLI reporting.
				'meta_compare' => $compare,
			);

			if ( null !== $value ) {
				$args['meta_value'] = $value; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- CLI reporting.
			}

			$query = new WP_User_Query( $args );

			return (int) $query->get_total();
		};

		$affected = Two_Factor_Secrets::is_api_present()
			? $count( self::SECRET_NETWORK_META_KEY, '!=', (string) get_current_network_id() )
			: $count( self::SECRET_NETWORK_META_KEY, 'EXISTS' );

		return array(
			'plaintext' => $count( self::SECRET_META_KEY, '!=', '' ),
			'migrated'  => $count( self::SECRET_NETWORK_META_KEY, 'EXISTS' ),
			'affected'  => $affected,
		);
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
		if ( '' !== (string) get_user_meta( $user_id, self::SECRET_META_KEY, true ) ) {
			return 'plaintext';
		}

		if ( '' === (string) get_user_meta( $user_id, self::SECRET_NETWORK_META_KEY, true ) ) {
			return 'none';
		}

		$secret = Two_Factor_Secrets::get_user_secret( $user_id, self::SECRET_SLUG );

		if ( is_string( $secret ) ) {
			Two_Factor_Secrets::memzero( $secret );
			return 'secrets-api';
		}

		return 'unavailable';
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
		if ( '' !== (string) get_user_meta( $user->ID, self::SECRET_META_KEY, true ) ) {
			return true;
		}

		$marker = (string) get_user_meta( $user->ID, self::SECRET_NETWORK_META_KEY, true );

		return '' !== $marker
			&& Two_Factor_Secrets::is_api_present()
			&& get_current_network_id() === (int) $marker;
	}

	/**
	 * Whether the user enrolled this provider but the stored key cannot currently be used.
	 *
	 * True when the key lives in the Secrets API and the API is missing, or the key belongs to a
	 * different network. Does not decrypt anything.
	 *
	 * @since 0.18.0
	 *
	 * @param WP_User $user WP_User object of the user.
	 *
	 * @return boolean
	 */
	public function is_enrolled_but_unavailable_for_user( $user ) {
		if ( '' !== (string) get_user_meta( $user->ID, self::SECRET_META_KEY, true ) ) {
			return false;
		}

		$marker = (string) get_user_meta( $user->ID, self::SECRET_NETWORK_META_KEY, true );

		if ( '' === $marker ) {
			return false;
		}

		return ! Two_Factor_Secrets::is_api_present() || get_current_network_id() !== (int) $marker;
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
	 * Delete every user's TOTP secret held in the Secrets API during plugin uninstall.
	 *
	 * When the Secrets API is absent at uninstall time the secrets are orphaned in the store, which
	 * is acceptable because they cannot be reached from here.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function uninstall_user_data() {
		if ( ! Two_Factor_Secrets::is_api_present() ) {
			return;
		}

		$seen = array();

		do {
			// The marker is removed with each secret, so the same offset always yields the next page.
			$user_ids = ( new WP_User_Query(
				array(
					'blog_id'      => 0,
					'fields'       => 'ID',
					'number'       => 100,
					'offset'       => 0,
					'orderby'      => 'ID',
					'meta_key'     => self::SECRET_NETWORK_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall only.
					'meta_compare' => 'EXISTS',
					'count_total'  => false,
				)
			) )->get_results();

			$new_ids = array_diff( array_map( 'intval', $user_ids ), $seen );

			foreach ( $new_ids as $user_id ) {
				$seen[] = $user_id;
				Two_Factor_Secrets::delete_user_secret( $user_id, self::SECRET_SLUG );
			}
		} while ( ! empty( $new_ids ) );
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
