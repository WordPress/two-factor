<?php
/**
 * Exception used by the WP-CLI test double to stand in for a process exit.
 *
 * @package Two_Factor
 */

if ( ! class_exists( 'WP_CLI_Mock_Exit_Exception' ) ) {
	/**
	 * Thrown by the mocked WP_CLI::error() and WP_CLI::confirm() so tests can
	 * assert on the "exit" behaviour instead of the process actually terminating.
	 */
	class WP_CLI_Mock_Exit_Exception extends Exception {}
}
