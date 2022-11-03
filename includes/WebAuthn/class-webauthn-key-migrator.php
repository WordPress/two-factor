<?php

/**
 *	Adapted from https://github.com/davidearl/webauthn
 */

class WebAuthnKeyMigrator {

	/**
	 *	@param int $user_id
	 *	@param string $u2f_key_handle
	 *	@return int|false
	 */
	public static function migrate_key_for_user( $user_id, $u2f_key_handle ) {

		$u2f_key = self::get_u2k_key_by_handle( $user_id, $u2f_key_handle );

		$id_str = base64_decode( strtr( $u2f_key['keyHandle'], '-_', '+/' ) ); // byte string

		$webauthn_key = (object) array(
			'key'       => self::u2fKeyToCOSE( $u2f_key['publicKey'] ),
			'id'		=> array_map( 'ord', str_split( $id_str ) ),
			'label'     => $u2f_key['name'],
			'md5id'     => md5( $id_str ),
			'created'   => $u2f_key['added'],
			'last_used' => $u2f_key['last_used'],
			'tested'    => false,
			'app_id'    => Two_Factor_FIDO_U2F::get_u2f_app_id(), // legacy IDs have trailing https://
		);

		$keystore = WebAuthnKeyStore::instance();

		return $keystore->create_key( $user_id, $webauthn_key );
	}

	/**
	 *	@param int $user_id
	 *	@param string $key_handle
	 *	@return array
	 */
	private static function get_u2k_key_by_handle( $user_id, $key_handle ) {

		global $wpdb;

		$key_handle = wp_unslash( $key_handle );
		$key_handle = maybe_serialize( $key_handle );

		$query = $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id = %d", Two_Factor_FIDO_U2F::REGISTERED_KEY_USER_META_KEY, $user_id );

		$key_handle_lookup = sprintf( ':"%s";s:', $key_handle );

		$query .= $wpdb->prepare(
			' AND meta_value LIKE %s',
			'%' . $wpdb->esc_like( $key_handle_lookup ) . '%'
		);

		$meta_id = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $meta_id ) {
			return false;
		}
		$meta = get_metadata_by_mid( 'user', $meta_id );
		if ( false !== $meta ) {
			return $meta->meta_value;
		}
		return false;
	}

	/**
	 *	@param string $key base64 encoded pubkey
	 *	@return string|null COSE Key
	 */
	private static function u2fKeyToCOSE( $key ) {

		$binary = base64_decode(strtr($key, '-_', '+/'), true);
		$x      = substr( $binary, 1, 32 );
		$y      = substr( $binary, 33, 32 );

		$der = self::sequence(
		self::sequence(
			self::oid( "\x2A\x86\x48\xCE\x3D\x02\x01" ) . // OID 1.2.840.10045.2.1 ecPublicKey
			self::oid( "\x2A\x86\x48\xCE\x3D\x03\x01\x07" )
			) .
			self::bitString( base64_decode(strtr($key, '-_', '+/'), true) )
		);

		return '-----BEGIN PUBLIC KEY-----' . "\n"
			. chunk_split(base64_encode($der), 64, "\n")
			. '-----END PUBLIC KEY-----' . "\n";
	}

	/**
	 *	Adapted from https://github.com/madwizard-org/webauthn-server/blob/master/src/Crypto/Der.php
	 */
	private static function length(int $len): string {
		if ($len < 128) {
			return \chr($len);
		}

		$lenBytes = '';
		while ($len > 0) {
			$lenBytes = \chr($len % 256) . $lenBytes;
			$len = \intdiv($len, 256);
		}
		return \chr(0x80 | \strlen($lenBytes)) . $lenBytes;
	}

	public static function sequence(string $contents): string {
		return "\x30" . self::length(\strlen($contents)) . $contents;
	}

	public static function oid(string $encoded): string {
		return "\x06" . self::length(\strlen($encoded)) . $encoded;
	}


	public static function bitString(string $bytes): string {
		$len = \strlen($bytes) + 1;

		return "\x03" . self::length($len) . "\x00" . $bytes;
	}
}
