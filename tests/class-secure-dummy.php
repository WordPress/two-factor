<?php
/**
 * Class for creating a dummy provider that never passes.
 *
 * This is a mock for unit testing the provider class name filter, and where authentication should never pass.
 *
 * @package Two_Factor
 */
class Two_Factor_Dummy_Secure extends Two_Factor_Dummy {

	/**
	 * Pretend to be the Two_Factor_Dummy provider.
	 */
	public function get_key() {
		return 'Two_Factor_Dummy';
	}

	/**
	 * Validates the users input token.
	 *
	 * In this class we just return false.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function validate_authentication( $user ) {
		return false;
	}

}