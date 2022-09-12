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
	 * Prefix for encrypted secrets. Contains a version identifier.
	 *
	 * $t1$ -> v1 (RFC 6238, encrypted with XChaCha20-Poly1305, with a key derived from HMAC-SHA256
	 *                  of SECURE_AUTH_SAL.)
	 *
	 * @var string
	 */
	const ENCRYPTED_PREFIX = '$t1$';

	/**
	 * Current "version" of the encryption protocol.
	 *
	 * 1 -> $t1$nonce|ciphertext|tag
	 */
	const ENCRYPTED_VERSION = 1;

	/**
	 * String used to confirm whether the encryption key has not changed.
	 */
	const ENCRYPTION_TEST_STRING = 'Code is Poetry';

	/**
	 * String used to confirm whether the encryption key has not changed.
	 */
	const ENCRYPTION_TEST_OPTION = 'two_factor_encryption_test';

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

	/**
	 * Is this string an encrypted secret?
	 *
	 * @param string $secret Stored secret.
	 * @return bool
	 */
	public static function is_encrypted( $secret ) {
		if ( strlen( $secret ) < 40 ) {
			return false;
		}
		// Should we add in a more complex check here for multiple prefixes if this changes?
		if ( strpos( $secret, self::ENCRYPTED_PREFIX ) !== 0 ) {
			return false;
		}
		return true;
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $secret  secret.
	 * @param int    $user_id User ID.
	 * @param int    $version (Optional) Version ID.
	 * @return string
	 * @throws SodiumException From sodium_compat or ext/sodium.
	 */
	public static function encrypt( $secret, $user_id, $version = self::ENCRYPTED_VERSION ) {
		$prefix     = self::get_version_header( $version );
		$nonce      = random_bytes( 24 );
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
			$secret,
			self::serialize_aad( $prefix, $nonce, $user_id ),
			$nonce,
			self::get_encryption_key( $version )
		);
		// @codingStandardsIgnoreStart
		return self::ENCRYPTED_PREFIX . base64_encode( $nonce . $ciphertext );
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Decrypt a secret.
	 *
	 * Version information is encoded with the ciphertext and thus omitted from this function.
	 *
	 * @param string $encrypted Encrypted secret.
	 * @param int    $user_id User ID.
	 * @param string $salt (Optional) The salt to derive the encryption key from.
	 * @return string
	 * @throws RuntimeException Decryption failed.
	 */
	public static function decrypt( $encrypted, $user_id, $salt = null ) {
		if ( strlen( $encrypted ) < 4 ) {
			throw new RuntimeException( 'Message is too short to be encrypted' );
		}
		$prefix  = substr( $encrypted, 0, 4 );
		$version = self::get_version_id( $prefix );
		if ( 1 === $version ) {
			// @codingStandardsIgnoreStart
			$decoded    = base64_decode( substr( $encrypted, 4 ) );
			// @codingStandardsIgnoreEnd
			$nonce      = RandomCompat_substr( $decoded, 0, 24 );
			$ciphertext = RandomCompat_substr( $decoded, 24 );
			try {
				$decrypted = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
					$ciphertext,
					self::serialize_aad( $prefix, $nonce, $user_id ),
					$nonce,
					self::get_encryption_key( $version, $salt )
				);
			} catch ( SodiumException $ex ) {
				throw new RuntimeException( 'Decryption failed', 0, $ex );
			}
		} else {
			throw new RuntimeException( 'Unknown version: ' . $version );
		}

		// If we don't have a string, throw an exception because decryption failed.
		if ( ! is_string( $decrypted ) ) {
			throw new RuntimeException( 'Could not decrypt secret' );
		}
		return $decrypted;
	}

	/**
	 * Recrypt a secret.
	 *
	 * This will use an old encryption key to decrypt a secret, and then re-encrypt
	 * it with the current key.
	 *
	 * The bulk of this function is duplicating ::decrypt() so we can use a different key.
	 * 
	 * @param string $old_salt The old salt to derive the key from.
	 * @param string $secret   The encrypted secret.
	 * @param int    $user_id  User ID.
	 * @return string The encrypted data.
	 */
	public static function recrypt( $old_salt, $encrypted, $user_id ) {
		$decrypted = self::decrypt( $encrypted, $user_id, $old_salt );

		// We'll just use the same version that was on the previously encrypted value.
		$prefix  = substr( $encrypted, 0, 4 );
		$version = self::get_version_id( $prefix );

		return self::encrypt( $decrypted, $user_id, $version );
	}

	/**
	 * Serialize the Additional Authenticated Data for secret encryption.
	 *
	 * @param string $prefix Version prefix.
	 * @param string $nonce Encryption nonce.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public static function serialize_aad( $prefix, $nonce, $user_id ) {
		return $prefix . $nonce . pack( 'N', $user_id );
	}

	/**
	 * Get the version prefix from a given version number.
	 *
	 * @param int $number Version number.
	 * @return string
	 * @throws RuntimeException For incorrect versions.
	 */
	final private static function get_version_header( $number = self::ENCRYPTED_VERSION ) {
		switch ( $number ) {
			case 1:
				return '$t1$';
		}
		throw new RuntimeException( 'Incorrect version number: ' . $number );
	}

	/**
	 * Get the version prefix from a given version number.
	 *
	 * @param string $prefix Version prefix.
	 * @return int
	 * @throws RuntimeException For incorrect versions.
	 */
	final private static function get_version_id( $prefix = self::ENCRYPTED_PREFIX ) {
		switch ( $prefix ) {
			case '$t1$':
				return 1;
		}
		throw new RuntimeException( 'Incorrect version identifier: ' . $prefix );
	}

	/**
	 * Get the encryption key for encrypting secrets.
	 *
	 * If we want to change the salt that we're using to encrypt/decrypt,
	 * this is where we change it.
	 *
	 * The Salt can be overridden in the arguments, for instances when we need
	 * to use a prior value after rotating salts in wp-config.
	 *
	 * @param int $version Key derivation strategy.
	 * @param string $salt (Optional) The raw salt we're deriving the key from.
	 * @return string
	 * @throws RuntimeException For incorrect versions.
	 */
	final private static function get_encryption_key( $version = self::ENCRYPTED_VERSION, $salt = null ) {
		if ( empty( $salt ) ) {
			$salt = SECURE_AUTH_SALT;
		}
		if ( 1 === $version ) {
			return hash_hmac( 'sha256', $salt, 'two-factor-encryption', true );
		}
		throw new RuntimeException( 'Incorrect version number: ' . $version );
	}

	/**
	 * Check to see if the encryption key has changed.
	 *
	 * Worth noting that this is written specifically to be multisite-compatible,
	 * which does mean that the options being used, if in a multisite environment,
	 * will not autoload as they would if in a single site environment.
	 *
	 * @param string $salt (Optiona) The string from which we will derive our encryption key.
	 * @return boolean Whether all seems right with the world. (false = data may need recrypted)
	 */
	final public static function test_encryption_key( $salt = null ) {
		$user_id   = 0; // We are doing this user-agnostic.
		$encrypted = get_site_option( self::ENCRYPTION_TEST_OPTION );

		if ( ! $encrypted ) {
			// If it hasn't been set yet, set it without overriding the salt.
			$encrypted = self::encrypt( self::ENCRYPTION_TEST_STRING, $user_id );
			update_site_option( self::ENCRYPTION_TEST_OPTION, $encrypted );
			// We've just set it, so there's no need to test it.
			return true;
		}

		try {
			$raw = self::decrypt( $encrypted, $user_id, $salt );
		} catch ( RuntimeException $ex ) {
			// If it doesn't decrypt at all, something went wrong.
			return false;
		}
		
		if ( self::ENCRYPTION_TEST_STRING !== $raw ) {
			// If it doesn't decrypt to our constant test string,
			// something must have changed.
			return false;
		}

		return true;
	}

	/**
	 * Runner function to iterate through recrypting data. Uses a static
	 * variable to avoid recursion.
	 *
	 * @param string $old_salt The old salt that had been used to derive the key.
	 */
	public static function recrypt_data( $old_salt ) {
		static $once = false;
		if ( ! $once ) {
			$once = true;

			$user_id = 0;
			$option  = get_site_option( self::ENCRYPTION_TEST_OPTION );

			try {
				$new_value = self::recrypt( $old_salt, $option, $user_id );
				update_site_option( self::ENCRYPTION_TEST_OPTION, $new_value );
			} catch ( RuntimeException $ex ) {
				return new WP_Error(
					'recrypt-failed',
					__( 'The recrypt could not complete due to a runtime error.' ),
					array(
						'error' => $ex,
					)
				);
			}

			// Now is the action we kick off to handle any other providers that may need to update their data.
			do_action( 'two_factor_recrypt_data', $old_salt );
		}
	}
}
