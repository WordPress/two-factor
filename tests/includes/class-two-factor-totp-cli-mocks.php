<?php
/**
 * Mock WP-CLI classes and functions for testing.
 *
 * @package Two_Factor
 */

/**
 * Mock WP_CLI class for testing outside the WP-CLI environment.
 */
class WP_CLI {

	/**
	 * @var Two_Factor_Totp_Cli_Testable|null
	 */
	public static $test_instance = null;

	/**
	 * @param string $message Error message.
	 * @throws Exception Always throws to simulate WP_CLI::error() halting execution.
	 */
	public static function error( $message ) {
		if ( self::$test_instance ) {
			self::$test_instance->result_type    = 'error';
			self::$test_instance->result_message = $message;
		}
		throw new Exception( 'WP_CLI::error: ' . $message );
	}

	/**
	 * @param string $message Success message.
	 */
	public static function success( $message ) {
		if ( self::$test_instance ) {
			self::$test_instance->result_type    = 'success';
			self::$test_instance->result_message = $message;
		}
	}

	/**
	 * @param string $message Log message.
	 */
	public static function log( $message ) {
		if ( self::$test_instance ) {
			self::$test_instance->logs[] = $message;
		}
	}

	/**
	 * @param string $message Warning message.
	 */
	public static function warning( $message ) {
		if ( self::$test_instance ) {
			self::$test_instance->warnings[] = $message;
		}
	}
}
