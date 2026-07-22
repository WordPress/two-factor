<?php
/**
 * Class for creating a WebAuthn (Passkeys) provider.
 *
 * @package Two_Factor
 */

use lbuchs\WebAuthn\WebAuthn;

/**
 * Class Two_Factor_Passkey
 *
 * @since 0.17.0
 */
class Two_Factor_Passkey extends Two_Factor_Provider {

	const PASSKEYS_META_KEY = '_two_factor_passkeys';

	protected function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'two_factor_user_options_' . __CLASS__, array( $this, 'user_two_factor_options' ) );
		parent::__construct();
	}

	public function get_label() {
		return _x( 'Passkeys', 'Provider Label', 'two-factor' );
	}

	public function get_alternative_provider_label() {
		return __( 'Use your Passkey', 'two-factor' );
	}

	public function enqueue_assets() {
		wp_enqueue_script(
			'two-factor-passkey',
			plugins_url( 'js/passkeys.js', dirname( __FILE__ ) ),
			array(),
			TWO_FACTOR_VERSION,
			true
		);

		wp_localize_script( 'two-factor-passkey', 'twoFactorPasskeyData', array(
			'restUrl' => esc_url_raw( rest_url( Two_Factor_Core::REST_NAMESPACE . '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		) );
	}

	/**
	 * Helper to configure WebAuthn
	 */
	private function get_webauthn() {
		return new WebAuthn('Two Factor', wp_parse_url( site_url(), PHP_URL_HOST ), array( 'none' ));
	}

	public function register_rest_routes() {
		register_rest_route(
			Two_Factor_Core::REST_NAMESPACE,
			'/passkeys/options',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get_options' ),
					'permission_callback' => '__return_true', 
					'args'                => array(
						'action' => array(
							'required' => true,
							'type'     => 'string',
						),
						'username' => array(
							'required' => false,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			Two_Factor_Core::REST_NAMESPACE,
			'/passkeys/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_register_passkey' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
	}

	public function rest_get_options( $request ) {
		$action = $request->get_param( 'action' );
		$webauthn = $this->get_webauthn();

		if ( 'register' === $action ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error( 'unauthorized', 'Must be logged in to register.', array( 'status' => 401 ) );
			}
			$user = wp_get_current_user();
			
			$createArgs = $webauthn->getCreateArgs( (string) $user->ID, $user->user_login, $user->display_name );
			$challenge = $webauthn->getChallenge();
			if ( is_object( $challenge ) && method_exists( $challenge, 'getBinaryString' ) ) {
				$challenge_str = $challenge->getBinaryString();
			} else {
				$challenge_str = (string) $challenge;
			}
			set_transient( 'webauthn_challenge_' . $user->ID, base64_encode( $challenge_str ), 15 * MINUTE_IN_SECONDS );
			
			return rest_ensure_response( $createArgs );
		}

		if ( 'authenticate' === $action ) {
			$username = $request->get_param( 'username' );
			$user = get_user_by( 'login', $username ) ?: get_user_by( 'email', $username );

			if ( ! $user ) {
				// Prevent username enumeration by returning mock options
				$getArgs = $webauthn->getGetArgs();
				return rest_ensure_response( array( 'args' => $getArgs, 'session_id' => 'mock' ) );
			}

			$keys = get_user_meta( $user->ID, self::PASSKEYS_META_KEY, true );
			if ( empty( $keys ) ) {
				$getArgs = $webauthn->getGetArgs();
				return rest_ensure_response( array( 'args' => $getArgs, 'session_id' => 'mock' ) );
			}

			// Pass existing credentials to restrict authentication to registered keys
			$credentialIds = array();
			foreach ( $keys as $key ) {
				$credentialIds[] = hex2bin( $key['id'] );
			}
			$getArgs = $webauthn->getGetArgs( $credentialIds );
			$challenge = $webauthn->getChallenge();
			if ( is_object( $challenge ) && method_exists( $challenge, 'getBinaryString' ) ) {
				$challenge_str = $challenge->getBinaryString();
			} else {
				$challenge_str = (string) $challenge;
			}
			
			$session_id = wp_generate_password( 20, false );
			set_transient( 'webauthn_login_' . $session_id, array( 'challenge' => base64_encode( $challenge_str ), 'user_id' => $user->ID ), 15 * MINUTE_IN_SECONDS );

			return rest_ensure_response( array( 'args' => $getArgs, 'session_id' => $session_id ) );
		}

		return new WP_Error( 'invalid_action', 'Invalid action', array( 'status' => 400 ) );
	}

	public function rest_register_passkey( $request ) {
		$user = wp_get_current_user();
		$challenge_b64 = get_transient( 'webauthn_challenge_' . $user->ID );
		if ( ! $challenge_b64 ) {
			return new WP_Error( 'expired_challenge', 'Challenge expired', array( 'status' => 400 ) );
		}
		delete_transient( 'webauthn_challenge_' . $user->ID );
		$challenge = base64_decode( $challenge_b64 );

		$webauthn = $this->get_webauthn();
		$client_data_json = base64_decode( strtr( $request->get_param( 'response' )['clientDataJSON'], '-_', '+/' ) );
		$attestation_object = base64_decode( strtr( $request->get_param( 'response' )['attestationObject'], '-_', '+/' ) );

		try {
			$data = $webauthn->processCreate( $client_data_json, $attestation_object, $challenge, true, true, false );
			
			$keys = get_user_meta( $user->ID, self::PASSKEYS_META_KEY, true ) ?: array();
			$keys[] = array(
				'id' => bin2hex($data->credentialId),
				'publicKey' => bin2hex($data->credentialPublicKey),
				'signatureCounter' => $data->signatureCounter,
			);
			update_user_meta( $user->ID, self::PASSKEYS_META_KEY, $keys );

			// Automatically enable the provider now that it's configured.
			Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Passkey' );

			return rest_ensure_response( array( 'success' => true ) );
		} catch ( Exception $e ) {
			error_log( 'WebAuthn Registration Error: ' . $e->getMessage() );
			return new WP_Error( 'webauthn_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function is_available_for_user( $user ) {
		$keys = get_user_meta( $user->ID, self::PASSKEYS_META_KEY, true );
		return ! empty( $keys );
	}

	public function authentication_page( $user ) {
		// Option 2 stub, the JS handles Option 1 currently.
		?>
		<p><?php esc_html_e( 'Authenticate using your registered passkey.', 'two-factor' ); ?></p>
		<p>
			<button type="button" class="button button-primary button-large" id="two-factor-passkey-auth-btn" data-username="<?php echo esc_attr( $user->user_login ); ?>">
				<?php esc_html_e( 'Use Passkey', 'two-factor' ); ?>
			</button>
		</p>
		<?php
	}

	public function validate_passwordless_assertion( $user, $assertion_json ) {
		$assertion = json_decode( $assertion_json, true );
		if ( ! $assertion || empty( $assertion['session_id'] ) ) {
			return new WP_Error( 'invalid_data', 'Missing assertion data.' );
		}

		$session_data = get_transient( 'webauthn_login_' . $assertion['session_id'] );
		if ( ! $session_data || $session_data['user_id'] != $user->ID ) {
			return new WP_Error( 'invalid_session', 'Invalid or expired passkey session.' );
		}
		delete_transient( 'webauthn_login_' . $assertion['session_id'] );

		$webauthn = $this->get_webauthn();
		$keys = get_user_meta( $user->ID, self::PASSKEYS_META_KEY, true );
		$matched_key = null;
		
		$credentialId = base64_decode( $assertion['rawId'] );
		foreach ( $keys as $key ) {
			if ( hex2bin( $key['id'] ) === $credentialId ) {
				$matched_key = $key;
				break;
			}
		}

		if ( ! $matched_key ) {
			return new WP_Error( 'key_not_found', 'Credential not found for this user.' );
		}

		try {
			$client_data_json = base64_decode( strtr( $assertion['response']['clientDataJSON'], '-_', '+/' ) );
			$authenticator_data = base64_decode( strtr( $assertion['response']['authenticatorData'], '-_', '+/' ) );
			$signature = base64_decode( strtr( $assertion['response']['signature'], '-_', '+/' ) );
			$public_key = hex2bin( $matched_key['publicKey'] );

			$webauthn->processGet(
				$client_data_json, 
				$authenticator_data, 
				$signature, 
				$public_key, 
				base64_decode( $session_data['challenge'] )
			);

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'webauthn_error', $e->getMessage() );
		}
	}

	/**
	 * Validate the authentication for this provider.
	 *
	 * @since 0.17.0
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function validate_authentication( $user ) {
		if ( empty( $_POST['two_factor_passkey_assertion'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$assertion_json = wp_unslash( $_POST['two_factor_passkey_assertion'] );
		$is_valid = $this->validate_passwordless_assertion( $user, $assertion_json );

		return ( true === $is_valid );
	}

	/**
	 * Render the user options for this provider.
	 *
	 * @since 0.17.0
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function user_two_factor_options( $user ) {
		$keys = get_user_meta( $user->ID, self::PASSKEYS_META_KEY, true );
		
		if ( $keys ) {
			/* translators: %s: number of passkeys */
			echo '<p>' . sprintf( esc_html( _n( 'You have %s passkey registered.', 'You have %s passkeys registered.', count( $keys ), 'two-factor' ) ), count( $keys ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'No passkeys registered.', 'two-factor' ) . '</p>';
		}

		if ( get_current_user_id() === $user->ID ) {
			?>
			<p>
				<button type="button" class="button" id="two-factor-passkey-register-btn">
					<?php esc_html_e( 'Register New Passkey', 'two-factor' ); ?>
				</button>
			</p>
			<?php
		}
	}
}
