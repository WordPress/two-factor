<?php
/**
 * Class for creating an email provider.
 *
 * @package Two_Factor
 */

/**
 * Class for creating an email provider.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_Email extends Two_Factor_Provider {

	/**
	 * The user meta token key.
	 *
	 * @var string
	 */
	const TOKEN_META_KEY = '_two_factor_email_token';

	/**
	 * Store the timestamp when the token was generated.
	 *
	 * @var string
	 */
	const TOKEN_META_KEY_TIMESTAMP = '_two_factor_email_token_timestamp';

	/**
	 * The user meta verified key.
	 *
	 * @var string
	 */
	const VERIFIED_META_KEY = '_two_factor_email_verified';

	/**
	 * Name of the input field used for code resend.
	 *
	 * @var string
	 */
	const INPUT_NAME_RESEND_CODE = 'two-factor-email-code-resend';

	/**
	 * Class constructor.
	 *
	 * @since 0.1-dev
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'two_factor_user_options_' . __CLASS__, array( $this, 'user_options' ) );
		add_action( 'personal_options_update', array( $this, 'pre_user_options_update' ), 5 );
		add_action( 'edit_user_profile_update', array( $this, 'pre_user_options_update' ), 5 );
		parent::__construct();
	}

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	public function get_label() {
		return _x( 'Email', 'Provider Label', 'two-factor' );
	}

	/**
	 * Returns the "continue with" text provider for the login screen.
	 *
	 * @since 0.9.0
	 */
	public function get_alternative_provider_label() {
		return __( 'Send a code to your email', 'two-factor' );
	}

	/**
	 * Register the rest-api endpoints required for this provider.
	 */
	public function register_rest_routes() {
		register_rest_route(
			Two_Factor_Core::REST_NAMESPACE,
			'/email',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'rest_delete_email' ),
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
					'callback'            => array( $this, 'rest_setup_email' ),
					'permission_callback' => function ( $request ) {
						return Two_Factor_Core::rest_api_can_edit_user_and_update_two_factor_options( $request['user_id'] );
					},
					'args'                => array(
						'user_id' => array(
							'required' => true,
							'type'     => 'integer',
						),
						'code'    => array(
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => null, // Note: validation handled in ::rest_setup_email().
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
	 * REST API endpoint for setting up Email.
	 *
	 * @param WP_REST_Request $request The Rest Request object.
	 * @return WP_Error|array Array of data on success, WP_Error on error.
	 */
	public function rest_setup_email( $request ) {
		$user_id = $request['user_id'];
		$user    = get_user_by( 'id', $user_id );

		$code = preg_replace( '/\s+/', '', $request['code'] );

		// If no code, generate and email one.
		if ( empty( $code ) ) {
			if ( $this->generate_and_email_token( $user, 'verification_setup' ) ) {
				return array( 'success' => true );
			}
			return new WP_Error( 'email_error', __( 'Unable to send email. Please check your server settings.', 'two-factor' ), array( 'status' => 500 ) );
		}

		// Verify code.
		if ( ! $this->validate_token( $user_id, $code ) ) {
			return new WP_Error( 'invalid_code', __( 'Invalid verification code.', 'two-factor' ), array( 'status' => 400 ) );
		}

		// Mark as verified.
		update_user_meta( $user_id, self::VERIFIED_META_KEY, true );

		if ( $request->get_param( 'enable_provider' ) && ! Two_Factor_Core::enable_provider_for_user( $user_id, 'Two_Factor_Email' ) ) {
			return new WP_Error( 'db_error', __( 'Unable to enable Email provider for this user.', 'two-factor' ), array( 'status' => 500 ) );
		}

		ob_start();
		$this->user_options( $user );
		$html = ob_get_clean();

		return array(
			'success' => true,
			'html'    => $html,
		);
	}

	/**
	 * Rest API endpoint for handling deactivation of Email.
	 *
	 * @param WP_REST_Request $request The Rest Request object.
	 * @return array Success array.
	 */
	public function rest_delete_email( $request ) {
		$user_id = $request['user_id'];
		$user    = get_user_by( 'id', $user_id );

		delete_user_meta( $user_id, self::VERIFIED_META_KEY );

		if ( ! Two_Factor_Core::disable_provider_for_user( $user_id, 'Two_Factor_Email' ) ) {
			return new WP_Error( 'db_error', __( 'Unable to disable Email provider for this user.', 'two-factor' ), array( 'status' => 500 ) );
		}

		ob_start();
		$this->user_options( $user );
		$html = ob_get_clean();

		return array(
			'success' => true,
			'html'    => $html,
		);
	}

	/**
	 * Get the email token length.
	 *
	 * @return int Email token string length.
	 */
	private function get_token_length() {
		/**
		 * Number of characters in the email token.
		 *
		 * @param int $token_length Number of characters in the email token.
		 */
		$token_length = (int) apply_filters( 'two_factor_email_token_length', 8 );

		return $token_length;
	}

	/**
	 * Generate the user token.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function generate_token( $user_id ) {
		$token = $this->get_code( $this->get_token_length() );

		update_user_meta( $user_id, self::TOKEN_META_KEY_TIMESTAMP, time() );
		update_user_meta( $user_id, self::TOKEN_META_KEY, wp_hash( $token ) );

		return $token;
	}

	/**
	 * Check if user has a valid token already.
	 *
	 * @param  int $user_id User ID.
	 * @return boolean      If user has a valid email token.
	 */
	public function user_has_token( $user_id ) {
		$hashed_token = $this->get_user_token( $user_id );

		if ( ! empty( $hashed_token ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Has the user token validity timestamp expired.
	 *
	 * @param integer $user_id User ID.
	 *
	 * @return boolean
	 */
	public function user_token_has_expired( $user_id ) {
		$token_lifetime = $this->user_token_lifetime( $user_id );
		$token_ttl      = $this->user_token_ttl( $user_id );

		// Invalid token lifetime is considered an expired token.
		if ( is_int( $token_lifetime ) && $token_lifetime <= $token_ttl ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the lifetime of a user token in seconds.
	 *
	 * @param integer $user_id User ID.
	 *
	 * @return integer|null Return `null` if the lifetime can't be measured.
	 */
	public function user_token_lifetime( $user_id ) {
		$timestamp = intval( get_user_meta( $user_id, self::TOKEN_META_KEY_TIMESTAMP, true ) );

		if ( ! empty( $timestamp ) ) {
			return time() - $timestamp;
		}

		return null;
	}

	/**
	 * Return the token time-to-live for a user.
	 *
	 * @param integer $user_id User ID.
	 *
	 * @return integer
	 */
	public function user_token_ttl( $user_id ) {
		$token_ttl = 15 * MINUTE_IN_SECONDS;

		/**
		 * Number of seconds the token is considered valid
		 * after the generation.
		 *
		 * @deprecated 0.11.0 Use {@see 'two_factor_email_token_ttl'} instead.
		 *
		 * @param integer $token_ttl Token time-to-live in seconds.
		 * @param integer $user_id User ID.
		 */
		$token_ttl = (int) apply_filters_deprecated( 'two_factor_token_ttl', array( $token_ttl, $user_id ), '0.11.0', 'two_factor_email_token_ttl' );

		/**
		 * Number of seconds the token is considered valid
		 * after the generation.
		 *
		 * @param integer $token_ttl Token time-to-live in seconds.
		 * @param integer $user_id User ID.
		 */
		return (int) apply_filters( 'two_factor_email_token_ttl', $token_ttl, $user_id );
	}

	/**
	 * Get the authentication token for the user.
	 *
	 * @param  int $user_id    User ID.
	 *
	 * @return string|boolean  User token or `false` if no token found.
	 */
	public function get_user_token( $user_id ) {
		$hashed_token = get_user_meta( $user_id, self::TOKEN_META_KEY, true );

		if ( ! empty( $hashed_token ) && is_string( $hashed_token ) ) {
			return $hashed_token;
		}

		return false;
	}

	/**
	 * Validate the user token.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id User ID.
	 * @param string $token User token.
	 * @return boolean
	 */
	public function validate_token( $user_id, $token ) {
		$hashed_token = $this->get_user_token( $user_id );

		// Bail if token is empty or it doesn't match.
		if ( empty( $hashed_token ) || ! hash_equals( wp_hash( $token ), $hashed_token ) ) {
			return false;
		}

		if ( $this->user_token_has_expired( $user_id ) ) {
			return false;
		}

		// Ensure the token can be used only once.
		$this->delete_token( $user_id );

		return true;
	}

	/**
	 * Delete the user token.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 */
	public function delete_token( $user_id ) {
		delete_user_meta( $user_id, self::TOKEN_META_KEY );
	}

	/**
	 * Get the client IP address for the current request.
	 *
	 * Note that the IP address is used only for information purposes
	 * and is expected to be configured correctly, if behind proxy.
	 *
	 * @return string|null
	 */
	private function get_client_ip() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) { // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- don't have more reliable option for now.
			return preg_replace( '/[^0-9a-fA-F:., ]/', '', $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- we're limit the allowed characters.
		}

		return null;
	}

	/**
	 * Generate and email the user token.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user   WP_User object of the logged-in user.
	 * @param string  $action Optional. The action intended for the token. Default 'login'.
	 *                        Accepts 'login', 'verification_setup'.
	 * @return bool Whether the email contents were sent successfully.
	 */
	public function generate_and_email_token( $user, $action = 'login' ) {
		$token     = $this->generate_token( $user->ID );
		$remote_ip = $this->get_client_ip();

		if ( 'verification_setup' === $action ) {
			/* translators: %s: site name */
			$subject = __( 'Verify your email for Two-Factor Authentication at %s', 'two-factor' );
			$message = wp_strip_all_tags(
				sprintf(
					/* translators: %s: token */
					__( 'Enter %s to verify your email address for two-factor authentication.', 'two-factor' ),
					$token
				)
			);
		} else {
			/* translators: %s: site name */
			$subject = __( 'Your login confirmation code for %s', 'two-factor' );
			$message_parts = array(
				sprintf(
					/* translators: %s: token */
					__( 'Enter %s to log in.', 'two-factor' ),
					$token
				),
			);
			/* translators: $1$s: IP address of user, %2$s: `user_login` of authenticated user */
			/* translators: $1$s: IP address of user, %2$s: `user_login` of authenticated user */
			$message_parts[] = sprintf(
				__( 'Didn\'t expect this? A user from %1$s has successfully authenticated as %2$s. If this wasn\'t you, please change your password.', 'two-factor' ),
				$remote_ip,
				$user->user_login
			);
			$message = wp_strip_all_tags( implode( "\n\n", $message_parts ) );
		}

		$subject = wp_strip_all_tags(
			sprintf(
				$subject,
				wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
			)
		);

		/**
		 * Filter the token email subject.
		 *
		 * @param string $subject The email subject line.
		 * @param int    $user_id The ID of the user.
		 */
		$subject = apply_filters( 'two_factor_token_email_subject', $subject, $user->ID );

		/**
		 * Filter the token email message.
		 *
		 * @param string $message The email message.
		 * @param string $token   The token.
		 * @param int    $user_id The ID of the user.
		 */
		$message = apply_filters( 'two_factor_token_email_message', $message, $token, $user->ID );

		return wp_mail( $user->user_email, $subject, $message ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		if ( ! $user ) {
			return;
		}

		if ( ! $this->user_has_token( $user->ID ) || $this->user_token_has_expired( $user->ID ) ) {
			$this->generate_and_email_token( $user );
		}

		$token_length      = $this->get_token_length();
		$token_placeholder = str_repeat( 'X', $token_length );

		require_once ABSPATH . '/wp-admin/includes/template.php';
		?>
		<?php do_action( 'two_factor_before_authentication_prompt', $this ); ?>
		<p class="two-factor-prompt"><?php esc_html_e( 'A verification code has been sent to the email address associated with your account.', 'two-factor' ); ?></p>
		<?php do_action( 'two_factor_after_authentication_prompt', $this ); ?>
		<p>
			<label for="authcode"><?php esc_html_e( 'Verification Code:', 'two-factor' ); ?></label>
			<input type="text" inputmode="numeric" name="two-factor-email-code" id="authcode" class="input authcode" value="" size="20" pattern="[0-9 ]*" autocomplete="one-time-code" placeholder="<?php echo esc_attr( $token_placeholder ); ?>" data-digits="<?php echo esc_attr( $token_length ); ?>" />
		</p>
		<?php do_action( 'two_factor_after_authentication_input', $this ); ?>
		<?php submit_button( __( 'Verify', 'two-factor' ) ); ?>
		<p class="two-factor-email-resend">
			<input type="submit" class="button" name="<?php echo esc_attr( self::INPUT_NAME_RESEND_CODE ); ?>" value="<?php esc_attr_e( 'Resend Code', 'two-factor' ); ?>" />
		</p>
		<script type="text/javascript">
			setTimeout( function(){
				var d;
				try{
					d = document.getElementById('authcode');
					d.value = '';
					d.focus();
				} catch(e){}
			}, 200);
		</script>
		<?php
	}

	/**
	 * Send the email code if missing or requested. Stop the authentication
	 * validation if a new token has been generated and sent.
	 *
	 * @param  WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function pre_process_authentication( $user ) {
		if ( isset( $user->ID ) && isset( $_REQUEST[ self::INPUT_NAME_RESEND_CODE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-distructive option that relies on user state.
			$this->generate_and_email_token( $user );
			return true;
		}

		return false;
	}

	/**
	 * Validates the users input token.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function validate_authentication( $user ) {
		$code = $this->sanitize_code_from_request( 'two-factor-email-code' );
		if ( ! isset( $user->ID ) || ! $code ) {
			return false;
		}

		return $this->validate_token( $user->ID, $code );
	}

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		// If the user has already enabled the provider (legacy), allow them to continue using it.
		$providers = get_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
		if ( is_array( $providers ) && in_array( 'Two_Factor_Email', $providers, true ) ) {
			return true;
		}

		// Otherwise, only available if verified.
		return (bool) get_user_meta( $user->ID, self::VERIFIED_META_KEY, true );
	}

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function user_options( $user ) {
		$email = $user->user_email;

		// Check if user is verified.
		$is_verified = $this->is_available_for_user( $user );

		wp_enqueue_script( 'wp-api-request' );
		wp_enqueue_script( 'jquery' );
		?>
		<div id="two-factor-email-options">
		<p>
			<?php
			echo esc_html(
				sprintf(
				/* translators: %s: email address */
					__( 'Authentication codes will be sent to %s.', 'two-factor' ),
					$email
				)
			);
			?>
		</p>
		<?php if ( ! $is_verified ) : ?>
			<p>
				<button type="button" class="button" id="two-factor-email-send-code">
					<?php esc_html_e( 'Verify your e-mail address', 'two-factor' ); ?>
				</button>
			</p>
			<div id="two-factor-email-verification-form" style="display:none; margin-top: 10px;">
				<p>
					<label for="two-factor-email-code-input"><?php esc_html_e( 'Verification Code:', 'two-factor' ); ?></label>
					<input type="text" id="two-factor-email-code-input" class="input" size="20" autocomplete="off" />
					<button type="button" class="button" id="two-factor-email-verify-code">
						<?php esc_html_e( 'Verify', 'two-factor' ); ?>
					</button>
				</p>
			</div>
			
			<script>
			(function($) {
				$('#two-factor-email-send-code').on('click', function(e) {
					e.preventDefault();
					var $btn = $(this);
					$btn.prop('disabled', true);
					
					wp.apiRequest({
						method: 'POST',
						path: <?php echo wp_json_encode( Two_Factor_Core::REST_NAMESPACE . '/email' ); ?>,
						data: { user_id: <?php echo wp_json_encode( $user->ID ); ?> }
					}).done(function() {
						$btn.hide();
						$('#two-factor-email-verification-form').slideDown();
						$('#two-factor-email-code-input').focus();
					}).fail(function(response) {
						alert(response.message || 'Error sending email');
						$btn.prop('disabled', false);
					});
				});

				$('#two-factor-email-verify-code').on('click', function(e) {
					e.preventDefault();
					var $btn = $(this);
					var code = $('#two-factor-email-code-input').val();
					
					$btn.prop('disabled', true);
					
					wp.apiRequest({
						method: 'POST',
						path: <?php echo wp_json_encode( Two_Factor_Core::REST_NAMESPACE . '/email' ); ?>,
						data: { 
							user_id: <?php echo wp_json_encode( $user->ID ); ?>,
							code: code,
							enable_provider: true
						}
					}).done(function(response) {
						// Update the container
						var $newContent = $(response.html);
						$('#two-factor-email-options').replaceWith($newContent);
						
						// Automatically check the "Enable" checkbox for Email
						$('#enabled-Two_Factor_Email').prop('checked', true);
					}).fail(function(response) {
						alert(response.responseJSON.message || 'Error verifying code');
						$btn.prop('disabled', false);
					});
				});
			})(jQuery);
			</script>
		<?php else : ?>
			<script>
			(function($) {
				// No specific JS needed for verified state, but we could add "Deactivate" logic eventually.
			})(jQuery);
			</script>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prevent enabling the Email provider if it hasn't been verified (and isn't a legacy enabled user).
	 *
	 * @param int $user_id The user ID.
	 */
	public function pre_user_options_update( $user_id ) {
		if ( isset( $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] ) && is_array( $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] ) ) {
			$enabled_providers = $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ];
			if ( in_array( 'Two_Factor_Email', $enabled_providers, true ) ) {
				$is_verified = get_user_meta( $user_id, self::VERIFIED_META_KEY, true );
				$current_providers = get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
				
				// If not verified, and NOT currently enabled (legacy), disallow enabling.
				if ( ! $is_verified && ( ! is_array( $current_providers ) || ! in_array( 'Two_Factor_Email', $current_providers, true ) ) ) {
					$enabled_providers = array_diff( $enabled_providers, array( 'Two_Factor_Email' ) );
					$_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] = $enabled_providers;
				}
			}
		}
	}

	/**
	 * Returns the key of the user meta. keys to delete during plugin uninstall.
	 *
	 * @return array
	 */
	public static function uninstall_user_meta_keys() {
		return array(
			self::TOKEN_META_KEY,
			self::TOKEN_META_KEY_TIMESTAMP,
			self::VERIFIED_META_KEY,
		);
	}
}
