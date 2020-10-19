<?php
/**
 * Class for creating a Time Based One-Time Password provider.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Totp
 */
class Two_Factor_Totp extends Two_Factor_Provider {

	/**
	 * The user meta token key.
	 *
	 * @var string
	 */
	const SECRET_META_KEY = '_two_factor_totp_key';

	/**
	 * The user meta token key.
	 *
	 * @var string
	 */
	const NOTICES_META_KEY = '_two_factor_totp_notices';

	/**
	 * Action name for resetting the secret token.
	 *
	 * @var string
	 */
	const ACTION_SECRET_DELETE = 'totp-delete';

	const DEFAULT_KEY_BIT_SIZE        = 160;
	const DEFAULT_CRYPTO              = 'sha1';
	const DEFAULT_DIGIT_COUNT         = 6;
	const DEFAULT_TIME_STEP_SEC       = 30;
	const DEFAULT_TIME_STEP_ALLOWANCE = 4;

	/**
	 * Prefix for encrypted TOTP secrets. Contains a version identifier.
	 *
	 * $t1$ -> TOTP v1 (RFC 6238, encrypted with XChaCha20-Poly1305, with a key derived from HMAC-SHA256
	 *                  of SECURE_AUTH_SAL.)
	 *
	 * @var string
	 */
	const ENCRYPTED_TOTP_PREFIX = '$t1$';

	/**
	 * Current "version" of the TOTP encryption protocol.
	 *
	 * 1 -> $t1$nonce|ciphertext|tag
	 */
	const ENCRYPTED_TOTP_VERSION = 1;

	/**
	 * Chracters used in base32 encoding.
	 *
	 * @var string
	 */
	private static $base_32_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * Class constructor. Sets up hooks, etc.
	 */
	protected function __construct() {
		add_action( 'two_factor_user_options_' . __CLASS__, array( $this, 'user_two_factor_options' ) );
		add_action( 'personal_options_update', array( $this, 'user_two_factor_options_update' ) );
		add_action( 'edit_user_profile_update', array( $this, 'user_two_factor_options_update' ) );
		add_action( 'two_factor_user_settings_action', array( $this, 'user_settings_action' ), 10, 2 );

		return parent::__construct();
	}

