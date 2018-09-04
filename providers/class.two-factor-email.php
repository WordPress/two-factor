<?php
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
	 * @type string
	 */
	const TOKEN_META_KEY = '_two_factor_email_token';

	/**
	 * Name of the input field used for code resend.
	 *
	 * @var string
	 */
	const INPUT_NAME_RESEND_CODE = 'two-factor-email-code-resend';

	/**
	 * Ensures only one instance of this class exists in memory at any one time.
	 *
	 * @since 0.1-dev
	 */
	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	/**
	 * Class constructor.
	 *
	 * @since 0.1-dev
	 */
	protected function __construct() {
		add_action( 'two-factor-user-options-' . __CLASS__, array( $this, 'user_options' ) );
		return parent::__construct();
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
	 * Generate the user token.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function generate_token( $user_id ) {
		$token = $this->get_code();
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
		} else {
			return false;
		}
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
		if ( empty( $hashed_token ) || ( wp_hash( $token ) !== $hashed_token ) ) {
			return false;
		}

		// Ensure that the token can't be re-used.
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
	 * Generate and email the user token.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return bool Whether the email contents were sent successfully.
	 */
	public function generate_and_email_token( $user ) {
		$token = $this->generate_token( $user->ID );

		/* translators: %s: site name */
		$subject = wp_strip_all_tags( sprintf( __( 'Your login confirmation code for %s', 'two-factor' ), get_bloginfo( 'name' ) ) );
		/* translators: %s: token */
		$message = wp_strip_all_tags( sprintf( __( 'Enter %s to log in.', 'two-factor' ), $token ) );

		return wp_mail( $user->user_email, $subject, $message );
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

		if ( ! $this->user_has_token( $user->ID ) ) {
			$this->generate_and_email_token( $user );
		}

		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p><?php esc_html_e( 'A verification code has been sent to the email address associated with your account.', 'two-factor' ); ?></p>
		<p>
			<label for="authcode"><?php esc_html_e( 'Verification Code:', 'two-factor' ); ?></label>
			<input type="tel" name="two-factor-email-code" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
			<?php submit_button( __( 'Log In', 'two-factor' ) ); ?>
		</p>
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
	 * @param  WP_USer $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function pre_process_authentication( $user ) {
		if ( isset( $user->ID ) && isset( $_REQUEST[ self::INPUT_NAME_RESEND_CODE ] ) ) {
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
		if ( ! isset( $user->ID ) || ! isset( $_REQUEST['two-factor-email-code'] ) ) {
			return false;
		}

		return $this->validate_token( $user->ID, $_REQUEST['two-factor-email-code'] );
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
		return true;
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
		?>
		<div>
			<?php
			echo esc_html( sprintf(
				/* translators: %s: email address */
				__( 'Authentication codes will be sent to %s.', 'two-factor' ),
				$email
			) );
			?>
		</div>
		<?php
	}
}
