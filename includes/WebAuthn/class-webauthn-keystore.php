<?php

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
	 * @param string $keyLike
	 * @return array|bool
	 */
	public function find_key( $user_id, $keyLike ) {
		global $wpdb;

		$found = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $wpdb->usermeta WHERE user_id=%d AND meta_key=%s AND meta_value LIKE %s",
			$user_id,
			self::PUBKEY_USERMETA_KEY,
			'%' . $wpdb->esc_like( $keyLike ) . '%'
		) );
		foreach ( $found as $key ) {
			return maybe_unserialize( $key->meta_value );
		}
		return false;
	}

	/**
	 * Check whether a key exists
	 *
	 * @param string $keyLike
	 * @return bool
	 */
	public function key_exists( $keyLike ) {

		global $wpdb;

		$num_keys = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key=%s AND meta_value LIKE %s",
			self::PUBKEY_USERMETA_KEY,
			'%' . $wpdb->esc_like( serialize( $keyLike ) ) . '%'
		) );

		return intval( $num_keys ) !== 0;

	}

	/**
	 * Add key to user
	 *
	 * @param int $user_id
	 * @param string $key
	 * @return bool
	 */
	public function create_key( $user_id, $key ) {
		if ( $this->find_key( $user_id, $key->md5id ) ) {
			return false;
		}
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
	public function save_key( $user_id, $key, $keyLike ) {
		$oldKey = $this->find_key( $user_id, $keyLike );
		return update_user_meta( $user_id, self::PUBKEY_USERMETA_KEY, $key, $oldKey );
	}

	/**
	 * Delete key for user
	 *
	 * @param int $user_id
	 * @param string $keyLike The Key to be deleted
	 * @return bool
	 */
	public function delete_key( $user_id, $keyLike ) {
		global $wpdb;

		if ( $key = $this->find_key( $user_id, $keyLike ) ) {
			return delete_user_meta( $user_id, self::PUBKEY_USERMETA_KEY, $key );
		}

		return false;
	}


}
