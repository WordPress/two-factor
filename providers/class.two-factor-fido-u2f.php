<?php
/**
 * Class for creating a FIDO Universal 2nd Factor provider.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_FIDO_U2F extends Two_Factor_Provider {

	/**
	 * U2F Library
	 * @var u2flib_server\U2F
	 */
	public static $u2f;

	/**
	 * The user meta registered key.
	 * @type string
	 */
	const REGISTERED_KEY_USER_META_KEY = '_two_factor_fido_u2f_registered_key';

	/**
	 * The user meta authenticate data.
	 * @type string
	 */
	const AUTH_DATA_USER_META_KEY = '_two_factor_fido_u2f_login_request';

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
		require_once( TWO_FACTOR_DIR . 'includes/Yubico/U2F.php' );
		self::$u2f = new u2flib_server\U2F( set_url_scheme( '//' . $_SERVER['HTTP_HOST'] ) );

		require_once( TWO_FACTOR_DIR . 'providers/class.two-factor-fido-u2f-admin.php' );
		Two_Factor_FIDO_U2F_Admin::add_hooks();

		add_action( 'login_enqueue_scripts',                array( $this, 'login_enqueue_assets' ) );
		add_action( 'two-factor-user-options-' . __CLASS__, array( $this, 'user_options' ) );
		return parent::__construct();
	}

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	public function get_label() {
		return _x( 'FIDO U2F', 'Provider Label' );
	}

	/**
	 * Enqueue assets for login form.
	 *
	 * @since 0.1-dev
	 */
	public function login_enqueue_assets() {
		wp_enqueue_script( 'u2f-api', plugins_url( 'includes/Google/u2f-api.js', dirname( __FILE__ ) ) );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		require_once( ABSPATH . '/wp-admin/includes/template.php' );

		try {
			$keys = self::get_security_keys( $user->ID );
			$data = self::$u2f->getAuthenticateData( $keys );
			update_user_meta( $user->ID, self::AUTH_DATA_USER_META_KEY, $data );
		} catch ( Exception $e ) {
			?>
			<p><?php esc_html_e( 'An error occured while creating authentication data.' ); ?></p>
			<?php
			return null;
		}
		?>
		<p><?php esc_html_e( 'Now insert (and tap) your Security Key.' ); ?></p>
		<input type="hidden" name="u2f_response" id="u2f_response" />
		<script>
			var request = <?php echo wp_json_encode( $data ); ?>;
			setTimeout(function() {
				console.log("sign: ", request);

				u2f.sign(request, function(data) {
					console.log("Authenticate callback", data);

					var form = document.getElementById('loginform');
					var field = document.getElementById('u2f_response');
					field.value = JSON.stringify(data);
					form.submit();
				});
			}, 1000);
		</script>
		<?php
	}

	/**
	 * Validates the users input token.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function validate_authentication( $user ) {
		$requests = get_user_meta( $user->ID, self::AUTH_DATA_USER_META_KEY, true );

		$response = json_decode( stripslashes( $_REQUEST['u2f_response'] ) );

		$keys = self::get_security_keys( $user->ID );

		try {
			$reg = self::$u2f->doAuthenticate( $requests, $keys, $response );
			self::update_security_key( $user->ID, $reg );

			return true;
		} catch ( Exception $e ) {
			return false;
		}
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
		return self::is_browser_support() && (bool) self::get_security_keys( $user->ID );
	}

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function user_options( $user ) {
		?>
		<div>
			<?php echo esc_html( __( 'You need to register security keys such as Yubikey.' ) ); ?>
		</div>
		<?php
	}

	/**
	 * Add registered security key to a user.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id  User ID.
	 * @param object $register The data of registered security key.
	 * @return int|bool Meta ID on success, false on failure.
	 */
	public static function add_security_key( $user_id, $register ) {
		if ( ! is_numeric( $user_id ) ) {
			return false;
		}

		if (
			! is_object( $register )
				|| ! property_exists( $register, 'keyHandle' ) || empty( $register->keyHandle )
				|| ! property_exists( $register, 'publicKey' ) || empty( $register->publicKey )
				|| ! property_exists( $register, 'certificate' ) || empty( $register->certificate )
				|| ! property_exists( $register, 'counter' ) || ( -1 > $register->counter )
		) {
			return false;
		}

		$register = array(
			'keyHandle'   => $register->keyHandle,
			'publicKey'   => $register->publicKey,
			'certificate' => $register->certificate,
			'counter'     => $register->counter,
		);

		$register['name']      = __( 'New Security Key' );
		$register['added']     = current_time( 'timestamp' );
		$register['last_used'] = $register['added'];

		return add_user_meta( $user_id, self::REGISTERED_KEY_USER_META_KEY, $register );
	}

	/**
	 * Retrieve registered security keys for a user.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 * @return array|bool Array of keys on success, false on failure.
	 */
	public static function get_security_keys( $user_id ) {
		if ( ! is_numeric( $user_id ) ) {
			return false;
		}

		$keys = get_user_meta( $user_id, self::REGISTERED_KEY_USER_META_KEY );
		if ( $keys ) {
			foreach ( $keys as &$key ) {
				$key = (object) $key;
			}
			unset( $key );
		}

		return $keys;
	}

	/**
	 * Update registered security key.
	 *
	 * Use the $prev_value parameter to differentiate between meta fields with the
	 * same key and user ID.
	 *
	 * If the meta field for the user does not exist, it will be added.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id  User ID.
	 * @param object $data The data of registered security key.
	 * @return int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
	 */
	public static function update_security_key( $user_id, $data ) {
		if ( ! is_numeric( $user_id ) ) {
			return false;
		}

		if (
			! is_object( $data )
				|| ! property_exists( $data, 'keyHandle' ) || empty( $data->keyHandle )
				|| ! property_exists( $data, 'publicKey' ) || empty( $data->publicKey )
				|| ! property_exists( $data, 'certificate' ) || empty( $data->certificate )
				|| ! property_exists( $data, 'counter' ) || ( -1 > $data->counter )
		) {
			return false;
		}

		$keys = get_user_meta( $user_id, self::REGISTERED_KEY_USER_META_KEY );
		if ( $keys ) {
			foreach ( $keys as $index => $key ) {
				if ( $key['keyHandle'] === $data->keyHandle ) {
					return update_user_meta( $user_id, self::REGISTERED_KEY_USER_META_KEY, (array) $data, $key );
				}
			}
		}

		return self::add_security_key( $user_id, $data );
	}

	/**
	 * Remove registered security key matching criteria from a user.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id   User ID.
	 * @param string $keyHandle Optional. Key handle.
	 * @return bool True on success, false on failure.
	 */
	public function delete_security_key( $user_id, $keyHandle = null ) {
		global $wpdb;

		if ( ! is_numeric( $user_id ) ) {
			return false;
		}

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$table = $wpdb->usermeta;

		$keyHandle = wp_unslash( $keyHandle );
		$keyHandle = maybe_serialize( $keyHandle );

		$query = $wpdb->prepare( "SELECT umeta_id FROM $table WHERE meta_key = '%s' AND user_id = %d", self::REGISTERED_KEY_USER_META_KEY, $user_id );

		if ( $keyHandle ) {
			$query .= $wpdb->prepare( ' AND meta_value LIKE %s', '%:"' . $keyHandle . '";s:%' );
		}

		$meta_ids = $wpdb->get_col( $query );
		if ( ! count( $meta_ids ) ) {
			return false;
		}

		foreach ( $meta_ids as $meta_id ) {
			delete_metadata_by_mid( 'user', $meta_id );
		}

		return true;
	}

	/**
	 * Detect browser support for FIDO U2F.
	 *
	 * @since 0.1-dev
	 */
	public static function is_browser_support() {
		global $is_chrome;

		require_once( ABSPATH . '/wp-admin/includes/dashboard.php' );
		$response = wp_check_browser_version();

		return $is_chrome && version_compare( $response['version'], '41' ) >= 0 && ! wp_is_mobile();
	}
}
