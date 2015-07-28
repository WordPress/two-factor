<?php

class Two_Factor_Backup_Codes extends Two_Factor_Provider {

	const DEBUG = true;
	const BACKUP_CODES_META_KEY = '_two_factor_backup_codes';
	const BACKUP_CODES_DEBUG_META_KEY = '_two_factor_backup_codes_debug';
	const NUMBER_OF_CODES = 10;

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	public static function add_hooks() {
		$user_id = get_current_user_id();
		$primary_provider = get_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true );

		// Only do these things when Backup Codes are selected as the primary provider.
		if( 'Two_Factor_Backup_Codes' != $primary_provider ) {
			return;
		}

		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'user_two_factor_options', array( __CLASS__, 'user_two_factor_options' ) );

		add_action( 'wp_ajax_two_factor_backup_codes_generate', array( __CLASS__, 'ajax_two_factor_backup_codes_generate' ) );
	}

	// @todo add nonce
	public static function ajax_two_factor_backup_codes_generate() {
		check_ajax_referer( 'two-factor-backup-codes-generate', 'nonce' );

		$user_id = get_current_user_id();
		$codes = self::get_instance()->generate_codes( $user_id );
		$json_codes = json_encode( $codes );
		echo $json_codes;
		die(0);
	}

	public static function admin_notices() {
		$user_id = get_current_user_id();

		// Only show this notice if we are out of backup codes
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );
		if( ! empty( $backup_codes ) ) {
			return;
		}
		?>
			<div class="error">
				<p><?php _e( 'Two-Factor: You are out of backup codes and need to <a href="' . get_edit_user_link( $user_id ) . '#two-factor-backup-codes" >regenerate</a>! (debug enabled: codes generated at login)', 'two-factor' ); ?></p>
			</div>
		<?php
	}

	public static function user_two_factor_options() {
		$user_id = get_current_user_id();

		// Only show this notice if we are out of backup codes
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );
		$ajax_nonce = wp_create_nonce( 'two-factor-backup-codes-generate' );
		?>
		<table id="two-factor-backup-codes" class="form-table two-factor-backup-codes-table">
			<tbody>
				<tr>
					<th><label><?php esc_html_e( 'Two-Factor Backup Codes', 'two-factor' ); ?></label></th>
					<td>
						<p>Two-Factor Backup Verification Codes are single use codes that can be used to login.</p>
						<p></p>
						<button type="button" class="button button-two-factor-backup-codes-generate button-secondary hide-if-no-js">Generate Verification Codes</button>
						<p class="description"><span class="two-factor-backup-codes-count"><?php echo count( $backup_codes ); ?></span> unused codes remaining.</p>
						<div class="two-factor-backup-codes-wrapper" style="display:none;">
							<ol class="two-factor-backup-codes-unused-codes"></ol>
							<p class="description">Write 'em down y'all!</p>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$('.button-two-factor-backup-codes-generate').click( function() {
					jQuery.ajax({
						url: ajaxurl,
						data:{
							action:'two_factor_backup_codes_generate',
							nonce: '<?php echo $ajax_nonce; ?>',
						},
						dataType: 'JSON',
						success:function(data){
							$('.two-factor-backup-codes-wrapper').show();
							$('.two-factor-backup-codes-unused-codes').html('');
							$.each( data, function( key, val ) {
								$('.two-factor-backup-codes-unused-codes').append('<li>'+val+'</li>');
							});
							// Update counter
							$('.two-factor-backup-codes-count').html( data.length );
						},
						error: function(errorThrown){
							alert('error');
							console.log(errorThrown);
						}
					});
				});
			});
		</script>
		<?php
	}

	function get_label() {
		return _x( 'Backup Verification Codes (Single Use)', 'Provider Label', 'two-factor' );
	}

	function validate_code( $user_id, $code ) {
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		foreach( $backup_codes as $code_index => $backup_code ) {
			if( wp_check_password( $code, $backup_code, $user_id ) ) {
				// Backup Codes are single use and are deleted upon a successful validation
				$this->delete_code( $user_id, $code_index );
				$this->delete_code_debug( $user_id, $code ); //@todo delete
				return true;
			}
		}
		return false;
	}

	private function delete_code( $user_id, $code_index ) {
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		// delete the current code from the list since it's been used.
		unset( $backup_codes[ $code_index ] );
		$backup_codes = array_values( $backup_codes );

		// Update the backup code master list
		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $backup_codes );
	}

	// @todo delete for production
	private function delete_code_debug( $user_id, $code ) {
		$backup_codes_debug = get_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, true );

		// delete the current code from the list since it's been used.
		$backup_codes_debug = array_flip( $backup_codes_debug );
		unset( $backup_codes_debug[ $code ] );
		$backup_codes_debug = array_flip( $backup_codes_debug );
		$backup_codes_debug = array_values( $backup_codes_debug );

		// Update the backup code master list
		update_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, $backup_codes_debug );
	}

	// @todo delete for production
	function generate_codes_debug( $user_id ) {
		$codes = array();
		$codes_debug = array();
		$codes[] = wp_hash_password( '31337' );
		$codes_debug[] = '31337';

		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $codes );
		update_user_meta( $user_id, self::BACKUP_CODES_DEBUG_META_KEY, $codes_debug );

		return $codes;
	}

	// @todo delete for production
	function display_codes_debug( $user ) {

		echo '<p>Debug: Cheat Sheet</p>';

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
		<p><?php if( self::DEBUG ) $this->display_codes_debug( $user ); //@todo delete ?></p><br/>
		<p><?php esc_html_e( 'Enter a backup verification code.', 'two-factor' ); ?></p><br/>
		<p>
			<label for="authcode"><?php esc_html_e( 'Verification Code:' ); ?></label>
			<input type="tel" name="two-factor-backup-code" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<?php
		submit_button( __( 'Submit', 'two-factor' ) );
	}

	function validate_authentication( $user ) {
		return $this->validate_code( $user->ID, $_REQUEST['two-factor-backup-code'] );
	}

}
Two_Factor_Backup_Codes::add_hooks();
