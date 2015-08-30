<?php
/**
 * Class for creating a Time Based One-Time Password provider.
 *
 * @package Two_Factor
 */
class Two_Factor_Totp extends Two_Factor_Provider {

	/**
	 * The user meta token key.
	 * @type string
	 */
	const SECRET_META_KEY = '_two_factor_totp_key';

	/**
	 * The user meta token key.
	 * @type string
	 */
	const NOTICES_META_KEY = '_two_factor_totp_notices';

	const DEFAULT_KEY_BIT_SIZE = 80;
	const DEFAULT_CRYPTO = 'sha1';
	const DEFAULT_DIGIT_COUNT = 6;
	const DEFAULT_TIME_STEP_SEC = 30;
	const DEFAULT_TIME_STEP_ALLOWANCE = 4;
	private $_base_32_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	protected function __construct() {
		add_action( 'show_user_profile',        array( $this, 'user_two_factor_options' ), 11 );
		add_action( 'edit_user_profile',        array( $this, 'user_two_factor_options' ), 11 );
		add_action( 'personal_options_update',  array( $this, 'user_two_factor_options_update' ) );
		add_action( 'edit_user_profile_update', array( $this, 'user_two_factor_options_update' ) );
		return parent::__construct();
	}

	/**
	 * Ensures only one instance of this class exists in memory at any one time.
	 */
	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	/**
	 * Returns the name of the provider.
	 */
	public function get_label() {
		return _x( 'Time Based One-Time Password (Google Authenticator)', 'Provider Label' );
	}

