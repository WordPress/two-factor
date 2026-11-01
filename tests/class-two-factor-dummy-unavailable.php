<?php
/**
 * Test Two Factor Dummy Unavailable.
 *
 * @package Two_Factor
 */

/**
 * Provider fixture that is enrolled but never usable.
 *
 * @package Two_Factor
 */
class Two_Factor_Dummy_Unavailable extends Two_Factor_Dummy {

	/**
	 * Number of times uninstall_user_data() was called.
	 *
	 * @var int
	 */
	public static $uninstall_user_data_calls = 0;

	/**
	 * Never available.
	 *
	 * @param WP_User $user WP_User object of the user.
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		return false;
	}

	/**
	 * Always enrolled but unavailable.
	 *
	 * @param WP_User $user WP_User object of the user.
	 * @return boolean
	 */
	public function is_enrolled_but_unavailable_for_user( $user ) {
		return true;
	}

	/**
	 * Count uninstall calls.
	 *
	 * @return void
	 */
	public static function uninstall_user_data() {
		++self::$uninstall_user_data_calls;
	}
}
