<?php
/**
 * Class for encrypting/decrypting TOTP secrets at rest.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Totp_Secret
 *
 * Static utility class handling all encryption/decryption logic for TOTP secrets.
 * Uses AES-256-GCM via sodium_compat, controlled by two constants:
 * - TWO_FACTOR_TOTP_ENCRYPTION_KEY (hex-encoded 32-byte key)
 * - TWO_FACTOR_TOTP_ENCRYPTION_KEY_PREVIOUS (for key rotation)
 *
 * @since 0.10.0
 */
class Two_Factor_Totp_Secret {

	/**
	 * Current encryption format version.
	 *
	 * @var string
	 */
	const ENCRYPTION_VERSION = '1';

	/**
	 * Get the current encryption key from the TWO_FACTOR_TOTP_ENCRYPTION_KEY constant.
	 *
	 * @return string|false 32-byte binary key or false if unavailable/invalid.
	 */
	protected static function get_current_key() {
		if ( ! defined( 'TWO_FACTOR_TOTP_ENCRYPTION_KEY' ) ) {
			return false;
		}

		$hex = TWO_FACTOR_TOTP_ENCRYPTION_KEY;
		if ( ! is_string( $hex ) || 64 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return false;
		}

		return hex2bin( $hex );
	}

	/**
	 * Get the previous encryption key from the TWO_FACTOR_TOTP_ENCRYPTION_KEY_PREVIOUS constant.
	 *
	 * @return string|false 32-byte binary key or false if unavailable/invalid.
	 */
	protected static function get_previous_key() {
		if ( ! defined( 'TWO_FACTOR_TOTP_ENCRYPTION_KEY_PREVIOUS' ) ) {
			return false;
		}

		$hex = TWO_FACTOR_TOTP_ENCRYPTION_KEY_PREVIOUS;
		if ( ! is_string( $hex ) || 64 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return false;
		}

		return hex2bin( $hex );
	}

	/**
	 * Check if encryption is available.
	 *
	 * Requires AES-256-GCM hardware support and a valid current key.
	 *
	 * @return bool
	 */
	public static function is_encryption_available() {
		if ( ! function_exists( 'sodium_crypto_aead_aes256gcm_is_available' ) ) {
			return false;
		}

		if ( ! sodium_crypto_aead_aes256gcm_is_available() ) {
			return false;
		}

		return false !== static::get_current_key();
	}

	/**
	 * Detect if a stored value is encrypted.
	 *
	 * @param string $value The stored value.
	 *
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::ENCRYPTION_VERSION . '::' );
	}

	/**
	 * Encrypt a plaintext TOTP secret.
	 *
	 * @param string $plaintext The plaintext TOTP secret.
	 * @param int    $user_id   The user ID (used as additional authenticated data).
	 *
	 * @return string|false Encrypted string in format "1::hex_nonce:hex_ciphertext" or false on failure.
	 */
	public static function encrypt( $plaintext, $user_id ) {
		$key = static::get_current_key();
		if ( false === $key ) {
			return false;
		}

		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES );
		$ad    = (string) $user_id;

		try {
			$ciphertext = sodium_crypto_aead_aes256gcm_encrypt( $plaintext, $ad, $nonce, $key );
		} catch ( SodiumException $e ) {
			return false;
		}

