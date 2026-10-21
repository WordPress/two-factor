<?php
/**
 * In-memory secrets store for tests.
 *
 * @package Two_Factor
 */

/**
 * A Two_Factor_Secrets_Store that keeps secrets in an array.
 *
 * Tests install it with Two_Factor_Secrets::set_instance(), so they run without
 * the Secrets API feature plugin, and flip its public properties to simulate a
 * store that is missing, read-only or failing.
 */
class Two_Factor_Secrets_Memory_Store implements Two_Factor_Secrets_Store {

	/**
	 * Whether the store reports itself as available.
	 *
	 * @var bool
	 */
	public $available = true;

	/**
	 * Whether the store reports itself as writable.
	 *
	 * @var bool
	 */
	public $writable = true;

	/**
	 * Stored secrets by name.
	 *
	 * @var array<string, string>
	 */
	public $values = array();

	/**
	 * When set, called instead of reading; receives the name and returns what get() should.
	 *
	 * @var callable|null
	 */
	public $on_get = null;

	/**
	 * When set, called instead of writing; receives the name and value and returns what set() should.
	 *
	 * @var callable|null
	 */
	public $on_set = null;

	/**
	 * Whether the store is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Whether the store is writable.
	 *
	 * @return bool
	 */
	public function is_writable(): bool {
		return $this->writable;
	}

	/**
	 * Label of the store.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Memory';
	}

	/**
	 * Read a secret.
	 *
	 * @param string $name Secret name.
	 * @return string|null|WP_Error
	 */
	public function get( string $name ) {
		if ( null !== $this->on_get ) {
			return call_user_func( $this->on_get, $name );
		}

		return isset( $this->values[ $name ] ) ? $this->values[ $name ] : null;
	}

	/**
	 * Write a secret.
	 *
	 * @param string $name  Secret name.
	 * @param string $value Secret value.
	 * @return true|WP_Error
	 */
	public function set( string $name, string $value ) {
		if ( null !== $this->on_set ) {
			return call_user_func( $this->on_set, $name, $value );
		}

		$this->values[ $name ] = $value;

		return true;
	}

	/**
	 * Delete a secret.
	 *
	 * @param string $name Secret name.
	 * @return true
	 */
	public function delete( string $name ) {
		unset( $this->values[ $name ] );

		return true;
	}
}
