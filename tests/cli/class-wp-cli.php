<?php
/**
 * Test double for the WP-CLI facade class.
 *
 * Captures output into WP_CLI::$logger so tests can assert on it, throws from
 * error()/confirm() to mimic a process exit, and otherwise no-ops. Only the
 * surface used by Two_Factor_CLI_Command is implemented.
 *
 * @package Two_Factor
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal stand-in for the real WP_CLI class.
	 */
	class WP_CLI {

		/**
		 * Ordered list of captured messages.
		 *
		 * Each entry has a `level` key (log|success|warning|error|confirm|format)
		 * plus level-specific data.
		 *
		 * @var array
		 */
		public static $logger = array();

		/**
		 * Reset the captured output between tests.
		 */
		public static function reset() {
			self::$logger = array();
		}

		/**
		 * Record an informational message.
		 *
		 * @param string $message Message text.
		 */
		public static function log( $message ) {
			self::$logger[] = array(
				'level'   => 'log',
				'message' => $message,
			);
		}

		/**
		 * Record a success message.
		 *
		 * @param string $message Message text.
		 */
		public static function success( $message ) {
			self::$logger[] = array(
				'level'   => 'success',
				'message' => $message,
			);
		}

		/**
		 * Record a warning message.
		 *
		 * @param string $message Message text.
		 */
		public static function warning( $message ) {
			self::$logger[] = array(
				'level'   => 'warning',
				'message' => $message,
			);
		}

		/**
		 * Record an error message and, by default, abort like the real WP_CLI::error().
		 *
		 * @param string $message   Message text.
		 * @param bool   $exit_flag Whether to throw to mimic a process exit.
		 *
		 * @throws WP_CLI_Mock_Exit_Exception When $exit_flag is true.
		 */
		public static function error( $message, $exit_flag = true ) {
			self::$logger[] = array(
				'level'   => 'error',
				'message' => $message,
			);

			if ( $exit_flag ) {
				$text = is_array( $message ) ? implode( "\n", $message ) : (string) $message;
				throw new WP_CLI_Mock_Exit_Exception( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double; message is not rendered to a browser.
			}
		}

		/**
		 * Mimic WP_CLI::confirm(): proceed when --yes is set, otherwise abort.
		 *
		 * @param string $question   Confirmation prompt.
		 * @param array  $assoc_args Associative CLI args.
		 *
		 * @throws WP_CLI_Mock_Exit_Exception When --yes is not present.
		 */
		public static function confirm( $question, $assoc_args = array() ) {
			self::$logger[] = array(
				'level'   => 'confirm',
				'message' => $question,
			);

			if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes' ) ) {
				return;
			}

			throw new WP_CLI_Mock_Exit_Exception( 'confirmation declined' );
		}

		/**
		 * No-op command registration used by the plugin bootstrap.
		 *
		 * @param string $name    Command name.
		 * @param mixed  $handler Command handler.
		 * @param array  $args    Registration args.
		 */
		public static function add_command( $name, $handler, $args = array() ) {}

		/**
		 * Return the captured messages, optionally filtered by level.
		 *
		 * @param string|null $level Optional level filter.
		 * @return array
		 */
		public static function get_logs( $level = null ) {
			if ( null === $level ) {
				return self::$logger;
			}

			return array_values(
				array_filter(
					self::$logger,
					function ( $entry ) use ( $level ) {
						return $entry['level'] === $level;
					}
				)
			);
		}
	}
}
