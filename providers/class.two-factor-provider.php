<?php

abstract class Two_Factor_Provider {

	protected function __construct() {
		return $this;
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 */
	abstract function authentication_page();

	/**
	 * Validates the users input token.
	 *
	 * @return boolean
	 */
	abstract function validate_authentication_page();

	/**
	 * Generate a random six-digit string to send out as an auth code.
	 */
	function get_code( $length = 8, $chars = '1234567890' ) {
		$code = '';
		if ( ! is_array( $chars ) ) {
			$chars = str_split( $chars );
		}
		for ( $i = 0; $i < $length; $i++ ) {
			$key = array_rand( $chars );
			$code += $chars[ $key ];
		}
		return $chars;
	}

}
