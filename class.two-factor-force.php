<?php
/**
 * Class for handling 2fa forcing.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_Force {
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
	 * Set up filters and actions.
	 *
	 * @since 0.1-dev
	 */
	public static function add_hooks() {
		// Forced 2fa login functionality.
		add_action( 'init', array( __CLASS__, 'register_scripts' ) );
		add_action( 'wp_ajax_two_factor_force_form_submit', array( __CLASS__, 'handle_force_2fa_submission' ) );

		// Handling intercession in 2 separate hooks to allow us to properly parse for REST requests.
		add_filter( 'login_redirect', array( __CLASS__, 'maybe_redirect_login' ), 15, 3 );
		add_action( 'parse_request', array( __CLASS__, 'maybe_redirect_to_2fa_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_2fa_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_display_2fa_settings' ) );

		if ( is_multisite() ) {
			add_action( 'wpmu_options', array( __CLASS__, 'force_two_factor_setting_options' ) );
			add_action( 'update_wpmu_options', array( __CLASS__, 'save_network_force_two_factor_update' ) );
		} else {
			add_action( 'admin_init', array( __CLASS__, 'register_single_site_force_2fa_options' ) );
		}
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
	 * Redirect a user to the 2fa login screen with redirect parameters.
	 *
	 * If a user must have 2fa enabled, we need to send them to the 2fa settings
	 * takeover. However, we also need to pass in the redirect_to information to
	 * ensure that the user lands in the right place.
	 *
	 * @param string           $redirect_to           The redirect destination URL.
	 * @param string           $requested_redirect_to The requested redirect destination URL passed as a parameter.
	 * @param WP_User|WP_Error $user                  WP_User object if login was successful, WP_Error object otherwise.
	 * @return string
	 */
	public static function maybe_redirect_login( $redirect_to, $requested_redirect_to, $user ) {
		// If login has failed, do nothing.
		if ( $user instanceof WP_Error ) {
			return $redirect_to;
		}

		// Check if redirect is necessary for user.
		if ( ! self::should_user_redirect( $user->ID ) ) {
			return $redirect_to;
		};

		// Append redirect_to URL.
		return add_query_arg(
			[
				'force_2fa_screen' => 1,
				'redirect_to'      => rawurlencode( $requested_redirect_to ),
			],
			admin_url()
		);
	}

	/**
	 * Maybe force the 2fa login page on a user.
	 *
	 * If 2fa is required for a user (based on universal or role settings),
	 * we display the 2-factor options page so that a user must validly enable
	 * a 2-factor authentication of some kind to perform any action on their site.
	 * This occurs both on the front and backend.
	 */
	public static function maybe_redirect_to_2fa_settings() {
		if ( ! self::should_user_redirect( get_current_user_id() ) || isset( $_GET['force_2fa_screen'] ) ) {
			return;
		}

		// We are now forced to display the two-factor settings page.
		wp_safe_redirect( add_query_arg(
			'force_2fa_screen',
			1,
			admin_url()
		) );
		exit;
	}

	/**
	 * On front and backend requests, display
	 */
	public static function maybe_display_2fa_settings() {
		// phpcs:ignore We are validating that the value exists and are not processing it.
		if ( ! isset( $_GET['force_2fa_screen'] ) || ! $_GET['force_2fa_screen'] ) {
			return;
		}

		if ( ! self::should_user_redirect( get_current_user_id() ) ) {
			$url = admin_url();

			if ( isset( $_GET['redirect_to'] ) ) {
				// phpcs:ignore This IS the proper sanitization for URLs.
				$url = esc_url_raw( urldecode( $_GET['redirect_to'] ) );
			}

			wp_safe_redirect( $url );
			exit;
		}

		self::force_2fa_login_html();
		exit;
	}

	/**
	 * Check whether or not a user should be redirected to the force 2fa screen.
	 *
	 * @param int $user_id ID of the user to check against.
	 * @return bool Whether or not user should be forced to 2fa screen.
	 */
	public static function should_user_redirect( $user_id ) {
		// This should not affect AJAX or REST requests, carry on.
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		// Should not interrupt logging in or out.
		if ( self::is_login_page() ) {
			return false;
		}

		// If user is not logged in, they can't 2fa anyway.
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// 2fa is not forced for current user, nothing to show.
		if ( ! self::is_two_factor_forced( $user_id ) ) {
			return false;
		}

		// The current user is already using two-factor, good for them!
		if ( Two_Factor_Core::is_user_using_two_factor() ) {
			return false;
		}

		return true;
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

		// If Fido is a valid 2fa Provider, enqueue its assets.
		$providers = Two_Factor_Core::get_providers();
		if ( in_array( 'Two_Factor_FIDO_U2F', array_keys( $providers ), true ) ) {
			Two_Factor_FIDO_U2F_Admin::enqueue_assets( 'profile.php' );
			wp_enqueue_style( 'common' );
			wp_enqueue_style( 'list-tables' );
		}

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
			<?php Two_Factor_Core::user_two_factor_options( $user ); ?>
			<button class="button button-primary"><?php esc_html_e( 'Submit' ); ?></button>
		</form>

		<p id="backtoblog">
			<a href="<?php echo esc_url( wp_logout_url() ); ?>" title="<?php esc_attr_e( 'Logout of your account', 'two-factor' ); ?>">
				<?php esc_html_e( '&larr; Logout', 'two-factor' ); ?>
			</a>
		</p>

		<script type="text/javascript">
			var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php', 'relative' ) ); ?>';
		</script>

		<?php
		/** This action is documented in wp-login.php */
		do_action( 'login_footer' );
		?>
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

		/**
		 * Save 2fa options against a user from AJAX submission.
		 *
		 * Providers can use this hook to save their own data.
		 *
		 * @param int $user_id User ID that we're saving against.
		 */
		do_action( 'two_factor_ajax_options_update', get_current_user_id() );

		wp_send_json_success();
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
	public static function is_two_factor_forced( $user_id ) {
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
		$required_roles          = array_filter( $user->roles, function( $role ) use ( $two_factor_forced_roles ) {
			return in_array( $role, $two_factor_forced_roles, true );
		}, ARRAY_FILTER_USE_BOTH );

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
		/**
		 * Whether or not site has two-factor universally forced.
		 *
		 * @param bool $is_forced Whether all users on a site are forced to use 2fa.
		 */
		return (bool) apply_filters( 'two_factor_universally_forced', get_site_option( self::FORCED_SITE_META_KEY, false ) );
	}

	/**
	 * Get which user roles have two-factor forced.
	 *
	 * @since 0.1-dev
	 *
	 * @return array
	 */
	public static function get_forced_user_roles() {
		/**
		 * User roles which have two-factor forced.
		 *
		 * @param array $roles Roles which are required to use 2fa.
		 */
		return (array) apply_filters( 'two_factor_forced_user_roles', get_site_option( self::FORCED_ROLES_META_KEY, false ) );
	}

	/**
	 * Add network and site-level fields for forcing 2-factor on users of a role(s).
	 *
	 * @since 0.1-dev
	 */
	public static function force_two_factor_setting_options() {
		?>
		<h2><?php esc_html_e( 'Two-Factor Options', 'two-factor' ); ?></h2>
		<table class="form-table">
			<?php wp_nonce_field( 'force_two_factor_options', '_nonce_force_two_factor_options', false ); ?>
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Force all users to enable two-factor', 'two-factor' ); ?>
					</th>
					<td>
						<?php self::global_force_2fa_field(); ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Force two-factor on specific roles', 'two-factor' ); ?></label>
					</th>
					<td>
						<?php self::global_force_2fa_by_role_field(); ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * HTML output for global force 2fa field.
	 */
	public static function global_force_2fa_field() {
		$is_universally_forced = self::get_universally_forced_option();

		?>
		<label>
			<input type='checkbox' name="<?php echo esc_attr( self::FORCED_SITE_META_KEY ); ?>" value="1" <?php checked( $is_universally_forced ); ?> />
			<?php esc_html_e( 'Force two-factor for all users', 'two-factor' ); ?>
		</label>
		<?php
	}

	/**
	 * HTML output for per-role force 2fa fields.
	 */
	public static function global_force_2fa_by_role_field() {
		$forced_roles          = self::get_forced_user_roles();
		$is_universally_forced = self::get_universally_forced_option();

		foreach ( get_editable_roles() as $slug => $role ) :
			?>
			<label>
				<input type='checkbox' name="<?php echo esc_attr( sprintf( '%s[%s]', self::FORCED_ROLES_META_KEY, $slug ) ); ?>" value="1" <?php checked( in_array( $slug, $forced_roles, true ) ); ?> <?php echo ( $is_universally_forced ) ? 'readonly' : ''; ?> />
				<?php echo esc_html( $role['name'] ); ?>
			</label>
			<br/>
			<?php
		endforeach;
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
		// phpcs:ignore The value from $_POST is not saved, only 1 or 0 can be.
		if ( isset( $_POST[ self::FORCED_SITE_META_KEY ] ) && $_POST[ self::FORCED_SITE_META_KEY ] ) {
			update_site_option( self::FORCED_SITE_META_KEY, 1 );
		} else {
			update_site_option( self::FORCED_SITE_META_KEY, 0 );
		}

		// Validate and save per-role settings.
		if (
			! isset( $_POST[ self::FORCED_ROLES_META_KEY ] ) ||
			! is_array( $_POST[ self::FORCED_ROLES_META_KEY ] )
		) {
			return;
		}

		// Whitelist roles against valid WordPress role slugs.
		// phpcs:ignore Our validation method below only accepts whitelisted strings from `editable_roles`.
		$roles = self::validate_forced_roles( $_POST[ self::FORCED_ROLES_META_KEY ] );

		update_site_option( self::FORCED_ROLES_META_KEY, $roles );
	}

	/**
	 * Validate and whitelist role values against valid editable_roles.
	 *
	 * @param array $unsafe_roles Values to validate and save.
	 * @return array Whitelisted and safe values.
	 */
	public static function validate_forced_roles( $unsafe_roles ) {
		$safe_roles = array_filter( wp_unslash( $unsafe_roles ), function( $is_role_saved, $role_slug ) {
			return $is_role_saved && in_array( $role_slug, array_keys( get_editable_roles() ), true );
		}, ARRAY_FILTER_USE_BOTH );

		return array_keys( $safe_roles );
	}

	/**
	 * Register settings for a single site.
	 */
	public static function register_single_site_force_2fa_options() {
		// Add a new setting group for forcing 2fa in General Options.
		add_settings_section(
			'two-factor-force-2fa',
			esc_html__( 'Two-Factor Options', 'two-factor' ),
			'__return_null',
			'general'
		);

		// Add global force 2fa field.
		add_settings_field(
			self::FORCED_SITE_META_KEY,
			esc_html__( 'Force all users to enable two-factor', 'two-factor' ),
			array( __CLASS__, 'global_force_2fa_field' ),
			'general',
			'two-factor-force-2fa'
		);

		register_setting(
			'general',
			self::FORCED_SITE_META_KEY,
			[
				'type' => 'boolean',
			]
		);

		// Add per-role force 2fa field.
		add_settings_field(
			self::FORCED_ROLES_META_KEY,
			esc_html__( 'Force two-factor on specific roles', 'two-factor' ),
			array( __CLASS__, 'global_force_2fa_by_role_field' ),
			'general',
			'two-factor-force-2fa'
		);

		register_setting(
			'general',
			self::FORCED_ROLES_META_KEY,
			array( __CLASS__, 'validate_forced_roles' )
		);
	}

	/**
	 * Check whether we're on main login page or not.
	 *
	 * Why is this not in core yet?
	 *
	 * @return bool
	 */
	public static function is_login_page() {
		return isset( $_SERVER['REQUEST_URI'] ) && strpos( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'wp-login.php' ) !== false;
	}
}