		return self::ENCRYPTION_VERSION . '::' . bin2hex( $nonce ) . ':' . bin2hex( $ciphertext );
	}

	/**
	 * Decrypt a stored encrypted value.
	 *
	 * Tries the current key first, then the previous key for rotation support.
	 *
	 * @param string $stored_value The encrypted stored value.
	 * @param int    $user_id      The user ID (used as additional authenticated data).
	 *
	 * @return array|false Array with 'plaintext' and 'needs_reencrypt' keys, or false on failure.
	 */
	public static function decrypt( $stored_value, $user_id ) {
		if ( ! self::is_encrypted( $stored_value ) ) {
			return false;
		}

		// Parse format: "1::hex_nonce:hex_ciphertext"
		$without_prefix = substr( $stored_value, strlen( self::ENCRYPTION_VERSION . '::' ) );
		$parts          = explode( ':', $without_prefix, 2 );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$nonce_hex      = $parts[0];
		$ciphertext_hex = $parts[1];

		if ( ! ctype_xdigit( $nonce_hex ) || ! ctype_xdigit( $ciphertext_hex ) ) {
			return false;
		}

		$nonce      = hex2bin( $nonce_hex );
		$ciphertext = hex2bin( $ciphertext_hex );
		$ad         = (string) $user_id;

		// Try current key first.
		$current_key = static::get_current_key();
		if ( false !== $current_key ) {
			$plaintext = self::try_decrypt( $ciphertext, $ad, $nonce, $current_key );
			if ( false !== $plaintext ) {
				return array(
					'plaintext'       => $plaintext,
					'needs_reencrypt' => false,
				);
			}
		}

		// Try previous key for rotation.
		$previous_key = static::get_previous_key();
		if ( false !== $previous_key ) {
			$plaintext = self::try_decrypt( $ciphertext, $ad, $nonce, $previous_key );
			if ( false !== $plaintext ) {
				return array(
					'plaintext'       => $plaintext,
					'needs_reencrypt' => true,
				);
			}
		}

		return false;
	}

	/**
	 * Attempt to decrypt with a specific key.
	 *
	 * @param string $ciphertext The ciphertext to decrypt.
	 * @param string $ad         Additional authenticated data.
	 * @param string $nonce      The nonce used during encryption.
	 * @param string $key        The encryption key.
	 *
	 * @return string|false The plaintext or false on failure.
	 */
	private static function try_decrypt( $ciphertext, $ad, $nonce, $key ) {
		try {
			$result = sodium_crypto_aead_aes256gcm_decrypt( $ciphertext, $ad, $nonce, $key );
			return ( false === $result ) ? false : $result;
		} catch ( SodiumException $e ) {
			return false;
		}
	}

	/**
	 * Resolve a stored value to plaintext, handling encryption/decryption transparently.
	 *
	 * This is the main entry point for reading TOTP secrets from the database.
	 *
	 * @param string $stored_value The raw value from the database.
	 * @param int    $user_id      The user ID.
	 *
	 * @return string The plaintext TOTP secret, or empty string on failure.
	 */
	public static function resolve( $stored_value, $user_id ) {
		// Empty value.
		if ( '' === $stored_value || false === $stored_value ) {
			return '';
		}

		// Not encrypted.
		if ( ! self::is_encrypted( $stored_value ) ) {
			// If encryption is available, opportunistically encrypt.
			if ( self::is_encryption_available() ) {
				$encrypted = self::encrypt( $stored_value, $user_id );
				if ( false !== $encrypted ) {
					update_user_meta( $user_id, '_two_factor_totp_key', $encrypted );
				}
			}
			return $stored_value;
		}

		// Encrypted value — attempt decryption.
		$result = self::decrypt( $stored_value, $user_id );
		if ( false === $result ) {
			return '';
		}

		// Re-encrypt with current key if needed (key rotation).
		if ( $result['needs_reencrypt'] ) {
			$encrypted = self::encrypt( $result['plaintext'], $user_id );
			if ( false !== $encrypted ) {
				update_user_meta( $user_id, '_two_factor_totp_key', $encrypted );
			}
		}

		return $result['plaintext'];
	}

	/**
	 * Prepare a plaintext TOTP secret for storage.
	 *
	 * Encrypts if a key is available, otherwise returns plaintext.
	 *
	 * @param string $plaintext The plaintext TOTP secret.
	 * @param int    $user_id   The user ID.
	 *
	 * @return string The value to store in the database.
	 */
	public static function prepare_for_storage( $plaintext, $user_id ) {
		if ( self::is_encryption_available() ) {
			$encrypted = self::encrypt( $plaintext, $user_id );
			if ( false !== $encrypted ) {
				return $encrypted;
			}
		}

		return $plaintext;
	}
}
