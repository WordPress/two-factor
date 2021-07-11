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
	 * Keep track of all the password-based authentication sessions that
	 * need to invalidated before the second factor authentication.
	 *
	 * @var array
	 */
	private static $password_auth_tokens = array();

	/**
	 * Set up filters and actions.
	 *
	 * @param object $compat A compaitbility later for plugins.
	 *
	 * @since 0.1-dev
	 */
	public static function add_hooks( $compat ) {
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'get_providers' ) );
		add_action( 'wp_login', array( __CLASS__, 'wp_login' ), 10, 2 );
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
		$request_nonce = filter_input( INPUT_GET, self::USER_SETTINGS_ACTION_NONCE_QUERY_ARG, FILTER_SANITIZE_STRING );

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
		$action  = filter_input( INPUT_GET, self::USER_SETTINGS_ACTION_QUERY_VAR, FILTER_SANITIZE_STRING );
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
		$nonce      = filter_input( INPUT_GET, 'wp-auth-nonce', FILTER_SANITIZE_STRING );
		$provider   = filter_input( INPUT_GET, 'provider', FILTER_SANITIZE_STRING );

		if ( ! $wp_auth_id || ! $nonce || ! $provider ) {
			return;
		}

		$user = get_userdata( $wp_auth_id );
		if ( ! $user ) {
			return;
		}

		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( get_bloginfo( 'url' ) );
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
								<?php $backup_provider->print_label(); ?>
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
	 * Create the login nonce.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function create_login_nonce( $user_id ) {
		$login_nonce = array();
		try {
			$login_nonce['key'] = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $ex ) {
			$login_nonce['key'] = wp_hash( $user_id . wp_rand() . microtime(), 'nonce' );
		}
		$login_nonce['expiration'] = time() + HOUR_IN_SECONDS;

		if ( ! update_user_meta( $user_id, self::USER_META_NONCE_KEY, $login_nonce ) ) {
			return false;
		}

		return $login_nonce;
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
		if ( ! $login_nonce ) {
			return false;
		}

		if ( $nonce !== $login_nonce['key'] || time() > $login_nonce['expiration'] ) {
			self::delete_login_nonce( $user_id );
			return false;
		}

		return true;
	}

	/**
	 * Login form validation.
	 *
	 * @since 0.1-dev
	 */
	public static function login_form_validate_2fa() {
		$wp_auth_id = filter_input( INPUT_POST, 'wp-auth-id', FILTER_SANITIZE_NUMBER_INT );
		$nonce      = filter_input( INPUT_POST, 'wp-auth-nonce', FILTER_SANITIZE_STRING );

		if ( ! $wp_auth_id || ! $nonce ) {
			return;
		}

		$user = get_userdata( $wp_auth_id );
		if ( ! $user ) {
			return;
		}

		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( get_bloginfo( 'url' ) );
			exit;
		}

		$provider = filter_input( INPUT_POST, 'provider', FILTER_SANITIZE_STRING );
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

		// Ask the provider to verify the second factor.
		if ( true !== $provider->validate_authentication( $user ) ) {
			do_action( 'wp_login_failed', $user->user_login );

			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				wp_die( esc_html__( 'Failed to create a login nonce.', 'two-factor' ) );
			}

			self::login_html( $user, $login_nonce['key'], $_REQUEST['redirect_to'], esc_html__( 'ERROR: Invalid verification code.', 'two-factor' ), $provider );
			exit;
		}

		self::delete_login_nonce( $user->ID );

		$rememberme = false;
		if ( isset( $_REQUEST['rememberme'] ) && $_REQUEST['rememberme'] ) {
			$rememberme = true;
		}

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
								<th class="col-name" scope="col"><?php esc_html_e( 'Name', 'two-factor' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( self::get_providers() as $class => $object ) : ?>
							<tr>
								<th scope="row"><input type="checkbox" name="<?php echo esc_attr( self::ENABLED_PROVIDERS_USER_META_KEY ); ?>[]" value="<?php echo esc_attr( $class ); ?>" <?php checked( in_array( $class, $enabled_providers, true ) ); ?> /></th>
								<th scope="row"><input type="radio" name="<?php echo esc_attr( self::PROVIDER_USER_META_KEY ); ?>" value="<?php echo esc_attr( $class ); ?>" <?php checked( $class, $primary_provider_key ); ?> /></th>
								<td>
									<?php
										$object->print_label();

										/**
										 * Fires after user options are shown.
										 *
										 * Use the {@see 'two_factor_user_options_' . $class } hook instead.
										 *
										 * @deprecated 0.7.0
										 *
										 * @param WP_User $user The user.
										 */
										do_action_deprecated(  'two-factor-user-options-' . $class, array( $user ), '0.7.0', 'two_factor_user_options_' . $class );
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
