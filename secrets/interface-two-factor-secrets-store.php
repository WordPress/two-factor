<?php
/**
 * Contract for where Two-Factor physically keeps secrets.
 *
 * @package Two_Factor
 */

/**
 * A place secrets are stored by name.
 *
 * Two_Factor_Secrets_Manager decides whether and when a secret is stored; a
 * store only knows how. Two_Factor_Secrets_Api_Store is the one used on a live
 * site, and tests supply an in-memory one.
 *
 * @since 0.18.0
 */
interface Two_Factor_Secrets_Store {

	/**
	 * Whether the store can be used at all right now.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Whether the store accepts writes. Only meaningful while it is available.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public function is_writable(): bool;

	/**
	 * Human-readable name of whatever backs the store.
	 *
	 * @since 0.18.0
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Read a secret.
	 *
	 * @since 0.18.0
	 *
	 * @param string $name Secret name.
	 * @return string|null|WP_Error The value, null when there is no such secret, or why it could not be read.
	 */
	public function get( string $name );

	/**
	 * Write a secret.
	 *
	 * @since 0.18.0
	 *
	 * @param string $name  Secret name.
	 * @param string $value Secret value.
	 * @return true|WP_Error
	 */
	public function set( string $name, string $value );

	/**
	 * Delete a secret. Deleting one that does not exist is a success.
	 *
	 * @since 0.18.0
	 *
	 * @param string $name Secret name.
	 * @return true|WP_Error
	 */
	public function delete( string $name );
}
