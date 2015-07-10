<?php

class Two_Factor_Email extends Two_Factor_Provider {

	const TOKEN_META_KEY = '_two_factor_email_token';

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

	function generate_token( $user_id ) {
		$token = $this->get_code();
		update_user_meta( $user_id, self::TOKEN_META_KEY, wp_hash( $token ) );
		return $token;
	}

	function validate_token( $user_id, $token ) {
		$hashed_token = get_user_meta( $user_id, self::TOKEN_META_KEY, true );
		if ( $hashed_token !== wp_hash( $token ) ) {
			$this->delete_token( $user_id );
			return false;
		}
		return true;
	}

	function delete_token( $user_id ) {
		delete_user_meta( $user_id, self::TOKEN_META_KEY );
	}

	function generate_and_email_token( $user ) {
		$token = $this->generate_token( $user->ID );

		$subject = sprintf( esc_html__( 'Your login confirmation code for %s', 'two-factor' ), get_bloginfo( 'name' ) );
		$message = sprintf( esc_html__( 'Enter %s to log in.', 'two-factor' ), $token );
		wp_mail( $user->user_email, $subject, $message );
	}

	function authentication_page( $user ) {
		$this->generate_and_email_token( $user );
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p><?php esc_html_e( 'A verification code has been sent to the email address associated with your account.', 'two-factor' ); ?></p>
		<p>
			<label for="authcode"><?php esc_html_e( 'Verification Code:' ); ?></label>
			<input type="text" name="two-factor-email-code" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<script type="text/javascript">
			setTimeout( function(){
				var d;
				try{
					d = document.getElementById('authcode');
					d.value = '';
					d.focus();
				} catch(e){}
			}, 200);
		</script>
		<?php
		submit_button( esc_html__( 'Log In', 'two-factor' ) );
	}

	function validate_authentication( $user ) {
		return $this->validate_token( $user->ID, $_REQUEST['two-factor-email-code'] );
	}

}
