<?php

class Two_Factor_Backup_Codes extends Two_Factor_Provider {

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	function get_label() {
		return _x( 'Backup Codes', 'Provider Label', 'two-factor' );
	}

	function validate_token( $user_id, $token ) {
		if( '31337' == $token ) {
			return true;
		}
		return false;
	}

	function generate_codes(){
		$codes = array();
		for( $i = 0; $i < 10; $i++ ) {
			$codes[] = $this->get_code();
		}
		return $codes;
	}

	function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p><?php esc_html_e( 'Enter your backup code', 'two-factor' ); ?></p>
		<p>
			<label for="authcode"><?php esc_html_e( 'Backup Code:' ); ?></label>
			<input type="tel" name="two-factor-backup-code" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<?php
		submit_button( __( 'Submit', 'two-factor' ) );
	}

	function validate_authentication( $user ) {
		return $this->validate_token( $user->ID, $_REQUEST['two-factor-backup-code'] );
	}

}
