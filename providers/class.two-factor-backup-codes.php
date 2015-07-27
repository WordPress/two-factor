<?php

class Two_Factor_Backup_Codes extends Two_Factor_Provider {

	const DEBUG = false;

	const BACKUP_CODES_META_KEY = '_two_factor_backup_codes';
	const BACKUP_CODES_DEBUG_META_KEY = '_two_factor_backup_codes_debug';

	const NUMBER_OF_CODES = 3;

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	function __construct(){
		add_action( 'admin_notices', array( $this, 'action_admin_notices' ) );
	}

	function action_admin_notices() {
		$user_id = 1;

		// Only show this notice when Backup Codes are selected
		$primary_provider = get_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true );
		if( 'Two_Factor_Backup_Codes' != $primary_provider ) {
			return;
		}

		// Only show this notice if we are out of backup codes
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );
		if( ! empty( $backup_codes ) ) {
			return;
		}


		$link = '<a href="' . get_edit_user_link( $user_id ) . '" >regenerate</a>';
		?>
			<div class="error">
				<p><?php _e( 'Two-Factor: You are out of backup codes and need to ' . $link . '!', 'two-factor' ); ?></p>
			</div>
		<?php
	}

	function get_label() {
		return _x( 'Backup Codes (Single Use)', 'Provider Label', 'two-factor' );
	}

	function validate_code( $user_id, $code ) {
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		foreach( $backup_codes as $code_index => $backup_code ) {
			if( wp_check_password( $code, $backup_code, $user_id ) ) {
				// Backup Codes are single use and are removed upon a successful validation
				$this->remove_code( $user_id, $code_index );
				$this->remove_code_debug( $user_id, $code ); //@todo remove
				return true;
			}
		}
		return false;
	}

	private function remove_code( $user_id, $code_index ) {
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		// Remove the current code from the list since it's been used.
		unset( $backup_codes[ $code_index ] );
		$backup_codes = array_values( $backup_codes );

		// Update the backup code master list
		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $backup_codes );
	}

	// @todo remove for production
	private function remove_code_debug( $user_id, $code ) {
		$backup_codes_debug = get_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, true );

		// Remove the current code from the list since it's been used.
		$backup_codes_debug = array_flip( $backup_codes_debug );
		unset( $backup_codes_debug[ $code ] );
		$backup_codes_debug = array_flip( $backup_codes_debug );
		$backup_codes_debug = array_values( $backup_codes_debug );

		// Update the backup code master list
		update_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, $backup_codes_debug );
	}

	// @todo remove for production
	function generate_codes_debug( $user_id ) {
		$codes = array();
		$codes_debug = array();
		$codes[] = wp_hash_password( '555' );
		$codes_debug[] = '555';

		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $codes );
		update_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, $codes_debug );

		return $codes;
	}

	// @todo remove for production
	function display_codes_debug( $user ) {
		$codes_hashed = get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true );
		if( empty( $codes_hashed ) ) {
			$codes = $this->generate_codes( $user->ID );
		} else {
			$codes = get_user_meta( $user->ID, self::BACKUP_CODES_DEBUG_META_KEY, true );
		}
		foreach( $codes as $i => $code ) echo "$i.) $code</br>";
	}

	function generate_codes( $user_id ) {
		// Create 10 Codes
		$codes = array();
		$codes_hashed = array();
		for( $i = 0; $i < self::NUMBER_OF_CODES; $i++ ) {
			$code = $this->get_code();
			$codes_hashed[] = wp_hash_password( $code );
			$codes[] = $code;
			unset( $code );
		}

		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $codes_hashed );
		update_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, $codes );
		return $codes; //unhashed
	}

	function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p><?php $this->display_codes_debug( $user ); ?></p><br/>
		<p><?php esc_html_e( 'Enter a backup code.', 'two-factor' ); //@todo remove ?></p><br/>
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
