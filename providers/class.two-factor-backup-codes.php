<?php

class Two_Factor_Backup_Codes extends Two_Factor_Provider {

	const BACKUP_CODES_META_KEY = '_two_factor_backup_codes';
	const BACKUP_CODES_DEBUG_META_KEY = '_two_factor_backup_codes_debug';

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	function get_label() {
		return _x( 'Backup Codes (single use)', 'Provider Label', 'two-factor' );
	}

	function validate_code( $user_id, $code ) {

		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		foreach( $backup_codes as $backup_code ) {
			if( wp_check_password( $code, $backup_code, $user_id ) ) {
				$this->remove_code( $user_id, $code );
				$this->remove_code_debug( $user_id, $code );
				return true;
			}
		}
		return false;
	}

	function remove_code( $user_id, $code ) {

		$hashed_code = wp_hash_password( $code );
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		// Remove the current code from the list since it's been used.
		$backup_codes = array_flip( $backup_codes );
		unset( $backup_codes[ $hashed_code ] );
		$backup_codes = array_flip( $backup_codes );

		// Update the backup code master list
		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $backup_codes );
	}

	function remove_code_debug( $user_id, $code ) {

		$backup_codes_debug = get_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, true );

		// Remove the current code from the list since it's been used.
		$backup_codes_debug = array_flip( $backup_codes_debug );
		unset( $backup_codes_debug[ $code ] );
		$backup_codes_debug = array_flip( $backup_codes_debug );

		// Update the backup code master list
		update_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, $backup_codes );
	}

	function generate_codes_debug( $user_id ) {
		$codes = array();
		$codes_debug = array();
		$codes[] = wp_hash_password( '555' );
		$codes_debug[] = '555';

		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $codes );
		update_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, $codes_debug );

		return $codes;
	}

	function generate_codes( $user_id ){

		// Auto generate new codes when we run out
		$codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );
		$codes_debug = get_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, true );

		var_dump( $codes );
		var_dump( $codes_debug );

		if( ! empty( $codes ) ) return $codes;

		// Create 10 Codes
		$codes = array();
		$code_debug = array();
		for( $i = 0; $i < 10; $i++ ) {
			$code = $this->get_code();
			$codes[] = wp_hash_password( $code );
			$codes_debug[] = $code;
			unset( $code );
		}

		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $codes );
		update_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, $codes_debug );
		return $codes;
	}

	function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		// Debug
		$codes = $this->generate_codes( $user->ID );

		$codes_debug = get_user_meta( $user->ID, self::BACKUP_CODES_DEBUG_META_KEY, true );
		?>
		<p><?php foreach( $codes_debug as $i => $code ) echo "$i.) $code</br>"; ?></p>
		<p><?php esc_html_e( 'Enter your backup code', 'two-factor' ); ?></p>
		<p>
			<label for="authcode"><?php esc_html_e( 'Backup Code:' ); ?></label>
			<input type="tel" name="two-factor-backup-code" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<?php
		submit_button( __( 'Submit', 'two-factor' ) );
	}

	function validate_authentication( $user ) {
		return $this->validate_code( $user->ID, $_REQUEST['two-factor-backup-code'] );
	}

}
