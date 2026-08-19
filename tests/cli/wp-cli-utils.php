<?php
/**
 * Test doubles for the WP_CLI\Utils helper functions.
 *
 * Provides just the two helpers used by Two_Factor_CLI_Command. Kept in a
 * dedicated namespaced file so the fully-qualified `WP_CLI\Utils\...` calls in
 * the command resolve during PHPUnit runs.
 *
 * @package Two_Factor
 */

namespace WP_CLI\Utils;

if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
	/**
	 * Read an associative flag with a fallback default.
	 *
	 * @param array  $assoc_args Associative CLI args.
	 * @param string $flag       Flag name.
	 * @param mixed  $fallback   Value to return when the flag is absent.
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $fallback = null ) {
		return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $fallback;
	}
}

if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	/**
	 * Capture a formatted-items request for assertions.
	 *
	 * @param string $format Output format (table|json|csv|yaml).
	 * @param array  $items  Row data.
	 * @param array  $fields Field list.
	 */
	function format_items( $format, $items, $fields ) {
		\WP_CLI::$logger[] = array(
			'level'  => 'format',
			'format' => $format,
			'items'  => $items,
			'fields' => $fields,
		);
	}
}
