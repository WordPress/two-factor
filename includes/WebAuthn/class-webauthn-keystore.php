<?php

if ( ! defined('ABSPATH') ) {
	die('Bye!');
}

/**
 *	Simple CRUD for keys
 */
class WebAuthnKeyStore {

	const PUBKEY_USERMETA_KEY = '_two_factor_webauthn_pubkey';


	/**
	 * Array containing derived class instances
	 */
	private static $instance = null;

	/**
	 * Getting a singleton.
	 *
	 * @return object single instance of Core
	 */
	public static function instance() {

		$class = get_called_class();

		if ( is_null( self::$instance ) ) {
			$args = func_get_args();
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 *	Prevent Instantinating
	 */
	private function __clone() { }

	/**
	 *	Protected constructor
	 */
	protected function __construct() {
	}

	/**
	 * Get all keys for user
	 *
	 * @param int $user_id
	 * @return array
	 */
	public function get_keys( $user_id ) {
		return get_user_meta( $user_id, self::PUBKEY_USERMETA_KEY );
	}

	/**
	 * Find specific key for user by
	 *
	 * @param int $user_id
	 * @return string|bool
	 */
	public function find_key( $user_id, $keyLike = null ) {
		global $wpdb;
		if ( is_null( $keyLike ) ) {
			return false;
		}

		$found = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $wpdb->usermeta WHERE user_id=%d AND meta_key=%s AND meta_value LIKE %s",
			$user_id,
			self::PUBKEY_USERMETA_KEY,
			$wpdb->esc_like( '%' . $keyLike . '%' )
		) );
		foreach ( $found as $key ) {
			return maybe_unserialize( $key->meta_value );
		}
		return false;

	}

	/**
	 * Add key to user
	 *
	 * @param int $user_id
	 * @param string $key
	 * @return bool
	 */
	private function create_key( $user_id, $key ) {
		return add_user_meta( $user_id, self::PUBKEY_USERMETA_KEY, $key );
	}

	/**
	 * Add or update key for user
	 *
	 * @param int $user_id
	 * @param string $key The new Key
	 * @param string $keyLike The old Key to be updated
	 * @return bool
	 */
	public function save_key( $user_id, $key, $keyLike = null ) {
		$oldKey = $this->find_key( $user_id, $keyLike );
		if ( false === $oldKey ) {
			return $this->create_key( $user_id, $key );
		}
		return update_user_meta( $user_id, self::PUBKEY_USERMETA_KEY, $key, $oldKey );
	}

	/**
	 * Delete key for user
	 *
	 * @param int $user_id
	 * @param string $keyLike The old Key to be updated
	 * @return bool
	 */
	public function delete_key( $user_id, $keyLike ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare(
			"DELETE FROM $wpdb->usermeta WHERE user_id=%d AND meta_key=%s AND meta_value LIKE %s",
			$user_id,
			self::PUBKEY_USERMETA_KEY,
			'%' . $keyLike . '%'
		) ) !== 0;
	}


}