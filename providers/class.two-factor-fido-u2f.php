<?php
/**
 * Class for creating a FIDO Universal 2nd Factor provider.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_Fido_U2f extends Two_Factor_Provider {

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
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	function get_label() {
		return _x( 'FIDO U2f', 'Provider Label', 'two-factor' );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	function authentication_page( $user ) {}

	/**
	 * Validates the users input token.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	function validate_authentication( $user ) {}

}
