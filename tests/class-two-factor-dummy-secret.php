<?php
/**
 * Test Two Factor Dummy Secret.
 *
 * @package Two_Factor
 */

/**
 * Provider fixture that keeps a secret, to show core handles secrets for a provider other than TOTP.
 *
 * @package Two_Factor
 */
class Two_Factor_Dummy_Secret extends Two_Factor_Dummy {

	/**
	 * User meta key holding the plaintext secret.
	 *
	 * @var string
	 */
	const SECRET_META_KEY = '_two_factor_dummy_secret';

	/**
	 * Secrets this provider declares. Tests replace it to try invalid and clashing declarations.
	 *
	 * @var array
	 */
	public static $secrets = array( 'dummy' => self::SECRET_META_KEY );

	/**
	 * Declare the secret.
	 *
	 * @return array
	 */
	public static function user_secret_meta_keys() {
		return self::$secrets;
	}
}
