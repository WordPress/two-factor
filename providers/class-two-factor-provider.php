<?php
/**
 * Abstract class for creating two factor authentication providers.
 *
 * @package Two_Factor
 */

/**
 * Abstract class for creating two factor authentication providers.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
abstract class Two_Factor_Provider {

	/**
	 * Class constructor.
	 *
	 * @since 0.1-dev
	 */
	protected function __construct() {
		return $this;
	}

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Prints the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	public function print_label() {
		echo esc_html( $this->get_label() );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	abstract public function authentication_page( $user );

	/**
	 * Allow providers to do extra processing before the authentication.
	 * Return `true` to prevent the authentication and render the
	 * authentication page.
	 *
	 * @param  WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function pre_process_authentication( $user ) {
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
	abstract public function validate_authentication( $user );

	/**
	 * Logs the failed authentication.
	 *
	 * @param WP_User      $user WP_User object of the user trying to login.
	 * @param string|false $code The code used to authenticate, if available.
	 *
	 * @return void
	 */
	public function log_failure( $user, $code = false ) {
		/**
		 * This action is triggered when a Two Factor validation fails.
		 *
		 * @param WP_User      $user WP_User object of the user trying to login.
		 * @param string|false $code The code used to authenticate, if available.
		 */
		do_action( 'two_factor_user_login_failed', $user, $code );

		/* translators: %1$d: the user's ID %2$s: the code used to authenticate */
		$log_message = sprintf( esc_html__( 'The user with ID %1$d failed to login using the code "%2$s"', 'two-factor' ), $user->ID, esc_html( $code ) );

		/**
		 * This filter is triggered to checke whether it's needed to log the authentication failure.
		 *
		 * @param boolean      $should_log  Whether or not the authentication failure should be logged.
		 * @param WP_User      $user        WP_User object of the user trying to login.
		 * @param string|false $code        The code used to authenticate, if available.
		 * @param string       $log_message The generated log message.
		 */
		if ( apply_filters( 'two_factor_log_failure', true, $user, $code, $log_message ) ) {
			error_log( $log_message );
		}
	}

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	abstract public function is_available_for_user( $user );

	/**
	 * Generate a random eight-digit string to send out as an auth code.
	 *
	 * @since 0.1-dev
	 *
	 * @param int          $length The code length.
	 * @param string|array $chars Valid auth code characters.
	 * @return string
	 */
	public function get_code( $length = 8, $chars = '1234567890' ) {
		$code = '';
		if ( is_array( $chars ) ) {
			$chars = implode( '', $chars );
		}
		for ( $i = 0; $i < $length; $i++ ) {
			$code .= substr( $chars, wp_rand( 0, strlen( $chars ) - 1 ), 1 );
		}
		return $code;
	}
}
