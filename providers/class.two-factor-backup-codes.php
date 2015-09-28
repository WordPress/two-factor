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
	 * Displays an admin notice when backup codes have run out.
	 *
	 * @since 0.1-dev
	 */
	public function admin_notices() {
		$user = wp_get_current_user();

		// Return if the provider is not enabled.
		if ( ! in_array( __CLASS__, Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) ) ) {
			return;
		}

		// Return if we are not out of codes.
		if ( $this->is_available_for_user( $user ) ) {
			return;
		}
		?>
		<div class="error">
			<p>
				<span>
					<?php
					printf( // WPCS: XSS OK.
						__( 'Two-Factor: You are out of backup codes and need to <a href="%s">regenerate!</a>' ),
						esc_url( get_edit_user_link( $user->ID ) . '#two-factor-backup-codes' )
					);
					?>
				<span>
			</p>
		</div>
		<?php
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
	 * Whether this Two Factor provider is configured and codes are available for the user specified.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		// Does this user have available codes?
		if ( 0 < self::codes_remaining_for_user( $user ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function user_options( $user ) {
		$ajax_nonce = wp_create_nonce( 'two-factor-backup-codes-generate-json-' . $user->ID );
		$count = self::codes_remaining_for_user( $user );
		?>
		<p id="two-factor-backup-codes">
			<button type="button" class="button button-two-factor-backup-codes-generate button-secondary hide-if-no-js">
				<?php esc_html_e( 'Generate Verification Codes' ); ?>
			</button>
			<span class="two-factor-backup-codes-count"><?php echo esc_html( sprintf( _n( '%s unused code remaining.', '%s unused codes remaining.', $count ), $count ) ); ?></span>
		</p>
		<div class="two-factor-backup-codes-wrapper" style="display:none;">
			<ol class="two-factor-backup-codes-unused-codes"></ol>
			<p class="description"><?php esc_html_e( 'Write these down! Once you navigate away from this page, you will not be able to view these codes again.' ); ?></p>
			<button type="button" class="button secondary-button button-two-factor-backup-codes-print hide-if-no-js"><?php esc_html_e( 'Print Codes' ); ?></button>
		</div>
		<script type="text/javascript">
			( function( $ ) {
				$( '.button-two-factor-backup-codes-generate' ).click( function() {
					$.ajax( {
						method: 'POST',
						url: ajaxurl,
						data: {
							action: 'two_factor_backup_codes_generate',
							user_id: '<?php echo esc_js( $user->ID ); ?>',
							nonce: '<?php echo esc_js( $ajax_nonce ); ?>'
						},
						dataType: 'JSON',
						success: function( response ) {
							$( '.two-factor-backup-codes-wrapper' ).show();
							$( '.two-factor-backup-codes-unused-codes' ).html( '' );

							// Append the codes.
							$.each( response.data.codes, function( key, val ) {
								$( '.two-factor-backup-codes-unused-codes' ).append( '<li>' + val + '</li>' );
							} );

							// Update counter.
							$( '.two-factor-backup-codes-count' ).html( response.data.i18n );
						}
					} );
				} );

				$( '.button-two-factor-backup-codes-print' ).click( function( event ) {
					var $window = window.open( '', '', 'height=800, width=800' ),
						codes = $( '.two-factor-backup-codes-unused-codes' ).html(),
						string = '<?php esc_html_e( 'Backup codes for %s' ) ?>',
						title = string.replace( /%s/g, document.domain );

					$window.document.write( '<html><head><title>' + title + '</title><style>' );
					$window.document.write( 'body { padding: 1.5em; }' );
					$window.document.write( 'ol { list-style-type: none; padding: 0; }' );
					$window.document.write( 'li { padding-top: .25em; }' );
					$window.document.write( '</style></head><body>' );
					$window.document.write( '<div><p>' + title + '</p>' );
					$window.document.write( '<ol>' + codes + '</ol></div>' );
					$window.document.write( '</body></html>' );
					$window.document.close();
					$window.print();

					event.preventDefault();
				} );
			} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Generates backup codes & updates the user meta.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param array   $args Optional arguments for assinging new codes.
	 */
	public function generate_codes( $user, $args = '' ) {
		$codes = array();
		$codes_hashed = array();

		// Check for arguments.
		if ( isset( $args['number'] ) ) {
			$num_codes = (int) $args['number'];
		} else {
			$num_codes = self::NUMBER_OF_CODES;
		}

		// Append or replace (default).
		if ( isset( $args['method'] ) && 'append' === $args['method'] ) {
			$codes_hashed = (array) get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true );
		}

		for ( $i = 0; $i < $num_codes; $i++ ) {
			$code = $this->get_code();
			$codes_hashed[] = wp_hash_password( $code );
			$codes[] = $code;
			unset( $code );
		}

		update_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, $codes_hashed );

		// Unhashed.
		return $codes;
	}

	/**
	 * Generates a JSON object of backup codes.
	 *
	 * @since 0.1-dev
	 */
	public function ajax_generate_json() {
		$user = get_user_by( 'id', sanitize_text_field( $_POST['user_id'] ) );
		check_ajax_referer( 'two-factor-backup-codes-generate-json-' . $user->ID, 'nonce' );

		// Setup the return data.
		$codes = $this->generate_codes( $user );
		$count = self::codes_remaining_for_user( $user );
		$i18n = esc_html( sprintf( _n( '%s unused code remaining.', '%s unused codes remaining.', $count ), $count ) );

		// Send the response.
		wp_send_json_success( array( 'codes' => $codes, 'i18n' => $i18n ) );
	}

	/**
	 * Returns the number of unused codes for the specified user
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return int $int  The number of unused codes remaining
	 */
	public static function codes_remaining_for_user( $user ) {
		$backup_codes = get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true );
		if ( is_array( $backup_codes ) && ! empty( $backup_codes ) ) {
			return count( $backup_codes );
		}
		return 0;
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
		return $this->validate_code( $user, $_POST['two-factor-backup-code'] );
	}

	/**
	 * Validates a backup code.
	 *
	 * Backup Codes are single use and are deleted upon a successful validation.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param int     $code The backup code.
	 * @return boolean
	 */
	public function validate_code( $user, $code ) {
		$backup_codes = get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true );

		if ( is_array( $backup_codes ) && ! empty( $backup_codes ) ) {
			foreach ( $backup_codes as $code_index => $code_hashed ) {
				if ( wp_check_password( $code, $code_hashed, $user->ID ) ) {
					$this->delete_code( $user, $code_hashed );
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Deletes a backup code.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param string  $code_hashed The hashed the backup code.
	 */
	public function delete_code( $user, $code_hashed ) {
		$backup_codes = get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true );

		// Delete the current code from the list since it's been used.
		$backup_codes = array_flip( $backup_codes );
		unset( $backup_codes[ $code_hashed ] );
		$backup_codes = array_values( array_flip( $backup_codes ) );

		// Update the backup code master list.
		update_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, $backup_codes );
	}
}
