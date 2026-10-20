<?php
/**
 * Stub of the WP-CLI base command class for the PHPUnit environment.
 *
 * The WP-CLI runtime is not loaded during PHPUnit runs, so this empty stub lets
 * `Two_Factor_CLI_Command extends WP_CLI_Command` load.
 *
 * @package Two_Factor
 */

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Minimal stand-in for the real WP_CLI_Command base class.
	 */
	class WP_CLI_Command {}
}
