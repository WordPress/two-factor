<?php
/**
 * Enrollment onboarding for enforced users.
 *
 * When enforcement uses the enrollment method, users in an enforced role who have not
 * configured a provider yet are sent to a dedicated setup screen and kept there until
 * they have enabled a second factor.
 *
 * @since 0.17
 *
 * @package Two_Factor
 */

/**
 * Class for the enrollment onboarding flow.
 *
 * @since 0.17
 */
class Two_Factor_Onboarding {

	/**
	 * The site option holding the enforcement method.
	 *
	 * @since 0.17
	 *
	 * @var string
	 */
	const ENFORCEMENT_METHOD_OPTION_KEY = 'two_factor_enforcement_method';

	/**
	 * Enforcement method that challenges unenrolled users with an email code.
	 *
	 * @since 0.17
	 *
	 * @var string
	 */
	const METHOD_EMAIL = 'email';

	/**
	 * Enforcement method that requires users to configure a provider themselves.
	 *
	 * @since 0.17
	 *
	 * @var string
	 */
	const METHOD_ENROLLMENT = 'enrollment';

	/**
	 * Slug of the setup screen.
	 *
	 * @since 0.17
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'two-factor-setup';

	/**
	 * Set up the hooks for the onboarding flow.
	 *
	 * @since 0.17
	 *
	 * @return void
	 */
	public static function add_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'add_setup_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_to_setup_page' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'render_pending_notice' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 10, 3 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'filter_rest_authentication_errors' ), 20 );
	}

	/**
	 * Get the configured enforcement method.
	 *
	 * @since 0.17
	 *
	 * @return string Either self::METHOD_EMAIL or self::METHOD_ENROLLMENT.
	 */
	public static function get_enforcement_method() {
		$method = get_option( self::ENFORCEMENT_METHOD_OPTION_KEY, self::METHOD_EMAIL );

		return self::METHOD_ENROLLMENT === $method ? self::METHOD_ENROLLMENT : self::METHOD_EMAIL;
	}

	/**
	 * Whether enforcement requires users to configure a provider themselves.
	 *
	 * @since 0.17
	 *
	 * @return bool
	 */
	public static function is_enrollment_required() {
		return self::METHOD_ENROLLMENT === self::get_enforcement_method();
	}

	/**
	 * Whether a user still has to complete the enrollment.
	 *
	 * @since 0.17
	 *
	 * @param int|WP_User|null $user Optional. User to check. Defaults to the current user.
	 * @return bool
	 */
	public static function is_user_pending( $user = null ) {
		$user = Two_Factor_Core::fetch_user( $user );

		if ( ! $user ) {
			return false;
		}

		$pending = self::is_enrollment_required()
			&& two_factor_user_has_enforced_role( $user )
			&& ! Two_Factor_Core::is_user_using_two_factor( $user->ID );

		/**
		 * Filter whether a user still has to complete the Two-Factor enrollment.
		 *
		 * @since 0.17
		 *
		 * @param bool    $pending Whether the enrollment is still pending.
		 * @param WP_User $user    The user being checked.
		 */
		return (bool) apply_filters( 'two_factor_onboarding_is_user_pending', $pending, $user );
	}

	/**
	 * Get the URL of the setup screen.
	 *
	 * @since 0.17
	 *
	 * @return string
	 */
	public static function get_setup_page_url() {
		return add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'index.php' ) );
	}

	/**
	 * Register the setup screen.
	 *
	 * The screen is listed under Dashboard while the enrollment is pending, so that users
	 * who navigate away can find their way back to it.
	 *
	 * @since 0.17
	 *
	 * @return void
	 */
	public static function add_setup_page() {
		if ( ! self::is_user_pending() ) {
			return;
		}

		$hook = add_submenu_page(
			'index.php',
			__( 'Two-Factor Setup', 'two-factor' ),
			__( 'Two-Factor Setup', 'two-factor' ),
			'read',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_setup_page' )
		);

		add_action( 'load-' . $hook, array( __CLASS__, 'handle_setup_page_submit' ) );
		add_action( 'admin_head-' . $hook, array( __CLASS__, 'print_setup_page_styles' ) );
	}

	/**
	 * Whether the current request is the setup screen.
	 *
	 * @since 0.17
	 *
	 * @return bool
	 */
	public static function is_setup_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the requested screen, not processing input.
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Save the provider selection made on the setup screen.
	 *
	 * The providers themselves are configured through their own REST endpoints; this only
	 * persists which of them the user turned on, the same way the profile screen does.
	 *
	 * @since 0.17
	 *
	 * @return void
	 */
	public static function handle_setup_page_submit() {
		if ( ! isset( $_POST['two_factor_setup_submit'] ) ) {
			return;
		}

		check_admin_referer( 'two_factor_setup', 'two_factor_setup_nonce' );

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		Two_Factor_Core::user_two_factor_options_update( $user_id );

		$redirect = self::is_user_pending( $user_id ) ? self::get_setup_page_url() : admin_url();

		wp_safe_redirect( add_query_arg( 'two-factor-setup', self::is_user_pending( $user_id ) ? 'incomplete' : 'complete', $redirect ) );
		exit;
	}

	/**
	 * Render the setup screen.
	 *
	 * @since 0.17
	 *
	 * @return void
	 */
	public static function render_setup_page() {
		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			return;
		}

		?>
		<div class="wrap two-factor-setup">
			<h1><?php esc_html_e( 'Set up Two-Factor Authentication', 'two-factor' ); ?></h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			if ( isset( $_GET['two-factor-setup'] ) && 'incomplete' === sanitize_key( wp_unslash( $_GET['two-factor-setup'] ) ) ) :
				?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Your settings were saved, but no method is active yet. Configure a method below and enable it to finish.', 'two-factor' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="two-factor-setup-intro">
				<?php esc_html_e( 'This site requires a second factor in addition to your password. Set one up here to continue; the rest of the admin becomes available as soon as a method is active.', 'two-factor' ); ?>
			</p>

			<ol class="two-factor-setup-steps">
				<li><?php esc_html_e( 'Configure one of the methods below. The authenticator app asks you to scan a QR code with an app on your phone.', 'two-factor' ); ?></li>
				<li><?php esc_html_e( 'Generate backup codes and store them somewhere safe, so you can still sign in if you lose access to your primary method.', 'two-factor' ); ?></li>
				<li><?php esc_html_e( 'Enable the method you configured and save.', 'two-factor' ); ?></li>
			</ol>

			<form method="post" action="<?php echo esc_url( self::get_setup_page_url() ); ?>">
				<?php
				wp_nonce_field( 'two_factor_setup', 'two_factor_setup_nonce' );
				Two_Factor_Core::user_two_factor_options( $user );
				submit_button( __( 'Save and continue', 'two-factor' ), 'primary', 'two_factor_setup_submit' );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Print the styles for the setup screen.
	 *
	 * The admin menu is hidden while the enrollment is pending: every other screen redirects
	 * back here, so leaving the navigation in place would only lead to dead ends.
	 *
	 * @since 0.17
	 *
	 * @return void
	 */
	public static function print_setup_page_styles() {
		?>
		<style>
			#adminmenumain,
			#screen-meta,
			#screen-meta-links,
			#wpfooter {
				display: none;
			}

			#wpcontent,
			#wpbody-content {
				margin-left: 0;
				padding-left: 20px;
			}

			.two-factor-setup {
				max-width: 46em;
			}

			.two-factor-setup-steps {
				margin-bottom: 2em;
				line-height: 1.6;
			}
		</style>
		<?php
	}

	/**
	 * Send users with a pending enrollment to the setup screen after they log in.
	 *
	 * @since 0.17
	 *
	 * @param string           $redirect_to           The redirect destination URL.
	 * @param string           $requested_redirect_to The requested redirect destination URL.
	 * @param WP_User|WP_Error $user                  The logged-in user, or an error object.
	 * @return string
	 */
	public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! $user instanceof WP_User || ! self::is_user_pending( $user ) ) {
			return $redirect_to;
		}

		return self::get_setup_page_url();
	}

	/**
	 * Keep users with a pending enrollment on the setup screen.
	 *
	 * @since 0.17
	 *
	 * @return void
	 */
	public static function redirect_to_setup_page() {
		if ( wp_doing_ajax() || ! self::is_user_pending() || self::is_allowed_screen() ) {
			return;
		}

		wp_safe_redirect( self::get_setup_page_url() );
		exit;
	}

	/**
	 * Whether the current admin screen stays reachable during a pending enrollment.
	 *
	 * Besides the setup screen itself, the profile screen remains available because that is
	 * where the Two-Factor options normally live, together with the endpoints the providers
	 * post to while they are being configured.
	 *
	 * @since 0.17
	 *
	 * @return bool
	 */
	public static function is_allowed_screen() {
		if ( self::is_setup_page() ) {
			return true;
		}

		$allowed = array( 'profile.php', 'admin-ajax.php', 'admin-post.php', 'options.php' );

		/**
		 * Filter the admin screens that remain reachable during a pending enrollment.
		 *
		 * @since 0.17
		 *
		 * @param string[] $allowed Script names, relative to the admin directory.
		 */
		$allowed = (array) apply_filters( 'two_factor_onboarding_allowed_screens', $allowed );

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';

		return in_array( $script, $allowed, true );
	}

	/**
	 * Explain on the other reachable screens why the admin is not available yet.
	 *
	 * @since 0.17
	 *
	 * @return void
	 */
	public static function render_pending_notice() {
		if ( ! self::is_user_pending() || self::is_setup_page() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%1$s <a class="button button-primary" href="%2$s">%3$s</a></p></div>',
			esc_html__( 'This site requires Two-Factor authentication. The admin stays unavailable until you have set it up.', 'two-factor' ),
			esc_url( self::get_setup_page_url() ),
			esc_html__( 'Set up now', 'two-factor' )
		);
	}

	/**
	 * Refuse REST requests from users with a pending enrollment.
	 *
	 * The plugin's own endpoints stay available: they are how the enrollment is completed.
	 *
	 * @since 0.17
	 *
	 * @param WP_Error|null|true $errors The current authentication result.
	 * @return WP_Error|null|true
	 */
	public static function filter_rest_authentication_errors( $errors ) {
		if ( ! empty( $errors ) || ! is_user_logged_in() || ! self::is_user_pending() ) {
			return $errors;
		}

		if ( self::is_two_factor_rest_route() ) {
			return $errors;
		}

		return new WP_Error(
			'two_factor_enrollment_required',
			__( 'Two-Factor authentication must be set up before the REST API can be used.', 'two-factor' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Whether the current REST request targets one of the plugin's own routes.
	 *
	 * @since 0.17
	 *
	 * @return bool
	 */
	private static function is_two_factor_rest_route() {
		$route = '';

		if ( isset( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$route = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		}

		return false !== strpos( $route, Two_Factor_Core::REST_NAMESPACE . '/' );
	}
}
