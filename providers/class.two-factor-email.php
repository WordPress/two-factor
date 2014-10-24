<?php

class Two_Factor_Email extends Two_Factor_Provider {

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	function get_label() {
		return _x( 'Email', 'Provider Label', 'two-factor' );
	}

	function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p>
			<label for="authcode"><?php esc_html_e( 'Verification Code:' ); ?></label>
			<input type="tel" name="twostep-authcode" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<?php
		submit_button( __( 'Log In', 'two-factor' ) );
	}

	function validate_authentication( $user ) {
		
	}

}
