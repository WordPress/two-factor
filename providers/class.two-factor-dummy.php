<?php

class Two_Factor_Dummy extends Two_Factor_Provider {

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	function get_label() {
		return _x( 'Dummy Method', 'Provider Label', 'two-factor' );
	}

	function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p><?php esc_html_e( 'Are you really you?', 'two-factor' ); ?></p>
		<?php
		submit_button( __( 'Yup.', 'two-factor' ) );
	}

	function validate_authentication( $user ) {
		return true;
	}

}
