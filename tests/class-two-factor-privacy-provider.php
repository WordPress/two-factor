<?php
/**
 * Test Two Factor Privacy Provider.
 *
 * @package Two_Factor
 */

/**
 * Class for creating a custom provider that stores a short-lived record.
 *
 * This is a mock for unit testing the personal data exporter and eraser with
 * a provider that is not bundled with the plugin.
 *
 * @package Two_Factor
 */
class Two_Factor_Privacy_Provider extends Two_Factor_Dummy {

	/**
	 * The user meta key for the short-lived record.
	 *
	 * @var string
	 */
	const TOKEN_META_KEY = '_two_factor_privacy_provider_token';

	/**
	 * Return the user meta keys that the personal data eraser removes.
	 *
	 * @return array
	 */
	public static function privacy_eraser_user_meta_keys() {
		return array( self::TOKEN_META_KEY );
	}
}