	/**
	 * Ensures only one instance of this class exists in memory at any one time.
	 */
	public static function get_instance() {
		static $instance;
		if ( ! isset( $instance ) ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Returns the name of the provider.
	 */
	public function get_label() {
		return _x( 'Time Based One-Time Password (TOTP)', 'Provider Label', 'two-factor' );
	}

	/**
	 * Trigger our custom user settings actions.
	 *
	 * @param integer $user_id User ID.
	 * @param string  $action Action ID.
	 *
	 * @return void
	 */
	public function user_settings_action( $user_id, $action ) {
		if ( self::ACTION_SECRET_DELETE === $action ) {
			$this->delete_user_totp_key( $user_id );
		}
	}

	/**
	 * Get the URL for deleting the secret token.
	 *
	 * @param integer $user_id User ID.
	 *
	 * @return string
	 */
	protected function get_token_delete_url_for_user( $user_id ) {
		return Two_Factor_Core::get_user_update_action_url( $user_id, self::ACTION_SECRET_DELETE );
	}

	/**
	 * Display TOTP options on the user settings page.
	 *
	 * @param WP_User $user The current user being edited.
	 * @return false
	 */
	public function user_two_factor_options( $user ) {
		if ( ! isset( $user->ID ) ) {
			return false;
		}

		wp_nonce_field( 'user_two_factor_totp_options', '_nonce_user_two_factor_totp_options', false );

		$key = $this->get_user_totp_key( $user->ID );
		$this->admin_notices( $user->ID );

		?>
		<div id="two-factor-totp-options">
		<?php
		if ( empty( $key ) ) :
			$key        = $this->generate_key();
			$site_name  = get_bloginfo( 'name', 'display' );
			$totp_title = apply_filters( 'two_factor_totp_title', $site_name . ':' . $user->user_login, $user );
			?>
			<p>
				<?php esc_html_e( 'Please scan the QR code or manually enter the key, then enter an authentication code from your app in order to complete setup.', 'two-factor' ); ?>
			</p>
			<p>
				<img src="<?php echo esc_url( $this->get_google_qr_code( $totp_title, $key, $site_name ) ); ?>" id="two-factor-totp-qrcode" />
			</p>
			<p>
				<code><?php echo esc_html( $key ); ?></code>
			</p>
			<p>
				<input type="hidden" name="two-factor-totp-key" value="<?php echo esc_attr( $key ); ?>" />
				<label for="two-factor-totp-authcode">
					<?php esc_html_e( 'Authentication Code:', 'two-factor' ); ?>
					<input type="tel" name="two-factor-totp-authcode" id="two-factor-totp-authcode" class="input" value="" size="20" pattern="[0-9]*" />
				</label>
				<input type="submit" class="button" name="two-factor-totp-submit" value="<?php esc_attr_e( 'Submit', 'two-factor' ); ?>" />
			</p>
		<?php else : ?>
			<p class="success">
				<?php esc_html_e( 'Secret key is configured and registered. It is not possible to view it again for security reasons.', 'two-factor' ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( self::get_token_delete_url_for_user( $user->ID ) ); ?>"><?php esc_html_e( 'Reset Key', 'two-factor' ); ?></a>
				<em class="description">
					<?php esc_html_e( 'You will have to re-scan the QR code on all devices as the previous codes will stop working.', 'two-factor' ); ?>
				</em>
			</p>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the options specified in `::user_two_factor_options()`
	 *
	 * @param integer $user_id The user ID whose options are being updated.
	 *
	 * @return void
	 */
	public function user_two_factor_options_update( $user_id ) {
		$notices = array();
		$errors  = array();

		if ( isset( $_POST['_nonce_user_two_factor_totp_options'] ) ) {
			check_admin_referer( 'user_two_factor_totp_options', '_nonce_user_two_factor_totp_options' );

			// Validate and store a new secret key.
			if ( ! empty( $_POST['two-factor-totp-authcode'] ) && ! empty( $_POST['two-factor-totp-key'] ) ) {
				// Don't use filter_input() because we can't mock it during tests for now.
				$authcode = filter_var( sanitize_text_field( $_POST['two-factor-totp-authcode'] ), FILTER_SANITIZE_NUMBER_INT );
				$key      = sanitize_text_field( $_POST['two-factor-totp-key'] );

				if ( $this->is_valid_key( $key ) ) {
					if ( $this->is_valid_authcode( $key, $authcode ) ) {
						if ( ! $this->set_user_totp_key( $user_id, $key ) ) {
							$errors[] = __( 'Unable to save Two Factor Authentication code. Please re-scan the QR code and enter the code provided by your application.', 'two-factor' );
						}
					} else {
						$errors[] = __( 'Invalid Two Factor Authentication code.', 'two-factor' );
					}
				} else {
					$errors[] = __( 'Invalid Two Factor Authentication secret key.', 'two-factor' );
				}
			}

			if ( ! empty( $errors ) ) {
				$notices['error'] = $errors;
			}

			if ( ! empty( $notices ) ) {
				update_user_meta( $user_id, self::NOTICES_META_KEY, $notices );
			}
		}
	}

	/**
	 * Get the TOTP secret key for a user.
	 *
	 * @param  int $user_id User ID.
	 *
	 * @return string
	 */
	public function get_user_totp_key( $user_id ) {
		$user_meta_value = get_user_meta( $user_id, self::SECRET_META_KEY, true );
		if ( ! self::is_encrypted( $user_meta_value ) ) {
			$user_meta_value = self::encrypt( $user_meta_value, $user_id );
			update_user_meta( $user_id, self::SECRET_META_KEY, $user_meta_value );
		}
		return self::decrypt( $user_meta_value, $user_id );
	}

	/**
	 * Set the TOTP secret key for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key TOTP secret key.
	 *
	 * @return boolean If the key was stored successfully.
	 */
	public function set_user_totp_key( $user_id, $key ) {
		$encrypted = self::encrypt( $key, $user_id );
		return update_user_meta( $user_id, self::SECRET_META_KEY, $encrypted );
	}

	/**
	 * Delete the TOTP secret key for a user.
	 *
	 * @param  int $user_id User ID.
	 *
	 * @return boolean If the key was deleted successfully.
	 */
	public function delete_user_totp_key( $user_id ) {
		return delete_user_meta( $user_id, self::SECRET_META_KEY );
	}

	/**
	 * Check if the TOTP secret key has a proper format.
	 *
	 * @param  string $key TOTP secret key.
	 *
	 * @return boolean
	 */
	public function is_valid_key( $key ) {
		$check = sprintf( '/^[%s]+$/', self::$base_32_chars );

		if ( 1 === preg_match( $check, $key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Display any available admin notices.
	 *
	 * @param integer $user_id User ID.
	 *
	 * @return void
	 */
	public function admin_notices( $user_id ) {
		$notices = get_user_meta( $user_id, self::NOTICES_META_KEY, true );

		if ( ! empty( $notices ) ) {
			delete_user_meta( $user_id, self::NOTICES_META_KEY );

			foreach ( $notices as $class => $messages ) {
				?>
				<div class="<?php echo esc_attr( $class ); ?>">
					<?php
					foreach ( $messages as $msg ) {
						?>
						<p>
							<span><?php echo esc_html( $msg ); ?><span>
						</p>
						<?php
					}
					?>
				</div>
				<?php
			}
		}
	}

	/**
	 * Validates authentication.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 *
	 * @return bool Whether the user gave a valid code
	 */
	public function validate_authentication( $user ) {
		if ( ! empty( $_REQUEST['authcode'] ) ) {
			return $this->is_valid_authcode(
				$this->get_user_totp_key( $user->ID ),
				sanitize_text_field( $_REQUEST['authcode'] )
			);
		}

		return false;
	}

	/**
	 * Checks if a given code is valid for a given key, allowing for a certain amount of time drift
	 *
	 * @param string $key      The share secret key to use.
	 * @param string $authcode The code to test.
	 *
	 * @return bool Whether the code is valid within the time frame
	 */
	public static function is_valid_authcode( $key, $authcode ) {
		/**
		 * Filter the maximum ticks to allow when checking valid codes.
		 *
		 * Ticks are the allowed offset from the correct time in 30 second increments,
		 * so the default of 4 allows codes that are two minutes to either side of server time
		 *
		 * @deprecated 0.7.0 Use {@see 'two_factor_totp_time_step_allowance'} instead.
		 * @param int $max_ticks Max ticks of time correction to allow. Default 4.
		 */
		$max_ticks = apply_filters_deprecated( 'two-factor-totp-time-step-allowance', array( self::DEFAULT_TIME_STEP_ALLOWANCE ), '0.7.0', 'two_factor_totp_time_step_allowance' );

		$max_ticks = apply_filters( 'two_factor_totp_time_step_allowance', self::DEFAULT_TIME_STEP_ALLOWANCE );

		// Array of all ticks to allow, sorted using absolute value to test closest match first.
		$ticks = range( - $max_ticks, $max_ticks );
		usort( $ticks, array( __CLASS__, 'abssort' ) );

		$time = time() / self::DEFAULT_TIME_STEP_SEC;

		foreach ( $ticks as $offset ) {
			$log_time = $time + $offset;
			if ( self::calc_totp( $key, $log_time ) === $authcode ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generates key
	 *
	 * @param int $bitsize Nume of bits to use for key.
	 *
	 * @return string $bitsize long string composed of available base32 chars.
	 */
	public static function generate_key( $bitsize = self::DEFAULT_KEY_BIT_SIZE ) {
		$bytes  = ceil( $bitsize / 8 );
		$secret = wp_generate_password( $bytes, true, true );

		return self::base32_encode( $secret );
	}

	/**
	 * Pack stuff
	 *
	 * @param string $value The value to be packed.
	 *
	 * @return string Binary packed string.
	 */
	public static function pack64( $value ) {
		// 64bit mode (PHP_INT_SIZE == 8).
		if ( PHP_INT_SIZE >= 8 ) {
			// If we're on PHP 5.6.3+ we can use the new 64bit pack functionality.
			if ( version_compare( PHP_VERSION, '5.6.3', '>=' ) && PHP_INT_SIZE >= 8 ) {
				return pack( 'J', $value );
			}
			$highmap = 0xffffffff << 32;
			$higher  = ( $value & $highmap ) >> 32;
		} else {
			/*
			 * 32bit PHP can't shift 32 bits like that, so we have to assume 0 for the higher
			 * and not pack anything beyond it's limits.
			 */
			$higher = 0;
		}

		$lowmap = 0xffffffff;
		$lower  = $value & $lowmap;

		return pack( 'NN', $higher, $lower );
	}

	/**
	 * Calculate a valid code given the shared secret key
	 *
	 * @param string $key        The shared secret key to use for calculating code.
	 * @param mixed  $step_count The time step used to calculate the code, which is the floor of time() divided by step size.
	 * @param int    $digits     The number of digits in the returned code.
	 * @param string $hash       The hash used to calculate the code.
	 * @param int    $time_step  The size of the time step.
	 *
	 * @return string The totp code
	 */
	public static function calc_totp( $key, $step_count = false, $digits = self::DEFAULT_DIGIT_COUNT, $hash = self::DEFAULT_CRYPTO, $time_step = self::DEFAULT_TIME_STEP_SEC ) {
		$secret = self::base32_decode( $key );

		if ( false === $step_count ) {
			$step_count = floor( time() / $time_step );
		}

		$timestamp = self::pack64( $step_count );

		$hash = hash_hmac( $hash, $timestamp, $secret, true );

		$offset = ord( $hash[19] ) & 0xf;

		$code = (
				( ( ord( $hash[ $offset + 0 ] ) & 0x7f ) << 24 ) |
				( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) |
				( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 ) |
				( ord( $hash[ $offset + 3 ] ) & 0xff )
			) % pow( 10, $digits );

		return str_pad( $code, $digits, '0', STR_PAD_LEFT );
	}

	/**
	 * Uses the Google Charts API to build a QR Code for use with an otpauth url
	 *
	 * @param string $name  The name to display in the Authentication app.
	 * @param string $key   The secret key to share with the Authentication app.
	 * @param string $title The title to display in the Authentication app.
	 *
	 * @return string A URL to use as an img src to display the QR code
	 */
	public static function get_google_qr_code( $name, $key, $title = null ) {
		// Encode to support spaces, question marks and other characters.
		$name       = rawurlencode( $name );
		$google_url = urlencode( 'otpauth://totp/' . $name . '?secret=' . $key );
		if ( isset( $title ) ) {
			$google_url .= urlencode( '&issuer=' . rawurlencode( $title ) );
		}
		return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . $google_url;
	}

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 *
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		// Only available if the secret key has been saved for the user.
		$key = $this->get_user_totp_key( $user->ID );

		return ! empty( $key );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		require_once ABSPATH . '/wp-admin/includes/template.php';
		?>
		<p>
			<?php esc_html_e( 'Please enter the code generated by your authenticator app.', 'two-factor' ); ?>
		</p>
		<p>
			<label for="authcode"><?php esc_html_e( 'Authentication Code:', 'two-factor' ); ?></label>
			<input type="tel" autocomplete="off" name="authcode" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<script type="text/javascript">
			setTimeout( function(){
				var d;
				try{
					d = document.getElementById('authcode');
					d.focus();
				} catch(e){}
			}, 200);
		</script>
		<?php
		submit_button( __( 'Authenticate', 'two-factor' ) );
	}

	/**
	 * Returns a base32 encoded string.
	 *
	 * @param string $string String to be encoded using base32.
	 *
	 * @return string base32 encoded string without padding.
	 */
	public static function base32_encode( $string ) {
		if ( empty( $string ) ) {
			return '';
		}

		$binary_string = '';

		foreach ( str_split( $string ) as $character ) {
			$binary_string .= str_pad( base_convert( ord( $character ), 10, 2 ), 8, '0', STR_PAD_LEFT );
		}

		$five_bit_sections = str_split( $binary_string, 5 );
		$base32_string     = '';

		foreach ( $five_bit_sections as $five_bit_section ) {
			$base32_string .= self::$base_32_chars[ base_convert( str_pad( $five_bit_section, 5, '0' ), 2, 10 ) ];
		}

		return $base32_string;
	}

	/**
	 * Decode a base32 string and return a binary representation
	 *
	 * @param string $base32_string The base 32 string to decode.
	 *
	 * @throws Exception If string contains non-base32 characters.
	 *
	 * @return string Binary representation of decoded string
	 */
	public static function base32_decode( $base32_string ) {

		$base32_string = strtoupper( $base32_string );

		if ( ! preg_match( '/^[' . self::$base_32_chars . ']+$/', $base32_string, $match ) ) {
			throw new Exception( 'Invalid characters in the base32 string.' );
		}

		$l      = strlen( $base32_string );
		$n      = 0;
		$j      = 0;
		$binary = '';

		for ( $i = 0; $i < $l; $i++ ) {

			$n  = $n << 5; // Move buffer left by 5 to make room.
			$n  = $n + strpos( self::$base_32_chars, $base32_string[ $i ] );    // Add value into buffer.
			$j += 5; // Keep track of number of bits in buffer.

			if ( $j >= 8 ) {
				$j      -= 8;
				$binary .= chr( ( $n & ( 0xFF << $j ) ) >> $j );
			}
		}

		return $binary;
	}

	/**
	 * Used with usort to sort an array by distance from 0
	 *
	 * @param int $a First array element.
	 * @param int $b Second array element.
	 *
	 * @return int -1, 0, or 1 as needed by usort
	 */
	private static function abssort( $a, $b ) {
		$a = abs( $a );
		$b = abs( $b );
		if ( $a === $b ) {
			return 0;
		}
		return ( $a < $b ) ? -1 : 1;
	}

	/**
	 * Is this string an encrypted TOTP secret?
	 *
	 * @param string $secret Stored TOTP secret.
	 * @return bool
	 */
	public static function is_encrypted( $secret ) {
		if ( strlen( $secret ) < 40 ) {
			return false;
		}
		if ( strpos( $secret, self::ENCRYPTED_TOTP_PREFIX ) !== 0 ) {
			return false;
		}
		return true;
	}

	/**
	 * Encrypt a TOTP secret.
	 *
	 * @param string $secret  TOTP secret.
	 * @param int    $user_id User ID.
	 * @param int    $version (Optional) Version ID.
	 * @return string
	 * @throws SodiumException From sodium_compat or ext/sodium.
	 */
	public static function encrypt( $secret, $user_id, $version = self::ENCRYPTED_TOTP_VERSION ) {
		$prefix     = self::get_version_header( $version );
		$nonce      = random_bytes( 24 );
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
			$secret,
			self::serialize_aad( $prefix, $nonce, $user_id ),
			$nonce,
			self::get_key( $version )
		);
		// @codingStandardsIgnoreStart
		return self::ENCRYPTED_TOTP_PREFIX . base64_encode( $nonce . $ciphertext );
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Decrypt a TOTP secret.
	 *
	 * Version information is encoded with the ciphertext and thus omitted from this function.
	 *
	 * @param string $encrypted Encrypted TOTP secret.
	 * @param int    $user_id User ID.
	 * @return string
	 * @throws RuntimeException Decryption failed.
	 */
	public static function decrypt( $encrypted, $user_id ) {
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
					self::get_key( $version )
				);
			} catch ( SodiumException $ex ) {
				throw new RuntimeException( 'Decryption failed', 0, $ex );
			}
		} else {
			throw new RuntimeException( 'Unknown version: ' . $version );
		}

		// If we don't have a string, throw an exception because decryption failed.
		if ( ! is_string( $decrypted ) ) {
			throw new RuntimeException( 'Could not decrypt TOTP secret' );
		}
		return $decrypted;
	}

	/**
	 * Serialize the Additional Authenticated Data for TOTP secret encryption.
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
	final private static function get_version_header( $number = self::ENCRYPTED_TOTP_VERSION ) {
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
	final private static function get_version_id( $prefix = self::ENCRYPTED_TOTP_PREFIX ) {
		switch ( $prefix ) {
			case '$t1$':
				return 1;
		}
		throw new RuntimeException( 'Incorrect version identifier: ' . $prefix );
	}

	/**
	 * Get the encryption key for encrypting TOTP secrets.
	 *
	 * @param int $version Key derivation strategy.
	 * @return string
	 * @throws RuntimeException For incorrect versions.
	 */
	final private static function get_key( $version = self::ENCRYPTED_TOTP_VERSION ) {
		if ( 1 === $version ) {
			return hash_hmac( 'sha256', SECURE_AUTH_SALT, 'totp-encryption', true );
		}
		throw new RuntimeException( 'Incorrect version number: ' . $version );
	}
}
