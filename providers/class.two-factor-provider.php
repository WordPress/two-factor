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

}
