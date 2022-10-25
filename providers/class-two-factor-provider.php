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
	 *      of the child class's `ENCRYPTION_SALT_NAME`. If that doesn't exist, the key falls back
	 *      to `wp_salt( 'secure_auth' )`.
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
	 * @return string
	 * @throws RuntimeException Decryption failed.
	 */
	public static function decrypt( $encrypted, $user_id ) {
		if ( strlen( $encrypted ) < 4 ) {
			throw new RuntimeException( 'Message is too short to be encrypted' );
		}

		require_once ABSPATH . WPINC . '/random_compat/byte_safe_strings.php';

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
					self::get_encryption_key( $version )
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
	private static function get_version_header( $number = self::ENCRYPTED_VERSION ) {
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
	private static function get_version_id( $prefix = self::ENCRYPTED_PREFIX ) {
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
	 * @param int $version Key derivation strategy.
	 * @return string
	 * @throws RuntimeException For incorrect versions.
	 */
	private static function get_encryption_key( $version = self::ENCRYPTED_VERSION ) {
		if ( 1 === $version ) {
			$name = get_called_class()::ENCRYPTION_SALT_NAME;
			$salt = defined( $name ) ? constant( $name ) : wp_salt( 'secure_auth' );

			return hash_hmac( 'sha256', $salt, 'two-factor-encryption', true );
		}
		throw new RuntimeException( 'Incorrect version number: ' . $version );
	}

	/**
	 * Get the path to `wp-config.php`.
	 *
	 * If merging to Core, move this to `wp-load.php` and use there for DRYness.
	 *
	 * @return string
	 */
	public static function get_config_path() {
		$path = '';

		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			$path = ABSPATH . 'wp-config.php';
		} elseif ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			// The config file resides one level above ABSPATH but is not part of another installation.
			$path = dirname( ABSPATH ) . '/wp-config.php';
		}

		return $path;
	}

	/**
	 * Attempt to create the given constant if it doesn't already exist.
	 *
	 * If created, it'll also be defined so it can be used in the current process.
	 *
	 * @param string $name
	 *
	 * @return bool `true` if already exists, `true` if created, `false` if could not be created.
	 */
	public static function maybe_create_config_salt( $name ) {
		if ( defined( $name ) ) {
			return true;
		}

		$result          = false;
		$config_path     = self::get_config_path();
		$config_contents = file_get_contents( $config_path );

		// Need to check this in addition to `defined()` above, to avoid writing duplicates
		// during edge cases where the config isn't included (like certain unit tests setups).
		$already_exists = false !== stripos( $config_contents, $name );

		if ( ! $already_exists && is_writable( $config_path ) ) {
			// todo in test suite iswritable always returns true, even when file is chmod 444
			// also todo, locally it still writes to conf file when chmod 444 - retest now that have changed some things

			$salt_value   = wp_generate_password( 64, true, true );
			// todo is wp_generate_pw strong enough here, or need to copy setup-config.php use of random_int() ?
			// see https://core.trac.wordpress.org/ticket/35290 and others from blame
			// doesn't wpgenpw also uses a cspring, and the same alphabet+length?

			$new_constant = self::get_config_salt_definition( $name, $salt_value );

			// Put it at the beginning for simplicity/reliability.
			$config_contents = str_replace( '<?php', '<?php ' . $new_constant, $config_contents );
			$written         = file_put_contents( $config_path, $config_contents );

			if ( $written ) {
				define( $name, $salt_value );

				$result = true;
			}
		}

		return $result;
	}

	/**
	 * Get the definition of a new salt constant, for `wp-config.php`
	 *
	 * @param string $name       The name of the constant.
	 * @param string $salt_value The value of the constant.
	 *
	 * @return string
	 */
	protected static function get_config_salt_definition( $name, $salt_value ) {
		$new_constant = "\n
			/*
			 * Warning: Changing this value will break decryption for existing users, and prevent
			 * them from logging in with this factor. If you change this you must create a constant
			 * to facilitate migration:
			 *
			 * define( '{$name}_MIGRATE', 'place the old value here' );
			 *
			 * See {@TODO support article URL} for more information.
			 */
			define( '$name', '$salt_value' );\n";

		return str_replace( "\t", '', $new_constant );
	}
}
