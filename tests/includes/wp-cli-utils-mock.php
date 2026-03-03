<?php
/**
 * Mock WP_CLI\Utils functions for testing.
 *
 * @package Two_Factor
 */

namespace WP_CLI\Utils;

/**
 * Mock implementation of WP_CLI\Utils\get_flag_value.
 *
 * @param array  $assoc_args Associative arguments.
 * @param string $flag       Flag name.
 * @param mixed  $default    Default value.
 *
 * @return mixed
 */
function get_flag_value( $assoc_args, $flag, $default = null ) {
	return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
}
