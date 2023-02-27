<?php
/**
 * Two Factore Core Class.
 *
 * @package Two_Factor
 */

/**
 * Class for creating two factor authorization.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_Core {

	/**
	 * The user meta provider key.
	 *
	 * @type string
	 */
	const PROVIDER_USER_META_KEY = '_two_factor_provider';

	/**
	 * The user meta enabled providers key.
	 *
	 * @type string
	 */
	const ENABLED_PROVIDERS_USER_META_KEY = '_two_factor_enabled_providers';

	/**
	 * The user meta nonce key.
	 *
	 * @type string
	 */
	const USER_META_NONCE_KEY = '_two_factor_nonce';

	/**
	 * The user meta key to store the last failed timestamp.
	 *
	 * @type string
	 */
	const USER_RATE_LIMIT_KEY = '_two_factor_last_login_failure';

	/**
	 * The user meta key to store the number of failed login attempts.
	 *
	 * @var string
	 */
	const USER_FAILED_LOGIN_ATTEMPTS_KEY = '_two_factor_failed_login_attempts';

	/**
	 * The user meta key to store whether or not the password was reset.
	 *
	 * @var string
	 */
	const USER_PASSWORD_WAS_RESET_KEY = '_two_factor_password_was_reset';

	/**
	 * URL query paramater used for our custom actions.
	 *
	 * @var string
	 */
	const USER_SETTINGS_ACTION_QUERY_VAR = 'two_factor_action';

	/**
	 * Nonce key for user settings.
	 *
	 * @var string
	 */
	const USER_SETTINGS_ACTION_NONCE_QUERY_ARG = '_two_factor_action_nonce';

	/**
	 * Namespace for plugin rest api endpoints.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'two-factor/1.0';

	/**
	 * Keep track of all the password-based authentication sessions that
	 * need to invalidated before the second factor authentication.
	 *
	 * @var array
	 */
	private static $password_auth_tokens = array();

	/**
	 * Set up filters and actions.
	 *
	 * @param object $compat A compatibility layer for plugins.
	 *
	 * @since 0.1-dev
	 */
	public static function add_hooks( $compat ) {
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'get_providers' ) );
		add_action( 'wp_login', array( __CLASS__, 'wp_login' ), 10, 2 );
		add_filter( 'wp_login_errors', array( __CLASS__, 'maybe_show_reset_password_notice' ) );
		add_action( 'after_password_reset', array( __CLASS__, 'clear_password_reset_notice' ) );
		add_action( 'login_form_validate_2fa', array( __CLASS__, 'login_form_validate_2fa' ) );
		add_action( 'login_form_backup_2fa', array( __CLASS__, 'backup_2fa' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'user_two_factor_options' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_two_factor_options' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'user_two_factor_options_update' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'user_two_factor_options_update' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'filter_manage_users_columns' ) );
		add_filter( 'wpmu_users_columns', array( __CLASS__, 'filter_manage_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'manage_users_custom_column' ), 10, 3 );

		/**
		 * Keep track of all the user sessions for which we need to invalidate the
		 * authentication cookies set during the initial password check.
		 *
		 * Is there a better way of doing this?
		 */
		add_action( 'set_auth_cookie', array( __CLASS__, 'collect_auth_cookie_tokens' ) );
		add_action( 'set_logged_in_cookie', array( __CLASS__, 'collect_auth_cookie_tokens' ) );

		// Run only after the core wp_authenticate_username_password() check.
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), 50 );

		// Run as late as possible to prevent other plugins from unintentionally bypassing.
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate_block_cookies' ), PHP_INT_MAX );

		add_action( 'admin_init', array( __CLASS__, 'trigger_user_settings_action' ) );
		add_filter( 'two_factor_providers', array( __CLASS__, 'enable_dummy_method_for_debug' ) );

		$compat->init();
	}

	/**
	 * Loads the plugin's text domain.
	 *
	 * Sites on WordPress 4.6+ benefit from just-in-time loading of translations.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'two-factor' );
	}

	/**
	 * For each provider, include it and then instantiate it.
	 *
	 * @since 0.1-dev
	 *
	 * @return array
	 */
	public static function get_providers() {
		$providers = array(
			'Two_Factor_Email'        => TWO_FACTOR_DIR . 'providers/class-two-factor-email.php',
			'Two_Factor_Totp'         => TWO_FACTOR_DIR . 'providers/class-two-factor-totp.php',
			'Two_Factor_FIDO_U2F'     => TWO_FACTOR_DIR . 'providers/class-two-factor-fido-u2f.php',
			'Two_Factor_Backup_Codes' => TWO_FACTOR_DIR . 'providers/class-two-factor-backup-codes.php',
			'Two_Factor_Dummy'        => TWO_FACTOR_DIR . 'providers/class-two-factor-dummy.php',
		);

		/**
		 * Filter the supplied providers.
		 *
		 * This lets third-parties either remove providers (such as Email), or
		 * add their own providers (such as text message or Clef).
		 *
		 * @param array $providers A key-value array where the key is the class name, and
		 *                         the value is the path to the file containing the class.
		 */
		$providers = apply_filters( 'two_factor_providers', $providers );

		// FIDO U2F is PHP 5.3+ only.
		if ( isset( $providers['Two_Factor_FIDO_U2F'] ) && version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
			unset( $providers['Two_Factor_FIDO_U2F'] );
			trigger_error( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				sprintf(
				/* translators: %s: version number */
					__( 'FIDO U2F is not available because you are using PHP %s. (Requires 5.3 or greater)', 'two-factor' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					PHP_VERSION
				)
			);
		}

		/**
		 * For each filtered provider,
		 */
		foreach ( $providers as $class => $path ) {
			include_once $path;

			/**
			 * Confirm that it's been successfully included before instantiating.
			 */
			if ( class_exists( $class ) ) {
				try {
					$providers[ $class ] = call_user_func( array( $class, 'get_instance' ) );
				} catch ( Exception $e ) {
					unset( $providers[ $class ] );
				}
			}
		}

		return $providers;
	}

	/**
	 * Enable the dummy method only during debugging.
	 *
	 * @param array $methods List of enabled methods.
	 *
	 * @return array
	 */
	public static function enable_dummy_method_for_debug( $methods ) {
		if ( ! self::is_wp_debug() ) {
			unset( $methods['Two_Factor_Dummy'] );
		}

		return $methods;
	}

	/**
	 * Check if the debug mode is enabled.
	 *
	 * @return boolean
	 */
	protected static function is_wp_debug() {
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Get the user settings page URL.
	 *
	 * Fetch this from the plugin core after we introduce proper dependency injection
	 * and get away from the singletons at the provider level (should be handled by core).
	 *
	 * @param integer $user_id User ID.
	 *
	 * @return string
	 */
	protected static function get_user_settings_page_url( $user_id ) {
		$page = 'user-edit.php';

		if ( defined( 'IS_PROFILE_PAGE' ) && IS_PROFILE_PAGE ) {
			$page = 'profile.php';
		}

		return add_query_arg(
			array(
				'user_id' => intval( $user_id ),
			),
			self_admin_url( $page )
		);
	}

	/**
	 * Get the URL for resetting the secret token.
	 *
	 * @param integer $user_id User ID.
	 * @param string  $action Custom two factor action key.
	 *
	 * @return string
	 */
	public static function get_user_update_action_url( $user_id, $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					self::USER_SETTINGS_ACTION_QUERY_VAR => $action,
				),
				self::get_user_settings_page_url( $user_id )
			),
			sprintf( '%d-%s', $user_id, $action ),
			self::USER_SETTINGS_ACTION_NONCE_QUERY_ARG
		);
	}

	/**
	 * Check if a user action is valid.
	 *
	 * @param integer $user_id User ID.
	 * @param string  $action User action ID.
	 *
	 * @return boolean
	 */
	public static function is_valid_user_action( $user_id, $action ) {
		$request_nonce = filter_input( INPUT_GET, self::USER_SETTINGS_ACTION_NONCE_QUERY_ARG, FILTER_CALLBACK, array( 'options' => 'sanitize_key' ) );

		return wp_verify_nonce(
			$request_nonce,
			sprintf( '%d-%s', $user_id, $action )
		);
	}

	/**
	 * Get the ID of the user being edited.
	 *
	 * @return integer
	 */
	public static function current_user_being_edited() {
		// Try to resolve the user ID from the request first.
		if ( ! empty( $_REQUEST['user_id'] ) ) {
			$user_id = intval( $_REQUEST['user_id'] );

			if ( current_user_can( 'edit_user', $user_id ) ) {
				return $user_id;
			}
		}

		return get_current_user_id();
	}

	/**
	 * Trigger our custom update action if a valid
	 * action request is detected and passes the nonce check.
	 *
	 * @return void
	 */
	public static function trigger_user_settings_action() {
		$action  = filter_input( INPUT_GET, self::USER_SETTINGS_ACTION_QUERY_VAR, FILTER_CALLBACK, array( 'options' => 'sanitize_key' ) );
		$user_id = self::current_user_being_edited();

		if ( ! empty( $action ) && self::is_valid_user_action( $user_id, $action ) ) {
			/**
			 * This action is triggered when a valid Two Factor settings
			 * action is detected and it passes the nonce validation.
			 *
			 * @param integer $user_id User ID.
			 * @param string $action Settings action.
			 */
			do_action( 'two_factor_user_settings_action', $user_id, $action );
		}
	}

	/**
	 * Keep track of all the authentication cookies that need to be
	 * invalidated before the second factor authentication.
	 *
	 * @param string $cookie Cookie string.
	 *
	 * @return void
	 */
	public static function collect_auth_cookie_tokens( $cookie ) {
		$parsed = wp_parse_auth_cookie( $cookie );

		if ( ! empty( $parsed['token'] ) ) {
			self::$password_auth_tokens[] = $parsed['token'];
		}
	}

	/**
	 * Get all Two-Factor Auth providers that are enabled for the specified|current user.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return array
	 */
	public static function get_enabled_providers_for_user( $user = null ) {
		if ( empty( $user ) || ! is_a( $user, 'WP_User' ) ) {
			$user = wp_get_current_user();
		}

		$providers         = self::get_providers();
		$enabled_providers = get_user_meta( $user->ID, self::ENABLED_PROVIDERS_USER_META_KEY, true );
		if ( empty( $enabled_providers ) ) {
			$enabled_providers = array();
		}
		$enabled_providers = array_intersect( $enabled_providers, array_keys( $providers ) );

		/**
		 * Filter the enabled two-factor authentication providers for this user.
		 *
		 * @param array  $enabled_providers The enabled providers.
		 * @param int    $user_id           The user ID.
		 */
		return apply_filters( 'two_factor_enabled_providers_for_user', $enabled_providers, $user->ID );
	}

	/**
	 * Get all Two-Factor Auth providers that are both enabled and configured for the specified|current user.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return array
	 */
	public static function get_available_providers_for_user( $user = null ) {
		if ( empty( $user ) || ! is_a( $user, 'WP_User' ) ) {
			$user = wp_get_current_user();
		}

		$providers            = self::get_providers();
		$enabled_providers    = self::get_enabled_providers_for_user( $user );
		$configured_providers = array();

		foreach ( $providers as $classname => $provider ) {
			if ( in_array( $classname, $enabled_providers, true ) && $provider->is_available_for_user( $user ) ) {
				$configured_providers[ $classname ] = $provider;
			}
		}

		return $configured_providers;
	}

	/**
	 * Gets the Two-Factor Auth provider for the specified|current user.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id Optional. User ID. Default is 'null'.
	 * @return object|null
	 */
	public static function get_primary_provider_for_user( $user_id = null ) {
		if ( empty( $user_id ) || ! is_numeric( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$providers           = self::get_providers();
		$available_providers = self::get_available_providers_for_user( get_userdata( $user_id ) );

		// If there's only one available provider, force that to be the primary.
		if ( empty( $available_providers ) ) {
			return null;
		} elseif ( 1 === count( $available_providers ) ) {
			$provider = key( $available_providers );
		} else {
			$provider = get_user_meta( $user_id, self::PROVIDER_USER_META_KEY, true );

			// If the provider specified isn't enabled, just grab the first one that is.
			if ( ! isset( $available_providers[ $provider ] ) ) {
				$provider = key( $available_providers );
			}
		}

		/**
		 * Filter the two-factor authentication provider used for this user.
		 *
		 * @param string $provider The provider currently being used.
		 * @param int    $user_id  The user ID.
		 */
		$provider = apply_filters( 'two_factor_primary_provider_for_user', $provider, $user_id );

		if ( isset( $providers[ $provider ] ) ) {
			return $providers[ $provider ];
		}

		return null;
	}

	/**
	 * Quick boolean check for whether a given user is using two-step.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id Optional. User ID. Default is 'null'.
	 * @return bool
	 */
	public static function is_user_using_two_factor( $user_id = null ) {
		$provider = self::get_primary_provider_for_user( $user_id );
		return ! empty( $provider );
	}

	/**
	 * Handle the browser-based login.
	 *
	 * @since 0.1-dev
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function wp_login( $user_login, $user ) {
		if ( ! self::is_user_using_two_factor( $user->ID ) ) {
			return;
		}

		// Invalidate the current login session to prevent from being re-used.
		self::destroy_current_session_for_user( $user );

		// Also clear the cookies which are no longer valid.
		wp_clear_auth_cookie();

		self::show_two_factor_login( $user );
		exit;
	}

	/**
	 * Destroy the known password-based authentication sessions for the current user.
	 *
	 * Is there a better way of finding the current session token without
	 * having access to the authentication cookies which are just being set
	 * on the first password-based authentication request.
	 *
	 * @param \WP_User $user User object.
	 *
	 * @return void
	 */
	public static function destroy_current_session_for_user( $user ) {
		$session_manager = WP_Session_Tokens::get_instance( $user->ID );

		foreach ( self::$password_auth_tokens as $auth_token ) {
			$session_manager->destroy( $auth_token );
		}
	}

	/**
	 * Prevent login through XML-RPC and REST API for users with at least one
	 * two-factor method enabled.
	 *
	 * @param  WP_User|WP_Error $user Valid WP_User only if the previous filters
	 *                                have verified and confirmed the
	 *                                authentication credentials.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function filter_authenticate( $user ) {
		if ( $user instanceof WP_User && self::is_api_request() && self::is_user_using_two_factor( $user->ID ) && ! self::is_user_api_login_enabled( $user->ID ) ) {
			return new WP_Error(
				'invalid_application_credentials',
				__( 'Error: API login for user disabled.', 'two-factor' )
			);
		}

		return $user;
	}

	/**
	 * Prevent login cookies being set on login for Two Factor users.
	 *
	 * This makes it so that Core never sends the auth cookies. `login_form_validate_2fa()` will send them manually once the 2nd factor has been verified.
	 *
	 * @param  WP_User|WP_Error $user Valid WP_User only if the previous filters
	 *                                have verified and confirmed the
	 *                                authentication credentials.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function filter_authenticate_block_cookies( $user ) {
		/*
		 * NOTE: The `login_init` action is checked for here to ensure we're within the regular login flow,
		 * rather than through an unsupported 3rd-party login process which this plugin doesn't support.
		 */
		if ( $user instanceof WP_User && self::is_user_using_two_factor( $user->ID ) && did_action( 'login_init' ) ) {
			add_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		}

		return $user;
	}

	/**
	 * If the current user can login via API requests such as XML-RPC and REST.
	 *
	 * @param  integer $user_id User ID.
	 *
	 * @return boolean
	 */
	public static function is_user_api_login_enabled( $user_id ) {
		return (bool) apply_filters( 'two_factor_user_api_login_enable', false, $user_id );
	}

	/**
	 * Is the current request an XML-RPC or REST request.
	 *
	 * @return boolean
	 */
	public static function is_api_request() {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		return false;
	}

	/**
	 * Display the login form.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function show_two_factor_login( $user ) {
		if ( ! $user ) {
			$user = wp_get_current_user();
		}

		$login_nonce = self::create_login_nonce( $user->ID );
		if ( ! $login_nonce ) {
			wp_die( esc_html__( 'Failed to create a login nonce.', 'two-factor' ) );
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : admin_url();

		self::login_html( $user, $login_nonce['key'], $redirect_to );
	}

	/**
	 * Display the Backup code 2fa screen.
	 *
	 * @since 0.1-dev
	 */
	public static function backup_2fa() {
		$wp_auth_id = filter_input( INPUT_GET, 'wp-auth-id', FILTER_SANITIZE_NUMBER_INT );
		$nonce      = filter_input( INPUT_GET, 'wp-auth-nonce', FILTER_CALLBACK, array( 'options' => 'sanitize_key' ) );
		$provider   = filter_input( INPUT_GET, 'provider', FILTER_CALLBACK, array( 'options' => 'sanitize_text_field' ) );

		if ( ! $wp_auth_id || ! $nonce || ! $provider ) {
			return;
		}

		$user = get_userdata( $wp_auth_id );
		if ( ! $user ) {
			return;
		}

		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$providers = self::get_available_providers_for_user( $user );
		if ( isset( $providers[ $provider ] ) ) {
			$provider = $providers[ $provider ];
		} else {
			wp_die( esc_html__( 'Cheatin&#8217; uh?', 'two-factor' ), 403 );
		}

		$redirect_to = filter_input( INPUT_GET, 'redirect_to', FILTER_SANITIZE_URL );
		self::login_html( $user, $nonce, $redirect_to, '', $provider );

		exit;
	}

	/**
	 * Displays a message informing the user that their account has had failed login attempts.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function maybe_show_last_login_failure_notice( $user ) {
		$last_failed_two_factor_login = (int) get_user_meta( $user->ID, self::USER_RATE_LIMIT_KEY, true );
		$failed_login_count           = (int) get_user_meta( $user->ID, self::USER_FAILED_LOGIN_ATTEMPTS_KEY, true );

		if ( $last_failed_two_factor_login ) {
			echo '<div id="login_notice" class="message"><strong>';
			printf(
				_n(
					'WARNING: Your account has attempted to login without providing a valid two factor token. The last failed login occured %2$s ago. If this wasn\'t you, you should reset your password.',
					'WARNING: Your account has attempted to login %1$s times without providing a valid two factor token. The last failed login occured %2$s ago. If this wasn\'t you, you should reset your password.',
					$failed_login_count,
					'two-factor'
				),
				number_format_i18n( $failed_login_count ),
				human_time_diff( $last_failed_two_factor_login, time() )
			);
			echo '</strong></div>';
		}
	}

	/**
	 * Show the password reset notice if the user's password was reset.
	 *
	 * They were also sent an email notification in `send_password_reset_email()`, but email sent from a typical
	 * web server is not reliable enough to trust completely.
	 *
	 * @param WP_Error $errors
	 */
	public static function maybe_show_reset_password_notice( $errors ) {
		if ( 'incorrect_password' !== $errors->get_error_code() ) {
			return $errors;
		}

		if ( ! isset( $_POST['log'] ) ) {
			return $errors;
		}

		$user_name      = sanitize_user( wp_unslash( $_POST['log'] ) );
		$attempted_user = get_user_by( 'login', $user_name );
		if ( ! $attempted_user && str_contains( $user_name, '@' ) ) {
			$attempted_user = get_user_by( 'email', $user_name );
		}

		if ( ! $attempted_user ) {
			return $errors;
		}
		
		$password_was_reset = get_user_meta( $attempted_user->ID, self::USER_PASSWORD_WAS_RESET_KEY, true );

		if ( ! $password_was_reset ) {
			return $errors;
		}

		$errors->remove( 'incorrect_password' );
		$errors->add(
			'two_factor_password_reset',
			sprintf(
				__( 'Your password was reset because of too many failed Two Factor attempts. You will need to <a href="%s">create a new password</a> to regain access. Please check your email for more information.', 'two-factor' ),
				esc_url( add_query_arg( 'action', 'lostpassword', wp_login_url() ) )
			)
		);

		return $errors;
	}

	/**
	 * Clear the password reset notice after the user resets their password.
	 *
	 * @param WP_User $user
	 */
	public static function clear_password_reset_notice( $user ) {
		delete_user_meta( $user->ID, self::USER_PASSWORD_WAS_RESET_KEY );
	}

	/**
	 * Generates the html form for the second step of the authentication process.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User       $user WP_User object of the logged-in user.
	 * @param string        $login_nonce A string nonce stored in usermeta.
	 * @param string        $redirect_to The URL to which the user would like to be redirected.
	 * @param string        $error_msg Optional. Login error message.
	 * @param string|object $provider An override to the provider.
	 */
	public static function login_html( $user, $login_nonce, $redirect_to, $error_msg = '', $provider = null ) {
		if ( empty( $provider ) ) {
			$provider = self::get_primary_provider_for_user( $user->ID );
		} elseif ( is_string( $provider ) && method_exists( $provider, 'get_instance' ) ) {
			$provider = call_user_func( array( $provider, 'get_instance' ) );
		}

		$provider_class = get_class( $provider );

		$available_providers = self::get_available_providers_for_user( $user );
		$backup_providers    = array_diff_key( $available_providers, array( $provider_class => null ) );
		$interim_login       = isset( $_REQUEST['interim-login'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$rememberme = intval( self::rememberme() );

		if ( ! function_exists( 'login_header' ) ) {
			// We really should migrate login_header() out of `wp-login.php` so it can be called from an includes file.
			include_once TWO_FACTOR_DIR . 'includes/function.login-header.php';
		}

		login_header();

		if ( ! empty( $error_msg ) ) {
			echo '<div id="login_error"><strong>' . esc_html( $error_msg ) . '</strong><br /></div>';
		} else {
			self::maybe_show_last_login_failure_notice( $user );
		}
		?>

		<form name="validate_2fa_form" id="loginform" action="<?php echo esc_url( self::login_url( array( 'action' => 'validate_2fa' ), 'login_post' ) ); ?>" method="post" autocomplete="off">
				<input type="hidden" name="provider"      id="provider"      value="<?php echo esc_attr( $provider_class ); ?>" />
				<input type="hidden" name="wp-auth-id"    id="wp-auth-id"    value="<?php echo esc_attr( $user->ID ); ?>" />
				<input type="hidden" name="wp-auth-nonce" id="wp-auth-nonce" value="<?php echo esc_attr( $login_nonce ); ?>" />
				<?php if ( $interim_login ) { ?>
					<input type="hidden" name="interim-login" value="1" />
				<?php } else { ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
				<?php } ?>
				<input type="hidden" name="rememberme"    id="rememberme"    value="<?php echo esc_attr( $rememberme ); ?>" />

				<?php $provider->authentication_page( $user ); ?>
		</form>

		<?php
		if ( 1 === count( $backup_providers ) ) :
			$backup_classname = key( $backup_providers );
			$backup_provider  = $backup_providers[ $backup_classname ];
			$login_url        = self::login_url(
				array(
					'action'        => 'backup_2fa',
					'provider'      => $backup_classname,
					'wp-auth-id'    => $user->ID,
					'wp-auth-nonce' => $login_nonce,
					'redirect_to'   => $redirect_to,
					'rememberme'    => $rememberme,
				)
			);
			?>
			<div class="backup-methods-wrap">
				<p class="backup-methods">
					<a href="<?php echo esc_url( $login_url ); ?>">
						<?php
						echo esc_html(
							sprintf(
								// translators: %s: Two-factor method name.
								__( 'Or, use your backup method: %s &rarr;', 'two-factor' ),
								$backup_provider->get_label()
							)
						);
						?>
					</a>
				</p>
			</div>
			<?php elseif ( 1 < count( $backup_providers ) ) : ?>
			<div class="backup-methods-wrap">
				<p class="backup-methods">
					<a href="javascript:;" onclick="document.querySelector('ul.backup-methods').style.display = 'block';">
						<?php esc_html_e( 'Or, use a backup method…', 'two-factor' ); ?>
					</a>
				</p>
				<ul class="backup-methods">
					<?php
					foreach ( $backup_providers as $backup_classname => $backup_provider ) :
						$login_url = self::login_url(
							array(
								'action'        => 'backup_2fa',
								'provider'      => $backup_classname,
								'wp-auth-id'    => $user->ID,
								'wp-auth-nonce' => $login_nonce,
								'redirect_to'   => $redirect_to,
								'rememberme'    => $rememberme,
							)
						);
						?>
						<li>
							<a href="<?php echo esc_url( $login_url ); ?>">
								<?php echo esc_html( $backup_provider->get_label() ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<style>
			/* @todo: migrate to an external stylesheet. */
			.backup-methods-wrap {
			margin-top: 16px;
			padding: 0 24px;
			}
			.backup-methods-wrap a {
			color: #999;
			text-decoration: none;
			}
			ul.backup-methods {
			display: none;
			padding-left: 1.5em;
			}
			/* Prevent Jetpack from hiding our controls, see https://github.com/Automattic/jetpack/issues/3747 */
			.jetpack-sso-form-display #loginform > p,
			.jetpack-sso-form-display #loginform > div {
			display: block;
			}
			#login form p.two-factor-prompt {
			margin-bottom: 1em;
			}
		</style>

		<?php
		if ( ! function_exists( 'login_footer' ) ) {
			include_once TWO_FACTOR_DIR . 'includes/function.login-footer.php';
		}

			login_footer();
		?>
		<?php
	}

	/**
	 * Generate the two-factor login form URL.
	 *
	 * @param  array  $params List of query argument pairs to add to the URL.
	 * @param  string $scheme URL scheme context.
	 *
	 * @return string
	 */
	public static function login_url( $params = array(), $scheme = 'login' ) {
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$params = urlencode_deep( $params );

		return add_query_arg( $params, site_url( 'wp-login.php', $scheme ) );
	}

	/**
	 * Get the hash of a nonce for storage and comparison.
	 *
	 * @param array $nonce Nonce array to be hashed. ⚠️ This must contain user ID and expiration,
	 *                     to guarantee the nonce only works for the intended user during the
	 *                     intended time window.
	 *
	 * @return string|false
	 */
	protected static function hash_login_nonce( $nonce ) {
		$message = wp_json_encode( $nonce );

		if ( ! $message ) {
			return false;
		}

		return wp_hash( $message, 'nonce' );
	}

	/**
	 * Create the login nonce.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 * @return array|false
	 */
	public static function create_login_nonce( $user_id ) {
		$login_nonce = array(
			'user_id'    => $user_id,
			'expiration' => time() + ( 10 * MINUTE_IN_SECONDS ),
		);

		try {
			$login_nonce['key'] = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $ex ) {
			$login_nonce['key'] = wp_hash( $user_id . wp_rand() . microtime(), 'nonce' );
		}

		// Store the nonce hashed to avoid leaking it via database access.
		$hashed_key = self::hash_login_nonce( $login_nonce );

		if ( $hashed_key ) {
			$login_nonce_stored = array(
				'expiration' => $login_nonce['expiration'],
				'key'        => $hashed_key,
			);

			if ( update_user_meta( $user_id, self::USER_META_NONCE_KEY, $login_nonce_stored ) ) {
				return $login_nonce;
			}
		}

		return false;
	}

	/**
	 * Delete the login nonce.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function delete_login_nonce( $user_id ) {
		return delete_user_meta( $user_id, self::USER_META_NONCE_KEY );
	}

	/**
	 * Verify the login nonce.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id User ID.
	 * @param string $nonce Login nonce.
	 * @return bool
	 */
	public static function verify_login_nonce( $user_id, $nonce ) {
		$login_nonce = get_user_meta( $user_id, self::USER_META_NONCE_KEY, true );

		if ( ! $login_nonce || empty( $login_nonce['key'] ) || empty( $login_nonce['expiration'] ) ) {
			return false;
		}

		$unverified_nonce = array(
			'user_id'    => $user_id,
			'expiration' => $login_nonce['expiration'],
			'key'        => $nonce,
		);

		$unverified_hash = self::hash_login_nonce( $unverified_nonce );
		$hashes_match    = $unverified_hash && hash_equals( $login_nonce['key'], $unverified_hash );

		if ( $hashes_match && time() < $login_nonce['expiration'] ) {
			return true;
		}

		// Require a fresh nonce if verification fails.
		self::delete_login_nonce( $user_id );

		return false;
	}

	/**
	 * Determine the minimum wait between two factor attempts for a user.
	 *
	 * This implements an increasing backoff, requiring an attacker to wait longer
	 * each time to attempt to brute-force the login.
	 *
	 * @param WP_User $user The user being operated upon.
	 * @return int Time delay in seconds between login attempts.
	 */
	public static function get_user_time_delay( $user ) {
		/**
		 * Filter the minimum time duration between two factor attempts.
		 *
		 * @param int $rate_limit The number of seconds between two factor attempts.
		 */
		$rate_limit = apply_filters( 'two_factor_rate_limit', 1 );

		$user_failed_logins = get_user_meta( $user->ID, self::USER_FAILED_LOGIN_ATTEMPTS_KEY, true );
		if ( $user_failed_logins ) {
			$rate_limit = pow( 2, $user_failed_logins ) * $rate_limit;

			/**
			 * Filter the maximum time duration a user may be locked out from retrying two factor authentications.
			 *
			 * @param int $max_rate_limit The maximum number of seconds a user might be locked out for. Default 15 minutes.
			 */
			$max_rate_limit = apply_filters( 'two_factor_max_rate_limit', 15 * MINUTE_IN_SECONDS );

			$rate_limit = min( $max_rate_limit, $rate_limit );
		}

		/**
		 * Filters the per-user time duration between two factor login attempts.
		 *
		 * @param int     $rate_limit The number of seconds between two factor attempts.
		 * @param WP_User $user       The user attempting to login.
		 */
		return apply_filters( 'two_factor_user_rate_limit', $rate_limit, $user );
	}

	/**
	 * Determine if a time delay between user two factor login attempts should be triggered.
	 *
	 * @since 0.8.0
	 *
	 * @param WP_User $user The User.
	 * @return bool True if rate limit is okay, false if not.
	 */
	public static function is_user_rate_limited( $user ) {
		$rate_limit  = self::get_user_time_delay( $user );
		$last_failed = get_user_meta( $user->ID, self::USER_RATE_LIMIT_KEY, true );

		$rate_limited = false;
		if ( $last_failed && $last_failed + $rate_limit > time() ) {
			$rate_limited = true;
		}

		/**
		 * Filter whether this login attempt is rate limited or not.
		 *
		 * This allows for dedicated plugins to rate limit two factor login attempts
		 * based on their own rules.
		 *
		 * @param bool     $rate_limited Whether the user login is rate limited.
		 * @param WP_User $user          The user attempting to login.
		 */
		return apply_filters( 'two_factor_is_user_rate_limited', $rate_limited, $user );
	}

	/**
	 * Login form validation.
	 *
	 * @since 0.1-dev
	 */
	public static function login_form_validate_2fa() {
		$wp_auth_id = filter_input( INPUT_POST, 'wp-auth-id', FILTER_SANITIZE_NUMBER_INT );
		$nonce      = filter_input( INPUT_POST, 'wp-auth-nonce', FILTER_CALLBACK, array( 'options' => 'sanitize_key' ) );

		if ( ! $wp_auth_id || ! $nonce ) {
			return;
		}

		$user = get_userdata( $wp_auth_id );
		if ( ! $user ) {
			return;
		}

		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$provider = filter_input( INPUT_POST, 'provider', FILTER_CALLBACK, array( 'options' => 'sanitize_text_field' ) );
		if ( $provider ) {
			$providers = self::get_available_providers_for_user( $user );
			if ( isset( $providers[ $provider ] ) ) {
				$provider = $providers[ $provider ];
			} else {
				wp_die( esc_html__( 'Cheatin&#8217; uh?', 'two-factor' ), 403 );
			}
		} else {
			$provider = self::get_primary_provider_for_user( $user->ID );
		}

		// Allow the provider to re-send codes, etc.
		if ( true === $provider->pre_process_authentication( $user ) ) {
			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				wp_die( esc_html__( 'Failed to create a login nonce.', 'two-factor' ) );
			}

			self::login_html( $user, $login_nonce['key'], $_REQUEST['redirect_to'], '', $provider );
			exit;
		}

		// Rate limit two factor authentication attempts.
		if ( true === self::is_user_rate_limited( $user ) ) {
			$time_delay = self::get_user_time_delay( $user );
			$last_login = get_user_meta( $user->ID, self::USER_RATE_LIMIT_KEY, true );

			$error = new WP_Error(
				'two_factor_too_fast',
				sprintf(
					__( 'ERROR: Too many invalid verification codes, you can try again in %s. This limit protects your account against automated attacks.', 'two-factor' ),
					human_time_diff( $last_login + $time_delay )
				)
			);

			do_action( 'wp_login_failed', $user->user_login, $error );

			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				wp_die( esc_html__( 'Failed to create a login nonce.', 'two-factor' ) );
			}

			self::login_html( $user, $login_nonce['key'], $_REQUEST['redirect_to'], esc_html( $error->get_error_message() ), $provider );
			exit;
		}

		// Ask the provider to verify the second factor.
		if ( true !== $provider->validate_authentication( $user ) ) {
			do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'two_factor_invalid', __( 'ERROR: Invalid verification code.', 'two-factor' ) ) );

			// Store the last time a failed login occured.
			update_user_meta( $user->ID, self::USER_RATE_LIMIT_KEY, time() );

			// Store the number of failed login attempts.
			update_user_meta( $user->ID, self::USER_FAILED_LOGIN_ATTEMPTS_KEY, 1 + (int) get_user_meta( $user->ID, self::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );

			if ( self::should_reset_password( $user->ID ) ) {
				self::reset_compromised_password( $user );
				self::send_password_reset_emails( $user );
				self::show_password_reset_error();
				exit;
			}

			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				wp_die( esc_html__( 'Failed to create a login nonce.', 'two-factor' ) );
			}

			self::login_html( $user, $login_nonce['key'], $_REQUEST['redirect_to'], esc_html__( 'ERROR: Invalid verification code.', 'two-factor' ), $provider );
			exit;
		}

		self::delete_login_nonce( $user->ID );
		delete_user_meta( $user->ID, self::USER_RATE_LIMIT_KEY );
		delete_user_meta( $user->ID, self::USER_FAILED_LOGIN_ATTEMPTS_KEY );

		$rememberme = false;
		if ( isset( $_REQUEST['rememberme'] ) && $_REQUEST['rememberme'] ) {
			$rememberme = true;
		}

		/*
		 * NOTE: This filter removal is not normally required, this is included for protection against
		 * a plugin/two factor provider which runs the `authenticate` filter during it's validation.
		 * Such a plugin would cause self::filter_authenticate_block_cookies() to run and add this filter.
		 */
		remove_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		wp_set_auth_cookie( $user->ID, $rememberme );

		do_action( 'two_factor_user_authenticated', $user );

		// Must be global because that's how login_header() uses it.
		global $interim_login;
		$interim_login = isset( $_REQUEST['interim-login'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.Security.NonceVerification.Recommended

		if ( $interim_login ) {
			$customize_login = isset( $_REQUEST['customize-login'] );
			if ( $customize_login ) {
				wp_enqueue_script( 'customize-base' );
			}
			$message       = '<p class="message">' . __( 'You have logged in successfully.', 'two-factor' ) . '</p>';
			$interim_login = 'success'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			login_header( '', $message );
			?>
			</div>
			<?php
			/** This action is documented in wp-login.php */
			do_action( 'login_footer' );
			?>
			<?php if ( $customize_login ) : ?>
				<script type="text/javascript">setTimeout( function(){ new wp.customize.Messenger({ url: '<?php echo esc_url( wp_customize_url() ); ?>', channel: 'login' }).send('login') }, 1000 );</script>
			<?php endif; ?>
			</body></html>
			<?php
			exit;
		}
		$redirect_to = apply_filters( 'login_redirect', $_REQUEST['redirect_to'], $_REQUEST['redirect_to'], $user );
		wp_safe_redirect( $redirect_to );

		exit;
	}

	/**
	 * Determine if the user's password should be reset.
	 *
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public static function should_reset_password( $user_id ) {
		$failed_attempts = (int) get_user_meta( $user_id, self::USER_FAILED_LOGIN_ATTEMPTS_KEY, true );

		/**
		 * Filters the maximum number of failed attempts on a 2nd factor before the user's
		 * password will be reset. After a reasonable number of attempts, it's safe to assume
		 * that the password has been compromised and an attacker is trying to brute force the 2nd
		 * factor.
		 *
		 * ⚠️ `get_user_time_delay()` mitigates brute force attempts, but many 2nd factors --
		 * like TOTP and backup codes -- are very weak on their own, so it's not safe to give
		 * attackers unlimited attempts. Setting this to a very large number is strongly
		 * discouraged.
		 *
		 * @param int $limit The number of attempts before the password is reset.
		 */
		$failed_attempt_limit = apply_filters( 'two_factor_failed_attempt_limit', 30 );

		return $failed_attempts >= $failed_attempt_limit;
	}

	/**
	 * Reset a compromised password.
	 *
	 * If we know that the the password is compromised, we have the responsibility to reset it and inform the
	 * user. `get_user_time_delay()` mitigates brute force attempts, but this acts as an extra layer of defense
	 * which guarantees that attackers can't brute force it (unless they compromise the new password).
	 *
	 * @param WP_User $user The user who failed to login
	 */
	public static function reset_compromised_password( $user ) {
		// Unhook because `wp_password_change_notification()` wouldn't notify the site admin when
		// their password is compromised.
		remove_action( 'after_password_reset', 'wp_password_change_notification' );
		reset_password( $user, wp_generate_password( 25 ) );
		update_user_meta( $user->ID, self::USER_PASSWORD_WAS_RESET_KEY, true );
		add_action( 'after_password_reset', 'wp_password_change_notification' );

		self::delete_login_nonce( $user->ID );
		delete_user_meta( $user->ID, self::USER_RATE_LIMIT_KEY );
		delete_user_meta( $user->ID, self::USER_FAILED_LOGIN_ATTEMPTS_KEY );
	}

	/**
	 * Notify the user and admin that a password was reset for being compromised.
	 *
	 * @param WP_User $user The user whose password should be reset
	 */
	public static function send_password_reset_emails( $user ) {
		self::notify_user_password_reset( $user );

		/**
		 * Filters whether or not to email the site admin when a user's password has been
		 * compromised and reset.
		 *
		 * @param bool $reset `true` to notify the admin, `false` to not notify them.
		 */
		$notify_admin = apply_filters( 'two_factor_notify_admin_user_password_reset', true );
		$admin_email  = get_option( 'admin_email' );

		if ( $notify_admin && $admin_email !== $user->user_email ) {
			self::notify_admin_user_password_reset( $user );
		}
	}

	/**
	 * Notify the user that their password has been compromised and reset.
	 *
	 * @param WP_User $user The user to notify
	 *
	 * @return bool `true` if the email was sent, `false` if it failed.
	 */
	public static function notify_user_password_reset( $user ) {
		$user_message = sprintf(
			'Hello %1$s, an unusually high number of failed login attempts have been detected on your account at %2$s.

			These attempts successfully entered your password, and were only blocked because they failed to enter your second authentication factor. Despite not being able to access your account, this behavior indicates that the attackers have compromised your password. The most common reasons for this are that your password was easy to guess, or was reused on another site which has been compromised.

			To protect your account, your password has been reset, and you will need to create a new one. For advice on setting a strong password, please read %3$s

			To pick a new password, please visit %4$s

			This is an automated notification. If you would like to speak to a site administrator, please contact them directly.',
			esc_html( $user->user_login ),
			home_url(),
			'https://wordpress.org/documentation/article/password-best-practices/',
			esc_url( add_query_arg( 'action', 'lostpassword', wp_login_url() ) ),
		);
		$user_message = str_replace( "\t", '', $user_message );

		return wp_mail( $user->user_email, 'Your password was compromised and has been reset', $user_message );
	}

	/**
	 * Notify the admin that a user's password was compromised and reset.
	 *
	 * @param WP_User $user The user whose password was reset.
	 *
	 * @return bool `true` if the email was sent, `false` if it failed.
	 */
	public static function notify_admin_user_password_reset( $user ) {
		$admin_email = get_option( 'admin_email' );
		$subject     = sprintf( 'Compromised password for %s has been reset', esc_html( $user->user_login ) );

		$message = sprintf(
			'Hello, this is a notice from the Two Factor plugin to inform you that an unusually high number of failed login attempts have been detected on the %1$s account (ID %2$d).

			Those attempts successfully entered the user\'s password, and were only blocked because they entered invalid second authentication factors.

			To protect their account, the password has automatically been reset, and they have been notified that they will need to create a new one.

			If you do not wish to receive these notifications, you can disable them with the `two_factor_notify_admin_user_password_reset` filter. See %3$s for more information.

			Thank you',
			esc_html( $user->user_login ),
			$user->ID,
			'https://developer.wordpress.org/plugins/hooks/'
		);
		$message = str_replace( "\t", '', $message );

		return wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Show the password reset error when on the login screen.
	 */
	public static function show_password_reset_error() {
		$error = new WP_Error(
			'too_many_attempts',
			sprintf(
				'<p>%s</p>
				<p style="margin-top: 1em;">%s</p>',
				__( 'There have been too many failed two-factor authentication attempts, which often indicates that the password has been compromised. The password has been reset in order to protect the account.', 'two-factor' ),
				__( 'If you are the owner of this account, please check your email for instructions on regaining access.', 'two-factor' )
			)
		);

		login_header( __( 'Password Reset', 'two-factor' ), '',  $error );
		login_footer();
	}

	/**
	 * Filter the columns on the Users admin screen.
	 *
	 * @param  array $columns Available columns.
	 * @return array          Updated array of columns.
	 */
	public static function filter_manage_users_columns( array $columns ) {
		$columns['two-factor'] = __( 'Two-Factor', 'two-factor' );
		return $columns;
	}

	/**
	 * Output the 2FA column data on the Users screen.
	 *
	 * @param  string $output      The column output.
	 * @param  string $column_name The column ID.
	 * @param  int    $user_id     The user ID.
	 * @return string              The column output.
	 */
	public static function manage_users_custom_column( $output, $column_name, $user_id ) {

		if ( 'two-factor' !== $column_name ) {
			return $output;
		}

		if ( ! self::is_user_using_two_factor( $user_id ) ) {
			return sprintf( '<span class="dashicons-before dashicons-no-alt">%s</span>', esc_html__( 'Disabled', 'two-factor' ) );
		} else {
			$provider = self::get_primary_provider_for_user( $user_id );
			return esc_html( $provider->get_label() );
		}

	}

	/**
	 * Add user profile fields.
	 *
	 * This executes during the `show_user_profile` & `edit_user_profile` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function user_two_factor_options( $user ) {
		wp_enqueue_style( 'user-edit-2fa', plugins_url( 'user-edit.css', __FILE__ ), array(), TWO_FACTOR_VERSION );

		$enabled_providers = array_keys( self::get_available_providers_for_user( $user ) );
		$primary_provider  = self::get_primary_provider_for_user( $user->ID );

		if ( ! empty( $primary_provider ) && is_object( $primary_provider ) ) {
			$primary_provider_key = get_class( $primary_provider );
		} else {
			$primary_provider_key = null;
		}

		wp_nonce_field( 'user_two_factor_options', '_nonce_user_two_factor_options', false );

		?>
		<input type="hidden" name="<?php echo esc_attr( self::ENABLED_PROVIDERS_USER_META_KEY ); ?>[]" value="<?php /* Dummy input so $_POST value is passed when no providers are enabled. */ ?>" />
		<table class="form-table" id="two-factor-options">
			<tr>
				<th>
					<?php esc_html_e( 'Two-Factor Options', 'two-factor' ); ?>
				</th>
				<td>
					<table class="two-factor-methods-table">
						<thead>
							<tr>
								<th class="col-enabled" scope="col"><?php esc_html_e( 'Enabled', 'two-factor' ); ?></th>
								<th class="col-primary" scope="col"><?php esc_html_e( 'Primary', 'two-factor' ); ?></th>
								<th class="col-name" scope="col"><?php esc_html_e( 'Type', 'two-factor' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( self::get_providers() as $class => $object ) : ?>
							<tr>
								<th scope="row"><input id="enabled-<?php echo esc_attr( $class ); ?>" type="checkbox" name="<?php echo esc_attr( self::ENABLED_PROVIDERS_USER_META_KEY ); ?>[]" value="<?php echo esc_attr( $class ); ?>" <?php checked( in_array( $class, $enabled_providers, true ) ); ?> /></th>
								<th scope="row"><input type="radio" name="<?php echo esc_attr( self::PROVIDER_USER_META_KEY ); ?>" value="<?php echo esc_attr( $class ); ?>" <?php checked( $class, $primary_provider_key ); ?> /></th>
								<td>
									<label class="two-factor-method-label" for="enabled-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $object->get_label() ); ?></label>
									<?php
										/**
										 * Fires after user options are shown.
										 *
										 * Use the {@see 'two_factor_user_options_' . $class } hook instead.
										 *
										 * @deprecated 0.7.0
										 *
										 * @param WP_User $user The user.
										 */
										do_action_deprecated( 'two-factor-user-options-' . $class, array( $user ), '0.7.0', 'two_factor_user_options_' . $class );
										do_action( 'two_factor_user_options_' . $class, $user );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</td>
			</tr>
		</table>
		<?php
		/**
		 * Fires after the Two Factor methods table.
		 *
		 * To be used by Two Factor methods to add settings UI.
		 *
		 * @since 0.1-dev
		 */
		do_action( 'show_user_security_settings', $user );
	}

	/**
	 * Enable a provider for a user.
	 *
	 * @param int    $user_id      The ID of the user.
	 * @param string $new_provider The name of the provider class.
	 *
	 * @return bool True if the provider was enabled, false otherwise.
	 */
	public static function enable_provider_for_user( $user_id, $new_provider ) {
		$available_providers = self::get_providers();

		if ( ! array_key_exists( $new_provider, $available_providers ) ) {
			return false;
		}

		$user              = get_userdata( $user_id );
		$enabled_providers = self::get_enabled_providers_for_user( $user );

		if ( in_array( $new_provider, $enabled_providers ) ) {
			return true;
		}

		$enabled_providers[] = $new_provider;
		$enabled             = update_user_meta( $user_id, self::ENABLED_PROVIDERS_USER_META_KEY, $enabled_providers );

		// Primary provider must be enabled.
		$has_primary = is_object( self::get_primary_provider_for_user( $user_id ) );

		if ( ! $has_primary ) {
			$has_primary = update_user_meta( $user_id, self::PROVIDER_USER_META_KEY, $new_provider );
		}

		return $enabled && $has_primary;
	}

	/**
	 * Update the user meta value.
	 *
	 * This executes during the `personal_options_update` & `edit_user_profile_update` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 */
	public static function user_two_factor_options_update( $user_id ) {
		if ( isset( $_POST['_nonce_user_two_factor_options'] ) ) {
			check_admin_referer( 'user_two_factor_options', '_nonce_user_two_factor_options' );

			if ( ! isset( $_POST[ self::ENABLED_PROVIDERS_USER_META_KEY ] ) ||
					! is_array( $_POST[ self::ENABLED_PROVIDERS_USER_META_KEY ] ) ) {
				return;
			}

			$providers = self::get_providers();

			$enabled_providers = $_POST[ self::ENABLED_PROVIDERS_USER_META_KEY ];

			// Enable only the available providers.
			$enabled_providers = array_intersect( $enabled_providers, array_keys( $providers ) );
			update_user_meta( $user_id, self::ENABLED_PROVIDERS_USER_META_KEY, $enabled_providers );

			// Primary provider must be enabled.
			$new_provider = isset( $_POST[ self::PROVIDER_USER_META_KEY ] ) ? $_POST[ self::PROVIDER_USER_META_KEY ] : '';
			if ( ! empty( $new_provider ) && in_array( $new_provider, $enabled_providers, true ) ) {
				update_user_meta( $user_id, self::PROVIDER_USER_META_KEY, $new_provider );
			}
		}
	}

	/**
	 * Should the login session persist between sessions.
	 *
	 * @return boolean
	 */
	public static function rememberme() {
		$rememberme = false;

		if ( ! empty( $_REQUEST['rememberme'] ) ) {
			$rememberme = true;
		}

		return (bool) apply_filters( 'two_factor_rememberme', $rememberme );
	}
}
