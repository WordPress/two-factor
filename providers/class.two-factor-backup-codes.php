<?php

class Two_Factor_Backup_Codes extends Two_Factor_Provider {

	const BACKUP_CODES_META_KEY = '_two_factor_backup_codes';
	const NUMBER_OF_CODES = 10;

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	protected function __construct() {
		add_action( 'two-factor-user-options-' . __CLASS__, array( $this, 'user_options' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'wp_ajax_two_factor_backup_codes_generate', array( $this, 'ajax_generate_json' ) );

		return parent::__construct();
	}

	public static function admin_notices() {
		// Only show this notice if we are out of backup codes
		$user_id = get_current_user_id();
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );
		if( ! empty( $backup_codes ) ) {
			return;
		}
		?>
			<div class="error">
				<p><?php _e( 'Two-Factor: You are out of backup codes and need to <a href="' . get_edit_user_link( $user_id ) . '#two-factor-backup-codes" >regenerate</a>!', 'two-factor' ); ?></p>
			</div>
		<?php
	}

	function get_label() {
		return _x( 'Backup Verification Codes (Single Use)', 'Provider Label', 'two-factor' );
	}

	function is_available_for_user( $user ) {
		return true;
	}

	function user_options( $user ) {
		$user_id = get_current_user_id();
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );
		$ajax_nonce = wp_create_nonce( 'two-factor-backup-codes-generate-json' );
		?>
		<p>
			<button type="button" class="button button-two-factor-backup-codes-generate button-secondary hide-if-no-js">Generate Verification Codes</button>
			<kbd><span class="two-factor-backup-codes-count"><?php echo count( $backup_codes ); ?></span> unused codes remaining.</kbd>
		</p>
		<div class="two-factor-backup-codes-wrapper" style="display:none;">
			<ol class="two-factor-backup-codes-unused-codes"></ol>
			<p class="description">Write 'em down y'all!</p>
		</div>
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

	function generate_codes( $user_id ) {
		$codes = array();
		$codes_hashed = array();
		for( $i = 0; $i < self::NUMBER_OF_CODES; $i++ ) {
			$code = $this->get_code();
			$codes_hashed[] = wp_hash_password( $code );
			$codes[] = $code;
			unset( $code );
		}
		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $codes_hashed );
		return $codes; //unhashed
	}

	function ajax_generate_json() {
		check_ajax_referer( 'two-factor-backup-codes-generate-json', 'nonce' );
		$user_id = get_current_user_id();
		$codes = $this->generate_codes( $user_id );
		$json_codes = json_encode( $codes );
		echo $json_codes;
		die(0);
	}

	function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p><?php esc_html_e( 'Enter a backup verification code.', 'two-factor' ); ?></p><br/>
		<p>
			<label for="authcode"><?php esc_html_e( 'Verification Code:', 'two-factor' ); ?></label>
			<input type="tel" name="two-factor-backup-code" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<?php
		submit_button( __( 'Submit', 'two-factor' ) );
	}

	function validate_authentication( $user ) {
		return $this->validate_code( $user->ID, $_REQUEST['two-factor-backup-code'] );
	}

	function validate_code( $user_id, $code ) {
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		foreach( $backup_codes as $code_index => $backup_code ) {
			if( wp_check_password( $code, $backup_code, $user_id ) ) {
				// Backup Codes are single use and are deleted upon a successful validation
				$this->delete_code( $user_id, $code_index );
				return true;
			}
		}
		return false;
	}

	function delete_code( $user_id, $code_index ) {
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		// delete the current code from the list since it's been used.
		unset( $backup_codes[ $code_index ] );
		$backup_codes = array_values( $backup_codes );

		// Update the backup code master list
		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $backup_codes );
	}
}
