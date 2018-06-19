<?php
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
	abstract function get_label();

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
	abstract function authentication_page( $user );

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
	abstract function validate_authentication( $user );

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	abstract function is_available_for_user( $user );

	/**
	 * Generate a random eight-digit string to send out as an auth code.
	 *
	 * @since 0.1-dev
	 *
	 * @param int          $length The code length.
	 * @param string|array $chars Valid auth code characters.
	 * @return string
	 */
	public function get_code( $length = 8, $chars = null ) {
		// If no characters are passed in, just make it numeric.
		if ( empty( $chars ) ) {
			// A length of 3, will be between 0 and ( 10^3 - 1 ) or 999
			$code = random_int( 0, ( pow( 10, $length ) - 1 ) );
			return str_pad( $code, $length, '0', STR_PAD_LEFT );
		}
		$code = '';
		if ( ! is_array( $chars ) ) {
			$chars = str_split( $chars );
		}
		// Make sure it isn't an associative array which could mess up the key lookup.
		$chars = array_values( $chars );
		for ( $i = 0; $i < $length; $i++ ) {
			$key = random_int( 0, sizeof( $chars ) - 1 );
			$code .= $chars[ $key ];
		}
		return $code;
	}
}
