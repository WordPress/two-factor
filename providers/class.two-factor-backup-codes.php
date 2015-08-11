<?php
/**
 * Class for creating a backup codes provider.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_Backup_Codes extends Two_Factor_Provider {

	/**
	 * The user meta backup codes key.
	 * @type string
	 */
	const BACKUP_CODES_META_KEY = '_two_factor_backup_codes';

	/**
	 * The number backup codes.
	 * @type int
	 */
	const NUMBER_OF_CODES = 10;

	/**
	 * Ensures only one instance of this class exists in memory at any one time.
	 *
	 * @since 0.1-dev
	 */
	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	/**
	 * Class constructor.
	 *
	 * @since 0.1-dev
	 */
	protected function __construct() {
		add_action( 'two-factor-user-options-' . __CLASS__, array( $this, 'user_options' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'wp_ajax_two_factor_backup_codes_generate', array( $this, 'ajax_generate_json' ) );

		return parent::__construct();
	}

	/**
	 * Displays an admin notice when backup codes have ran out.
	 *
	 * @since 0.1-dev
	 */
	public static function admin_notices() {
		// Only show this notice if we are out of backup codes.
		$user_id = get_current_user_id();
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );
		if ( ! empty( $backup_codes ) ) {
			return;
		}

		// Only show when the provider is enabled.
		if ( false === Two_Factor_Backup_Codes::get_instance()->is_enabled( $user_id ) ) {
			return;
		}
		?>
		<div class="error">
			<p><?php echo wp_kses( sprintf( __( 'Two-Factor: You are out of backup codes and need to <a href="%1$s#two-factor-backup-codes" >regenerate</a>!' ), esc_url( get_edit_user_link( $user_id ) ) ), array( 'a' => array( 'href' => array() ) ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Verify the provider is enabled.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id The logged-in user ID.
	 */
	public function is_enabled( $user_id ) {
		$enabled_providers = (array) get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
		if ( in_array( __CLASS__, $enabled_providers ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	public function get_label() {
		return _x( 'Backup Verification Codes (Single Use)', 'Provider Label' );
	}

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		return true;
	}

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function user_options( $user ) {
		$user_id = $user->ID;
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );
		$ajax_nonce = wp_create_nonce( 'two-factor-backup-codes-generate-json' );
		?>
		<p id="two-factor-backup-codes">
			<button type="button" class="button button-two-factor-backup-codes-generate button-secondary hide-if-no-js">
				<?php esc_html_e( 'Generate Verification Codes' ); ?>
			</button>
			<?php echo wp_kses( sprintf( _n( '%s unused code remaining.', '%s unused codes remaining.', count( $backup_codes ) ), '<span class="two-factor-backup-codes-count">' . count( $backup_codes ) . '</span>' ), array( 'span' => array( 'class' => array() ) ) ); ?>
		</p>
		<div class="two-factor-backup-codes-wrapper" style="display:none;">
			<ol class="two-factor-backup-codes-unused-codes"></ol>
			<p class="description"><?php esc_html_e( "Write 'em down y'all!" ); ?></p>
		</div>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$('.button-two-factor-backup-codes-generate').click( function() {
					jQuery.ajax({
						url: ajaxurl,
						data:{
							action: 'two_factor_backup_codes_generate',
							nonce: '<?php echo esc_js( $ajax_nonce ); ?>'
						},
						dataType: 'JSON',
						success:function(data){
							$('.two-factor-backup-codes-wrapper').show();
							$('.two-factor-backup-codes-unused-codes').html('');
							$.each( data, function( key, val ) {
								$('.two-factor-backup-codes-unused-codes').append('<li>'+val+'</li>');
							});
							// Update counter.
							$('.two-factor-backup-codes-count').html( data.length );
						},
						error: function(errorThrown){
							alert('error'); // @todo: remove for production
							console.log(errorThrown);
						}
					});
				});
			});
		</script>
		<?php
	}

	/**
	 * Generates backup codes & updates the user meta.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id The logged-in user ID.
	 */
	public function generate_codes( $user_id ) {
		$codes = array();
		$codes_hashed = array();

		for ( $i = 0; $i < self::NUMBER_OF_CODES; $i++ ) {
			$code = $this->get_code();
			$codes_hashed[] = wp_hash_password( $code );
			$codes[] = $code;
			unset( $code );
		}

		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $codes_hashed );

		// Unhashed.
		return $codes;
	}

	/**
	 * Generates a JSON object of backup codes.
	 *
	 * @since 0.1-dev
	 */
	public function ajax_generate_json() {
		check_ajax_referer( 'two-factor-backup-codes-generate-json', 'nonce' );

		$user_id = get_current_user_id();
		$codes = $this->generate_codes( $user_id );
		$json_codes = wp_json_encode( $codes );

		echo $json_codes;
		die( 0 );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p><?php esc_html_e( 'Enter a backup verification code.' ); ?></p><br/>
		<p>
			<label for="authcode"><?php esc_html_e( 'Verification Code:' ); ?></label>
			<input type="tel" name="two-factor-backup-code" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<?php
		submit_button( __( 'Submit' ) );
	}

	/**
	 * Validates the users input token.
	 *
	 * In this class we just return true.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function validate_authentication( $user ) {
		return $this->validate_code( $user->ID, $_REQUEST['two-factor-backup-code'] );
	}

	/**
	 * Validates a backup code.
	 *
	 * Backup Codes are single use and are deleted upon a successful validation.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id The logged-in user ID.
	 * @param int $code    The backup code.
	 * @return boolean
	 */
	public function validate_code( $user_id, $code ) {
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		foreach ( $backup_codes as $code_index => $backup_code ) {
			if ( wp_check_password( $code, $backup_code, $user_id ) ) {
				$this->delete_code( $user_id, $code_index );
				return true;
			}
		}
		return false;
	}

	/**
	 * Deletes a backup code.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id    The logged-in user ID.
	 * @param int $code_index The array index of the backup code.
	 */
	public function delete_code( $user_id, $code_index ) {
		$backup_codes = get_user_meta( $user_id, self::BACKUP_CODES_META_KEY, true );

		// Delete the current code from the list since it's been used.
		unset( $backup_codes[ $code_index ] );
		$backup_codes = array_values( $backup_codes );

		// Update the backup code master list.
		update_user_meta( $user_id, self::BACKUP_CODES_META_KEY, $backup_codes );
	}
}