	public function user_two_factor_options( $user ) {
		wp_nonce_field( 'user_two_factor_totp_options', '_nonce_user_two_factor_totp_options', false );
		?>
		<table class="form-table" id="two-factor-totp-options">
			<tr>
				<th>
					<?php echo esc_html_x( 'Time Based One-Time Password (Google Authenticator) Options', 'Provider Label' ); ?>
				</th>
				<td>
					<?php
						$key = get_user_meta( $user->ID, self::SECRET_META_KEY, true );

						$this->admin_notices();
						if ( empty( $key ) ) {
							$key = $this->generate_key();
							$site_name = get_bloginfo( 'name', 'display' );
							?>
							<img src="<?php echo $this->get_google_qr_code( $site_name . ':' . $user->user_login, $key, $site_name ); ?>" id="two-factor-totp-qrcode" />
							<p><strong><?php echo esc_html( $key ); ?></strong></p>
							<p><?php _e( 'Please scan the QR code or manually enter the key, then enter an authentication code from your app in order to complete setup' ); ?></p>
							<p>
								<label for="two-factor-totp-authcode"><?php esc_html_e( 'Authentication Code:' ); ?></label>
								<input type="hidden" name="two-factor-totp-key" value="<?php echo esc_attr( $key ) ?>" />
								<input type="tel" name="two-factor-totp-authcode" id="two-factor-totp-authcode" class="input" value="" size="20" pattern="[0-9]*" />
							</p>
							<?php
						} else {
							?>
							<p class="success">Enabled</p>
							<?php
						}
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	public function user_two_factor_options_update( $user_id ) {
		if ( isset( $_POST['_nonce_user_two_factor_totp_options'] ) ) {
			check_admin_referer( 'user_two_factor_totp_options', '_nonce_user_two_factor_totp_options' );

			$current_key = get_user_meta( $user_id, self::SECRET_META_KEY, true );
			// If the key hasn't changed or is invalid, do nothing
			if ( $current_key === $_POST['two-factor-totp-key'] || ! preg_match( '/^[' . $this->_base_32_chars . ']+$/', $_POST['two-factor-totp-key'] ) ) {
				return;
			}

			$notices = array();

			if ( empty( $_POST['two-factor-totp-authcode'] ) ) {
				$notices['error'][] = __( 'Two Factor Authentication not activated, you must specify authcode to ensure it is properly set up. Please re-scan the QR code and enter the code provided by your application.' );
			}

			if ( $this->_is_valid_authcode( $_POST['two-factor-totp-key'], $_POST['two-factor-totp-authcode'] ) ) {
				if ( ! update_user_meta( $user_id, self::SECRET_META_KEY, $_POST['two-factor-totp-authcode'] ) ) {
					$notices['error'][] = __( 'Unable to save Two Factor Authentication code. Please re-scan the QR code and enter the code provided by your application.' );
				}
			} else {
				$notices['error'][] = __( 'Two Factor Authentication not activated, the authentication code you entered was not valid. Please re-scan the QR code and enter the code provided by your application.' );
			}

			if ( ! empty( $notices ) ) {
				update_user_meta( $user_id, self::NOTICES_META_KEY, $notices );
			}
		}
	}

	public function admin_notices() {
		$notices = get_user_meta( get_current_user_id(), self::NOTICES_META_KEY, true );

		if ( ! empty( $notices ) ) {
			delete_user_meta( get_current_user_id(), self::NOTICES_META_KEY );
			foreach ( $notices as $class => $messages ) {
				?>
				<div class="<?php echo esc_attr( $class ) ?>">
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
		$key = get_user_meta( $user->ID, self::SECRET_META_KEY, true );
		return $this->_is_valid_authcode( $key, $_REQUEST['authcode'] );
	}

	/**
	 * Checks if a given code is valid for a given key, allowing for a certain amount of time drift
	 *
	 * @param string $key      The share secret key to use
	 * @param string $authcode The code to test
	 *
	 * @return bool Whether the code is valid within the time frame
	 */
	private function _is_valid_authcode( $key, $authcode ) {
		/**
		 * Filter the maximum ticks to allow when checking valid codes.
		 *
		 * Ticks are the allowed offset from the correct time in 30 second increments,
		 * so the default of 4 allows codes that are two minutes to either side of server time
		 *
		 * @param int $max_ticks Max ticks of time correction to allow. Default 4.
		 */
		$max_ticks = apply_filters( 'two-factor-totp-time-step-allowance', self::DEFAULT_TIME_STEP_ALLOWANCE );

		// Array of all ticks to allow, sorted using absolute value to test closest match first.
		$ticks = range( - $max_ticks, $max_ticks );
		usort( $ticks, array( $this, 'abssort' ) );

		$time = time() / self::DEFAULT_TIME_STEP_SEC;

		foreach ( $ticks as $offset ) {
			$log_time = $time + $offset;
			if ( $this->calc_totp( $key, $log_time ) === $authcode ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generates key
	 *
	 * @param int $bitsize Nume of bits to use for key.
	 */
	public function generate_key( $bitsize = self::DEFAULT_KEY_BIT_SIZE ) {
		if( $bitsize < 8 || $bitsize % 8 !== 0 ) {
			// @TODO: handle this case
			wp_die(-1);
		}

		$s 	= '';

		for ( $i = 0; $i < $bitsize / 8; $i++ ) {
			$s .= $this->_base_32_chars[ rand( 0, 31 ) ];
		}

		return $s;
	}

	/**
	 * Pack stuff
	 *
	 * @param $value
	 */
	private static function pack64( $value ) {
		if( version_compare( PHP_VERSION, '5.6.3', '>=' ) ) {
			return pack( 'J', $value );
		}
		$highmap = 0xffffffff << 32;
		$lowmap  = 0xffffffff;
		$higher  = ( $value & $highmap ) >> 32;
		$lower   = $value & $lowmap;
		return pack( 'NN', $higher, $lower );
	}

	/**
	 * Calculate a valid code given the shared secret key
	 *
	 * @param string $key        The shared secret key to use for calculating code
	 * @param int    $step_count The time step used to calculate the code, which is the floor of time() divided by step size
	 * @param int    $digits     The number of digits in the returned code
	 * @param string $hash       The hash used to calculate the code
	 * @param int    $time_step  The size of the time step
	 *
	 * @return string The totp code
	 */
	private function calc_totp( $key, $step_count = false, $digits = self::DEFAULT_DIGIT_COUNT, $hash = self::DEFAULT_CRYPTO, $time_step = self::DEFAULT_TIME_STEP_SEC ) {
		$secret = $this->base32_decode( $key );

		if ( false === $step_count ) {
			$step_count = floor( time() / $time_step );
		}

		$timestamp = $this->pack64( $step_count );

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
	 * @param string $name  The name to display in the Authentication app
	 * @param string $key   The secret key to share with the Authentication app
	 * @param string $title The title to display in the Authentication app
	 *
	 * @return string A URL to use as an img src to display the QR code
	 */
	public static function get_google_qr_code( $name, $key, $title = null ) {
		$google_url = urlencode('otpauth://totp/' . $name . '?secret=' . $key );
		if( isset( $title ) ) {
			$google_url .= urlencode('&issuer=' . urlencode( $title ) );
		}
		return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . $google_url;
	}

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function is_available_for_user( $user ) {
		// Only available if the secret key has been saved for the user
		$key = get_user_meta( $user->ID, self::SECRET_META_KEY, true );

		return ! empty( $key );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p>
			<label for="authcode"><?php esc_html_e( 'Authentication Code:' ); ?></label>
			<input type="tel" name="authcode" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<script type="text/javascript">
			setTimeout( function(){
				var d;
				try{
					d = document.getElementById('authcode');
					d.value = '';
					d.focus();
				} catch(e){}
			}, 200);
		</script>
		<?php
		submit_button( __( 'Authenticate' ) );
	}

	/**
	 * Decode a base32 string and return a binary representation
	 *
	 * @param string $base32_string The base 32 string to decode
	 *
	 * @return Binary representation of decoded string
	 */
	public function base32_decode( $base32_string ) {

		$base32_string 	= strtoupper( $base32_string );

		if ( ! preg_match( '/^[' . $this->_base_32_chars . ']+$/', $base32_string, $match ) ) {
			throw new Exception('Invalid characters in the base32 string.');
		}

		$l 	= strlen( $base32_string );
		$n	= 0;
		$j	= 0;
		$binary = '';

		for ( $i = 0; $i < $l; $i++ ) {

			$n = $n << 5; // Move buffer left by 5 to make room
			$n = $n + strpos( $this->_base_32_chars, $base32_string[$i] ); 	// Add value into buffer
			$j += 5; // Keep track of number of bits in buffer

			if ( $j >= 8 ) {
				$j -= 8;
				$binary .= chr( ( $n & ( 0xFF << $j ) ) >> $j );
			}
		}

		return $binary;
	}

	/**
	 * Used with usort to sort an array by distance from 0
	 *
	 * @param int $a First array element
	 * @param int $b Second array element
	 *
	 * @return int -1, 0, or 1 as needed by usort
	 */
	private function abssort ( $a, $b ) {
		$a = abs( $a );
		$b = abs( $b );
		if ( $a == $b ) {
			return 0;
		}
		return ($a < $b) ? -1 : 1;
	}

}
