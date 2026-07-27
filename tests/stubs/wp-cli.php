<?php
/**
 * WP-CLI stubs for PHPStan analysis.
 *
 * @package Two_Factor
 */

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/**
			 * @param string          $name     Command name.
			 * @param string|callable $callable Command handler.
			 * @param array           $args     Optional arguments.
			 * @return void
			 */
			public static function add_command( $name, $callable, $args = array() ) {}

			/**
			 * @param string $message Error message.
			 * @return void
			 */
			public static function error( $message ) {}

			/**
			 * @param string $message Success message.
			 * @return void
			 */
			public static function success( $message ) {}

			/**
			 * @param string $message Log message.
			 * @return void
			 */
			public static function log( $message ) {}

			/**
			 * @param string $message Warning message.
			 * @return void
			 */
			public static function warning( $message ) {}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\\Utils\\get_flag_value' ) ) {
		/**
		 * @param array  $assoc_args Associative arguments.
		 * @param string $flag       Flag name.
		 * @param mixed  $default    Default value.
		 * @return mixed
		 */
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
		}
	}
}
