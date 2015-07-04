<?php

class Two_Factor_Totp extends Two_Factor_Provider {

	const DEFAULT_KEY_BIT_SIZE = 80;
	const DEFAULT_CRYPTO = 'sha1';
	const DEFAULT_DIGIT_COUNT = 6;
	const DEFAULT_TIME_STEP_SEC = 30;

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}


	function get_label() {
		return _x( 'Time Based One-Time Password (Google Authenticator)', 'Provider Label', 'two-factor' );
	}

	function authentication_page( $user ) {}
	function validate_authentication( $user, $value, $key, $time, $accept_step_past = 2, $accept_step_future = 1, $digits = self::DEFAULT_DIGIT_COUNT, $hash = self::DEFAULT_CRYPTO, $time_step_sec = self::DEFAULT_TIME_STEP_SEC ) {
		$binary_key = base64_decode( strtoupper( $key ) );
		$current_time_step = (int)floor( (int)$time / (int)$time_step_sec );
		$digits = (int)$digits;
		$hash = strtolower( $hash );

		$step_start = $current_time_step - (int)$accept_step_past;
		$step_end   = $current_time_step + (int)$accept_step_future + 1;
		for( $test_step = $step_start, $test_step < $step_end, ++$test_step ) {
			$test_value = self::calcMain($binary_key, $test_step, $digits, $hash);
			if( $test_value === $value ) {
				// @TODO: do logon stuffz
			}
		}
		return false;
	}

	public static function generate_key( $bitsize = self::DEFAULT_KEY_BIT_SIZE ) {
		global $wp_hasher;
		if( $bitsize < 8 || $bitsize % 8 !== 0 ) {
			// @TODO: handle this
			wp_die(-1);
		}
		if( empty( $wp_hasher ) {
			require_once ABSPATH . WPINC . '/class-phpass.php';
			$wp_hasher = new PasswordHash( 8, true );
		}
		return base64_encode( $wp_hasher->get_random_bytes( $bitsize / 8 ) );
	}

	private static function pack64( $value ) {
		if( version_compare( PHP_VERSION, '5.6.3', '>=' ) ) {
			return pack( 'J', $value );
		}
		$highmap = 0xffffffff << 32;
		$lowmap  = 0xffffffff;
		$higher = ( $value & $highmap ) >> 32;
		$lower = $value & $lowmap;
		return pack( 'NN', $higher, $lower );
	}

	public static function get_code( $key, $time, $digits = self::DEFAULT_DIGIT_COUNT, $hash = self::DEFAULT_CRYPTO, $time_step_sec = self::DEFAULT_TIME_STEP_SEC ) {
		if( is_int( $digits ) && is_numeric( $digits ) {
			$digits = (int)$digits;
			if( 1 <= $digits && 8 >= $digits ) {
				$timestep = (int)floor( (int)$time / (int)$time_step_sec;
				return self::calc_totp( base64_decode( strtoupper( $key ) ), $timestep, $digits, strtolower( $hash ) );
			}
		}
	}

	private static function calc_totp( $binary_key, $step_count, $digits, $hash ) {
		$time_step = self::pack64($step_count);
		$hmac = hash_hmac( $hash, $time_step, $binary_key, true );
		$offset = ord($hmac[strlen($hmac) - 1]) & 0x0f;
		$int = ((ord($hmac[$offset]) & 0x7f) << 24) +
			((ord($hmac[$offset + 1])) << 16) +
			((ord($hmac[$offset + 2])) << 8) +
			((ord($hmac[$offset + 3])) << 0);
		$totp = (string)($intValue % pow(10, $digits));
		return substr( str_repeat( '0', $digits ) . $otp, -$digits);
	}
}
