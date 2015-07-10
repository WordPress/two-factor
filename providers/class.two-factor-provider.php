<?php

abstract class Two_Factor_Provider {

	protected function __construct() {
		return $this;
	}

	/**
	 * Returns the *unescaped* name of the method.
	 *
	 * @return string
	 */
	abstract function get_label();

	/**
	 * Safely prints the name of the method.
	 */
	function print_label() {
		echo esc_html( $this->get_label() );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @param $user WP_User
	 */
	abstract function authentication_page( $user );

	/**
	 * Validates the users input token.
	 *
	 * @param $user WP_User
	 *
	 * @return boolean
	 */
	abstract function validate_authentication( $user );

	/**
	 * Generate a random eight-digit string to send out as an auth code.
	 */
	function get_code( $length = 8, $chars = '1234567890' ) {
		$code = '';
		if ( ! is_array( $chars ) ) {
			$chars = str_split( $chars );
		}
		for ( $i = 0; $i < $length; $i++ ) {
			$key = array_rand( $chars );
			$code .= $chars[ $key ];
		}
		return $code;
	}

}
