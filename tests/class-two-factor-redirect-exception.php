<?php
/**
 * Redirect exception used by core tests.
 *
 * @package Two_Factor
 */

/**
 * Exception thrown when wp_redirect fires during tests.
 */
class Two_Factor_Redirect_Exception extends RuntimeException {}
