<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants that are set at runtime in two-factor.php
 * but unreachable during static analysis because of the ABSPATH guard.
 */

define( 'TWO_FACTOR_DIR', __DIR__ . '/' );
define( 'TWO_FACTOR_VERSION', '0.15.0' );
