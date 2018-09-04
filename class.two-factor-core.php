<?php
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
	 * The network forced 2fa user roles key.
	 *
	 * @type string
	 */
	const FORCED_ROLES_META_KEY = '_two_factor_forced_roles';

	/**
	 * The network forced 2fa global key.
	 *
	 * @type string
	 */
	const FORCED_SITE_META_KEY = '_two_factor_forced_universally';

	/**
	 * The user meta nonce key.
	 *
	 * @type string
	 */
	const USER_META_NONCE_KEY    = '_two_factor_nonce';

	/**
	 * Set up filters and actions.
	 *
	 * @since 0.1-dev
	 */
	public static function add_hooks() {
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

		// Forced 2fa login functionality.
		// @todo:: display settings to force 2fa on specific site, if site is not network.
		// @todo:: Add action to save said setting if site is not network.
		add_action( 'init', array( __CLASS__, 'register_scripts' ) );
		add_action( 'wpmu_options', array( __CLASS__, 'force_two_factor_setting_options' ) );
		add_action( 'update_wpmu_options', array( __CLASS__, 'save_network_force_two_factor_update' ) );
		add_action( 'wp_ajax_two_factor_force_form_submit', array( __CLASS__, 'handle_force_2fa_submission' ) );

		// Handling intercession in 2 separate hooks to allow us to properly parse for REST requests.
		add_action( 'parse_request', array( __CLASS__, 'maybe_force_2fa_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_force_2fa_settings' ) );
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
	 * Register scripts.
	 */
	public static function register_scripts() {
		// Script for handling AJAX submission in force 2fa takeover screen.
		wp_register_script(
			'two-factor-form-script',
			plugins_url( 'assets/js/force-2fa.js', __FILE__ ),
			[],
			'0.1',
			false
		);
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
			'Two_Factor_Email'        => TWO_FACTOR_DIR . 'providers/class.two-factor-email.php',
			'Two_Factor_Totp'         => TWO_FACTOR_DIR . 'providers/class.two-factor-totp.php',
			'Two_Factor_FIDO_U2F'     => TWO_FACTOR_DIR . 'providers/class.two-factor-fido-u2f.php',
			'Two_Factor_Backup_Codes' => TWO_FACTOR_DIR . 'providers/class.two-factor-backup-codes.php',
			'Two_Factor_Dummy'        => TWO_FACTOR_DIR . 'providers/class.two-factor-dummy.php',
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
			trigger_error( sprintf( // WPCS: XSS OK.
				/* translators: %s: version number */
				__( 'FIDO U2F is not available because you are using PHP %s. (Requires 5.3 or greater)', 'two-factor' ),
				PHP_VERSION
			) );
		}

		/**
		 * For each filtered provider,
		 */
		foreach ( $providers as $class => $path ) {
			include_once( $path );

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

		return $enabled_providers;
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
			if ( in_array( $classname, $enabled_providers ) && $provider->is_available_for_user( $user ) ) {
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

		wp_clear_auth_cookie();

		self::show_two_factor_login( $user );
		exit;
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

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : $_SERVER['REQUEST_URI'];

		self::login_html( $user, $login_nonce['key'], $redirect_to );
	}

	/**
	 * Add short description. @todo
	 *
	 * @since 0.1-dev
	 */
	public static function backup_2fa() {
		if ( ! isset( $_GET['wp-auth-id'], $_GET['wp-auth-nonce'], $_GET['provider'] ) ) {
			return;
		}

		$user = get_userdata( $_GET['wp-auth-id'] );
		if ( ! $user ) {
			return;
		}

		$nonce = $_GET['wp-auth-nonce'];
		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( get_bloginfo( 'url' ) );
			exit;
		}

		$providers = self::get_available_providers_for_user( $user );
		if ( isset( $providers[ $_GET['provider'] ] ) ) {
			$provider = $providers[ $_GET['provider'] ];
		} else {
			wp_die( esc_html__( 'Cheatin&#8217; uh?' ), 403 );
		}

		self::login_html( $user, $_GET['wp-auth-nonce'], $_GET['redirect_to'], '', $provider );

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
		$backup_providers = array_diff_key( $available_providers, array( $provider_class => null ) );
		$interim_login = isset( $_REQUEST['interim-login'] ); // WPCS: override ok.
		$wp_login_url = wp_login_url();

		$rememberme = 0;
		if ( isset( $_REQUEST['rememberme'] ) && $_REQUEST['rememberme'] ) {
			$rememberme = 1;
		}

		if ( ! function_exists( 'login_header' ) ) {
			// We really should migrate login_header() out of `wp-login.php` so it can be called from an includes file.
			include_once( TWO_FACTOR_DIR . 'includes/function.login-header.php' );
		}

		login_header();

		if ( ! empty( $error_msg ) ) {
			echo '<div id="login_error"><strong>' . esc_html( $error_msg ) . '</strong><br /></div>';
		}
		?>

		<form name="validate_2fa_form" id="loginform" action="<?php echo esc_url( set_url_scheme( add_query_arg( 'action', 'validate_2fa', $wp_login_url ), 'login_post' ) ); ?>" method="post" autocomplete="off">
				<input type="hidden" name="provider"      id="provider"      value="<?php echo esc_attr( $provider_class ); ?>" />
				<input type="hidden" name="wp-auth-id"    id="wp-auth-id"    value="<?php echo esc_attr( $user->ID ); ?>" />
				<input type="hidden" name="wp-auth-nonce" id="wp-auth-nonce" value="<?php echo esc_attr( $login_nonce ); ?>" />
				<?php   if ( $interim_login ) { ?>
					<input type="hidden" name="interim-login" value="1" />
				<?php   } else { ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
				<?php   } ?>
				<input type="hidden" name="rememberme"    id="rememberme"    value="<?php echo esc_attr( $rememberme ); ?>" />

				<?php $provider->authentication_page( $user ); ?>
		</form>

		<?php if ( 1 === count( $backup_providers ) ) :
			$backup_classname = key( $backup_providers );
			$backup_provider  = $backup_providers[ $backup_classname ];
			?>
			<div class="backup-methods-wrap">
				<p class="backup-methods"><a href="<?php echo esc_url( add_query_arg( urlencode_deep( array(
					'action'        => 'backup_2fa',
					'provider'      => $backup_classname,
					'wp-auth-id'    => $user->ID,
					'wp-auth-nonce' => $login_nonce,
					'redirect_to'   => $redirect_to,
					'rememberme'    => $rememberme,
				) ), $wp_login_url ) ); ?>"><?php echo esc_html( sprintf( __( 'Or, use your backup method: %s &rarr;', 'two-factor' ), $backup_provider->get_label() ) ); ?></a></p>
			</div>
		<?php elseif ( 1 < count( $backup_providers ) ) : ?>
			<div class="backup-methods-wrap">
				<p class="backup-methods"><a href="javascript:;" onclick="document.querySelector('ul.backup-methods').style.display = 'block';"><?php esc_html_e( 'Or, use a backup method…', 'two-factor' ); ?></a></p>
				<ul class="backup-methods">
					<?php foreach ( $backup_providers as $backup_classname => $backup_provider ) : ?>
						<li><a href="<?php echo esc_url( add_query_arg( urlencode_deep( array(
							'action'        => 'backup_2fa',
							'provider'      => $backup_classname,
							'wp-auth-id'    => $user->ID,
							'wp-auth-nonce' => $login_nonce,
							'redirect_to'   => $redirect_to,
							'rememberme'    => $rememberme,
						) ), $wp_login_url ) ); ?>"><?php $backup_provider->print_label(); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<p id="backtoblog">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php esc_attr_e( 'Are you lost?', 'two-factor' ); ?>"><?php /* translators: %s: site name */ echo esc_html( sprintf( __( '&larr; Back to %s', 'two-factor' ), get_bloginfo( 'title', 'display' ) ) ); ?></a>
		</p>

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
		/** This action is documented in wp-login.php */
		do_action( 'login_footer' ); ?>
		<div class="clear"></div>
		</body>
		</html>
		<?php
	}

	/**
	 * Maybe force the 2fa login page on a user.
	 *
	 * If 2fa is required for a user (based on universal or role settings),
	 * we display the 2-factor options page so that a user must validly enable
	 * a 2-factor authentication of some kind to perform any action on their site.
	 * This occurs both on the front and backend.
	 */
	public static function maybe_force_2fa_settings() {
		// This should not affect AJAX or REST requests, carry on.
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Should not interrupt logging in or out.
		if ( self::is_login_page() ) {
			return;
		}

		// If user is not logged in, they can't 2fa anyway.
		if ( ! is_user_logged_in() ) {
			return;
		}

		// 2fa is not forced for current user, nothing to show.
		if ( ! self::is_two_factor_forced_for_current_user() ) {
			return;
		}

		// The current user is already using two-factor, good for them!
		if ( self::is_user_using_two_factor() ) {
			return;
		}

		// We are now forced to display the two-factor settings page.
		self::force_2fa_login_html();
		exit;
	}

	/**
	 * Generates the html for adding 2-factor authentication to their account, if forced.
	 *
	 * If a user hits this screen, they must setup 2fa and do not get to skip.
	 *
	 * @since 0.1-dev
	 */
	public static function force_2fa_login_html() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'two-factor-form-script' );

		if ( ! function_exists( 'login_header' ) ) {
			// We really should migrate login_header() out of `wp-login.php` so it can be called from an includes file.
			include_once( TWO_FACTOR_DIR . 'includes/function.login-header.php' );
		}

		login_header();

		$user = wp_get_current_user();

		// Display the form for updating a user's two-factor options.
		?>
		<h2 class="force-2fa-title"><?php esc_html_e( 'You must have 2-factor authentication enabled to continue using this site. Please select and save at least one method of authentication.', 'two-factor' ); ?></h2>
		<form name="force_2fa_form" id="force_2fa_form" method="post" autocomplete="off">
			<?php self::user_two_factor_options( $user ); ?>
			<button class="button button-primary"><?php esc_html_e( 'Submit' ); ?></button>
		</form>

		<p id="backtoblog">
			<a href="<?php echo esc_url( wp_logout_url() ); ?>" title="<?php esc_attr_e( 'Are you lost?', 'two-factor' ); ?>">
				<?php esc_html_e( '&larr; Logout', 'two-factor' ); ?>
			</a>
		</p>

		<style>
			/* Hackity hack hack hack */
			#login {
				width: 100%;
				max-width: 1000px;
			}
			.login .button-primary {
				float: left;
			}
			.force-2fa-title {
				line-height: 1.3;
				text-align: center;
				padding: 0 10%;
			}
		</style>

		<script type="text/javascript">
			var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php', 'relative' ) ); ?>';
		</script>

		<?php
		/** This action is documented in wp-login.php */
		do_action( 'login_footer' ); ?>
		<div class="clear"></div>
		</body>
		</html>
		<?php
	}

	/**
	 * AJAX handler for 2fa settings from forced 2fa takeover screen.
	 */
	public static function handle_force_2fa_submission() {
		// Verify that a user is allowed here.
		check_ajax_referer( 'user_two_factor_options', '_nonce_user_two_factor_options' );

		// Save data.
		self::user_two_factor_options_update( get_current_user_id() );

		wp_send_json_success();
	}

	/**
	 * Create the login nonce.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 */
	public static function create_login_nonce( $user_id ) {
		$login_nonce               = array();
		try {
			$login_nonce['key'] = bin2hex( random_bytes( 32 ) );
		} catch (Exception $ex) {
			$login_nonce['key'] = wp_hash( $user_id . mt_rand() . microtime(), 'nonce' );
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
		if ( ! isset( $_POST['wp-auth-id'], $_POST['wp-auth-nonce'] ) ) {
			return;
		}

		$user = get_userdata( $_POST['wp-auth-id'] );
		if ( ! $user ) {
			return;
		}

		$nonce = $_POST['wp-auth-nonce'];
		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( get_bloginfo( 'url' ) );
			exit;
		}

		if ( isset( $_POST['provider'] ) ) {
			$providers = self::get_available_providers_for_user( $user );
			if ( isset( $providers[ $_POST['provider'] ] ) ) {
				$provider = $providers[ $_POST['provider'] ];
			} else {
				wp_die( esc_html__( 'Cheatin&#8217; uh?' ), 403 );
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

		// Must be global because that's how login_header() uses it.
		global $interim_login;
		$interim_login = isset( $_REQUEST['interim-login'] ); // WPCS: override ok.

		if ( $interim_login ) {
			$customize_login = isset( $_REQUEST['customize-login'] );
			if ( $customize_login ) {
				wp_enqueue_script( 'customize-base' );
			}
			$message       = '<p class="message">' . __( 'You have logged in successfully.', 'two-factor' ) . '</p>';
			$interim_login = 'success'; // WPCS: override ok.
			login_header( '', $message ); ?>
			</div>
			<?php
			/** This action is documented in wp-login.php */
			do_action( 'login_footer' ); ?>
			<?php if ( $customize_login ) : ?>
				<script type="text/javascript">setTimeout( function(){ new wp.customize.Messenger({ url: '<?php echo wp_customize_url(); /* WPCS: XSS OK. */ ?>', channel: 'login' }).send('login') }, 1000 );</script>
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
		$columns['two-factor'] = __( 'Two-Factor' );
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
		wp_enqueue_style( 'user-edit-2fa', plugins_url( 'user-edit.css', __FILE__ ) );

		$enabled_providers = array_keys( self::get_available_providers_for_user( $user->ID ) );
		$primary_provider = self::get_primary_provider_for_user( $user->ID );

		if ( ! empty( $primary_provider ) && is_object( $primary_provider ) ) {
			$primary_provider_key = get_class( $primary_provider );
		} else {
			$primary_provider_key = null;
		}

		wp_nonce_field( 'user_two_factor_options', '_nonce_user_two_factor_options', false );

		?>
		<input type="hidden" name="<?php echo esc_attr( self::ENABLED_PROVIDERS_USER_META_KEY ); ?>[]" value="<?php /* Dummy input so $_POST value is passed when no providers are enabled. */ ?>" />
		<table class="form-table">
			<tr>
				<th>
					<?php esc_html_e( 'Two-Factor Options' ); ?>
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
								<th scope="row"><input type="checkbox" name="<?php echo esc_attr( self::ENABLED_PROVIDERS_USER_META_KEY ); ?>[]" value="<?php echo esc_attr( $class ); ?>" <?php checked( in_array( $class, $enabled_providers ) ); ?> /></th>
								<th scope="row"><input type="radio" name="<?php echo esc_attr( self::PROVIDER_USER_META_KEY ); ?>" value="<?php echo esc_attr( $class ); ?>" <?php checked( $class, $primary_provider_key ); ?> /></th>
								<td>
									<?php $object->print_label(); ?>
									<?php do_action( 'two-factor-user-options-' . $class, $user ); ?>
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
	 * Check whether the current user requires two_factor or not.
	 *
	 * @return bool Whether user should be required to use 2fa.
	 */
	public static function is_two_factor_forced_for_current_user() {
		$id = get_current_user_id();
		return self::is_two_factor_forced( $id );
	}

	/**
	 * Check whether a user should have 2fa forced on or not.
	 *
	 * @param int $user_id User ID to check against.
	 * @return bool Whether user should be required to use 2fa.
	 */
	public static function is_two_factor_forced( int $user_id ) : bool {
		// If 2fa is forced for all users, always return true.
		if ( self::get_universally_forced_option() ) {
			return true;
		}

		$user = get_user_by( 'id', $user_id );

		// If we can't pull up user, escape.
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}

		// Check whether a user is in a user role that requires two-factor authentication.
		$two_factor_forced_roles = self::get_forced_user_roles();
		$required_roles = array_filter( $user->roles, function( $role ) use ( $two_factor_forced_roles ) {
			return in_array( $role, $two_factor_forced_roles, true );
		} , ARRAY_FILTER_USE_BOTH);

		// If the required_roles is not empty, then the user is in a role that requires two_factor authentication.
		return ! empty( $required_roles );
	}

	/**
	 * Get whether site has two-factor universally forced or not.
	 *
	 * @since 0.1-dev
	 *
	 * @return bool
	 */
	public static function get_universally_forced_option() {
		$is_forced = is_multisite() ? get_site_option( self::FORCED_SITE_META_KEY, false ) : get_option( self::FORCED_SITE_META_KEY, false );

		/**
		 * Whether or not site has two-factor universally forced.
		 *
		 * @param bool $is_forced Whether all users on a site are forced to use 2fa.
		 */
		return (bool) apply_filters( 'two_factor_universally_forced', $is_forced );
	}

	/**
	 * Get which user roles have two-factor forced.
	 *
	 * @since 0.1-dev
	 *
	 * @return array
	 */
	public static function get_forced_user_roles() {
		$roles = is_multisite() ? get_site_option( self::FORCED_ROLES_META_KEY, false ) : get_option( self::FORCED_ROLES_META_KEY, false );

		/**
		 * User roles which have two-factor forced.
		 *
		 * @param array $roles Roles which are required to use 2fa.
		 */
		return (array) apply_filters( 'two_factor_forced_user_roles', $roles );
	}

	/**
	 * Add network and site-level fields for forcing 2-factor on users of a role(s).
	 *
	 * @since 0.1-dev
	 */
	public static function force_two_factor_setting_options() {
		$forced_roles          = self::get_forced_user_roles();
		$is_universally_forced = self::get_universally_forced_option();
		?>

		<h2><?php esc_html_e( 'Two-Factor Options', 'two-factor' ); ?></h2>
		<table class="form-table">
			<?php wp_nonce_field( 'force_two_factor_options', '_nonce_force_two_factor_options', false ); ?>
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Universally force two-factor', 'two-factor' ); ?>
					</th>
					<td>
						<label>
							<input type='checkbox' name="<?php echo esc_attr( self::FORCED_SITE_META_KEY ); ?>" value="1" <?php checked( $is_universally_forced ); ?> />
							<?php esc_html_e( 'Force two-factor for all users', 'two-factor' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Force two-factor on specific roles', 'two-factor' ); ?></label>
					</th>
					<td>
						<?php foreach ( get_editable_roles() as $slug => $role ) : ?>
							<label>
								<input type='checkbox' name="<?php echo esc_attr( sprintf( '%s[%s]', self::FORCED_ROLES_META_KEY, $slug ) ); ?>" value="1" <?php checked( in_array( $slug, $forced_roles, true ) ); ?> <?php if ( $is_universally_forced ) { echo 'readonly'; } ?> />
								<?php echo esc_html( $role['name'] ); ?>
							</label>
							<br/>
						<?php endforeach; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save the force two_factor options against a network.
	 *
	 * @since 0.1-dev
	 */
	public static function save_network_force_two_factor_update() {
		if ( ! isset( $_POST['_nonce_force_two_factor_options'] ) ) {
			return;
		}

		check_admin_referer( 'force_two_factor_options', '_nonce_force_two_factor_options' );

		// Validate and save universally forced key.
		if ( isset( $_POST[ self::FORCED_SITE_META_KEY ] ) && $_POST[ self::FORCED_SITE_META_KEY ] ) {
			update_site_option( self::FORCED_SITE_META_KEY, 1 );
		} else {
			update_site_option( self::FORCED_SITE_META_KEY, 0 );
		}

		// Validate and save per-role settings.
		if ( ! isset( $_POST[ self::FORCED_ROLES_META_KEY ] ) ||
			! is_array( $_POST[ self::FORCED_ROLES_META_KEY ] ) ) {
			return;
		}

		// Whitelist roles against valid WordPress role slugs.
		$saved_roles = array_filter( $_POST[ self::FORCED_ROLES_META_KEY ], function( $is_role_saved, $role_slug ) {
			return $is_role_saved && in_array( $role_slug, array_keys( get_editable_roles() ), true );
		}, ARRAY_FILTER_USE_BOTH );

		update_site_option( self::FORCED_ROLES_META_KEY, array_keys( $saved_roles ) );
	}

	/**
	 * Check whether we're on main login page or not.
	 *
	 * @return bool
	 */
	public static function is_login_page() {
		return isset( $_SERVER['REQUEST_URI'] ) && strpos( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'wp-login.php' ) !== false;
	}
}
