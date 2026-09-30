<?php
// phpcs:ignoreFile -- PHPStan stubs for the WordPress Secrets API; never executed.
/**
 * PHPStan stubs for the Secrets API feature plugin.
 *
 * @package Two_Factor
 */

/**
 * Store a network-scoped secret.
 *
 * @param string $name  Secret name.
 * @param string $value Secret value.
 * @return true|WP_Error
 */
function wp_set_network_secret( $name, $value ) {}

/**
 * Retrieve a network-scoped secret.
 *
 * @param string $name    Secret name.
 * @param string $version Version to read.
 * @return WP_Secret|null|WP_Error
 */
function wp_get_network_secret( $name, $version = WP_Secret_Version::CURRENT ) {}

/**
 * Delete a network-scoped secret.
 *
 * @param string $name Secret name.
 * @return true|WP_Error
 */
function wp_delete_network_secret( $name ) {}

/**
 * Whether the active secrets provider accepts writes.
 *
 * @return bool
 */
function wp_secrets_provider_is_writable() {}

/**
 * Human-readable label of the active secrets provider.
 *
 * @return string
 */
function wp_secrets_provider_label() {}

/**
 * Overwrite a value in memory.
 *
 * @param string $value Value to wipe.
 * @return void
 */
function wp_secrets_memzero( &$value ) {}

/**
 * A retrieved secret.
 */
final class WP_Secret {
	/**
	 * Reveal the plaintext value.
	 *
	 * @return string|WP_Error
	 */
	public function reveal() {}

	/**
	 * Secret name.
	 *
	 * @return string
	 */
	public function get_name() {}

	/**
	 * Fingerprint of the value.
	 *
	 * @return string
	 */
	public function fingerprint() {}
}

/**
 * Secret version identifiers.
 */
final class WP_Secret_Version {
	const CURRENT  = 'current';
	const PREVIOUS = 'previous';
}
