<?php
/**
 * Test the network-wide Two-Factor settings on multisite.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Settings_Multisite
 *
 * @package Two_Factor
 * @group settings
 * @group multisite
 */
class Tests_Two_Factor_Settings_Multisite extends WP_UnitTestCase {

	/**
	 * Network super admin user ID.
	 *
	 * @var int
	 */
	protected static $super_admin_id;

	/**
	 * User ID of the sub-site administrator, who has no network privileges.
	 *
	 * @var int
	 */
	protected static $site_admin_id;

	/**
	 * User ID of an editor on the sub-site.
	 *
	 * @var int
	 */
	protected static $member_id;

	/**
	 * Sub-site ID.
	 *
	 * @var int
	 */
	protected static $blog_id;

	/**
	 * Set up test fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory instance.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		if ( ! is_multisite() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		self::$super_admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( self::$super_admin_id );

		self::$site_admin_id = $factory->user->create();
		self::$blog_id       = $factory->blog->create( array( 'user_id' => self::$site_admin_id ) );

		self::$member_id = $factory->user->create();
		add_user_to_blog( self::$blog_id, self::$member_id, 'editor' );
	}

	/**
	 * Clean up test fixtures.
	 */
	public static function wpTearDownAfterClass() {
		if ( ! is_multisite() ) {
			return;
		}

		revoke_super_admin( self::$super_admin_id );
		wp_delete_site( self::$blog_id );
	}

	/**
	 * Skip on single site.
	 */
	public function set_up() {
		parent::set_up();

		$this->skipWithoutMultisite();
	}

	/**
	 * Clean up the simulated request after each test.
	 */
	public function tear_down() {
		unset(
			$_GET['page'],
			$_GET['updated'],
			$_GET['error'],
			$_POST['two_factor_settings_submit'],
			$_POST['two_factor_settings_nonce'],
			$_POST['two_factor_enabled_providers'],
			$_REQUEST['two_factor_settings_nonce']
		);

		parent::tear_down();
	}

	/**
	 * Configure TOTP as the only provider for a user.
	 *
	 * @param int $user_id User ID.
	 */
	private function enable_totp_only( $user_id ) {
		Two_Factor_Totp::get_instance()->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );
		update_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Totp' );
	}

	/**
	 * Configure both emailed codes and TOTP for a user.
	 *
	 * @param int $user_id User ID.
	 */
	private function enable_email_and_totp( $user_id ) {
		Two_Factor_Totp::get_instance()->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email', 'Two_Factor_Totp' ) );
	}

	/**
	 * Populate the request with a settings form submission for the current user.
	 *
	 * @param string[] $providers Provider keys to submit.
	 */
	private function set_settings_form_post( array $providers ) {
		$nonce = wp_create_nonce( 'two_factor_save_settings' );

		$_POST['two_factor_settings_submit']   = 'Save Settings';
		$_POST['two_factor_settings_nonce']    = $nonce;
		$_REQUEST['two_factor_settings_nonce'] = $nonce;
		$_POST['two_factor_enabled_providers'] = $providers;
	}

	/**
	 * Submit the settings form to the settings page callback and return its output.
	 *
	 * @param string[] $providers Provider keys to submit.
	 * @return string
	 */
	private function submit_settings_page( array $providers ) {
		$this->set_settings_form_post( $providers );

		ob_start();

		try {
			two_factor_render_settings_page();
		} finally {
			$output = ob_get_clean();
		}

		return $output;
	}

	/**
	 * Run a callback that redirects and exits, and return the redirect location.
	 *
	 * @param callable $callback Code that calls wp_safe_redirect() and exits.
	 * @return string|null The redirect location, or null if there was no redirect.
	 */
	private function capture_redirect( callable $callback ) {
		$redirect_filter = static function ( $location ) {
			throw new Two_Factor_Redirect_Exception( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};

		add_filter( 'wp_redirect', $redirect_filter, PHP_INT_MAX );

		try {
			$callback();
		} catch ( Two_Factor_Redirect_Exception $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter, PHP_INT_MAX );
		}

		return null;
	}

	/**
	 * Run a callback that is expected to call wp_die().
	 *
	 * @param callable $callback Code expected to die.
	 * @return bool Whether wp_die() was called.
	 */
	private function did_die( callable $callback ) {
		try {
			$this->capture_redirect( $callback );
		} catch ( WPDieException $e ) {
			return true;
		}

		return false;
	}

	/**
	 * Register settings pages and return the capability of each page under a parent menu.
	 *
	 * @param string   $parent_slug Parent menu slug.
	 * @param callable $register    Callback that registers the pages.
	 * @return string[] Capabilities indexed by page slug.
	 */
	private function get_registered_page_capabilities( $parent_slug, callable $register ) {
		global $submenu, $_registered_pages, $_parent_pages;

		$backup = array( $submenu, $_registered_pages, $_parent_pages );

		try {
			$register();

			return wp_list_pluck( isset( $submenu[ $parent_slug ] ) ? $submenu[ $parent_slug ] : array(), 1, 2 );
		} finally {
			list( $submenu, $_registered_pages, $_parent_pages ) = $backup;
		}
	}

	/**
	 * Values saved per site before the setting became network-wide are ignored.
	 *
	 * @covers Two_Factor_Core::get_enabled_providers_policy
	 * @covers ::two_factor_filter_enabled_providers
	 */
	public function test_legacy_subsite_value_is_ignored() {
		$this->enable_totp_only( self::$super_admin_id );
		update_blog_option( self::$blog_id, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		switch_to_blog( self::$blog_id );
		$provider = Two_Factor_Core::get_primary_provider_for_user( self::$super_admin_id );
		restore_current_blog();

		$this->assertInstanceOf( Two_Factor_Totp::class, $provider, 'The per-site value does not change the provider on the sub-site' );
	}

	/**
	 * A sub-site administrator submitting the per-site settings form changes nothing.
	 *
	 * @covers Two_Factor_Settings::render_network_managed_page
	 * @covers ::two_factor_render_settings_page
	 */
	public function test_subsite_admin_cannot_change_policy() {
		$this->enable_totp_only( self::$super_admin_id );

		switch_to_blog( self::$blog_id );
		wp_set_current_user( self::$site_admin_id );

		$this->assertTrue( current_user_can( 'manage_options' ), 'The site administrator manages the sub-site options' );

		$output   = $this->submit_settings_page( array( 'Two_Factor_Email' ) );
		$policy   = two_factor_get_enabled_providers_option();
		$provider = Two_Factor_Core::get_primary_provider_for_user( self::$super_admin_id );

		restore_current_blog();

		$this->assertStringNotContainsString( 'Settings saved.', $output, 'Nothing was saved' );
		$this->assertNull( $policy, 'No provider restriction applies on the sub-site' );
		$this->assertInstanceOf( Two_Factor_Totp::class, $provider, 'The provider used on the sub-site is unchanged' );
		$this->assertNull(
			get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, null ),
			'No network option was written'
		);
	}

	/**
	 * A sub-site administrator can't save the network-wide settings.
	 *
	 * @covers Two_Factor_Settings::save_network_settings
	 */
	public function test_subsite_admin_cannot_save_network_settings() {
		switch_to_blog( self::$blog_id );
		wp_set_current_user( self::$site_admin_id );
		$this->set_settings_form_post( array( 'Two_Factor_Email' ) );
		restore_current_blog();

		$this->assertTrue(
			$this->did_die( array( 'Two_Factor_Settings', 'save_network_settings' ) ),
			'Saving was refused'
		);
		$this->assertNull(
			get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, null ),
			'No network option was written'
		);
	}

	/**
	 * Saving the network-wide settings requires a valid nonce.
	 *
	 * @covers Two_Factor_Settings::save_network_settings
	 */
	public function test_network_settings_require_valid_nonce() {
		wp_set_current_user( self::$super_admin_id );
		$this->set_settings_form_post( array( 'Two_Factor_Email' ) );

		$_POST['two_factor_settings_nonce']    = 'invalid';
		$_REQUEST['two_factor_settings_nonce'] = 'invalid';

		$this->assertTrue(
			$this->did_die( array( 'Two_Factor_Settings', 'save_network_settings' ) ),
			'Saving was refused'
		);
		$this->assertNull(
			get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, null ),
			'No network option was written'
		);
	}

	/**
	 * Saving the network-wide settings with no provider enabled is refused.
	 *
	 * @covers Two_Factor_Settings::save_network_settings
	 */
	public function test_network_settings_refuse_empty_selection() {
		update_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp' ) );
		wp_set_current_user( self::$super_admin_id );

		$expected_redirect = add_query_arg(
			array(
				'page'  => 'two-factor-settings',
				'error' => 'no_providers',
			),
			network_admin_url( 'settings.php' )
		);

		// Nothing ticked: browsers don't submit the field at all.
		$this->set_settings_form_post( array() );
		unset( $_POST['two_factor_enabled_providers'] );

		$this->assertSame( $expected_redirect, $this->capture_redirect( array( 'Two_Factor_Settings', 'save_network_settings' ) ), 'An empty selection is refused' );

		// Only keys that aren't registered providers.
		$this->set_settings_form_post( array( 'Not_A_Provider' ) );

		$this->assertSame( $expected_redirect, $this->capture_redirect( array( 'Two_Factor_Settings', 'save_network_settings' ) ), 'A selection without registered providers is refused' );
		$this->assertSame(
			array( 'Two_Factor_Totp' ),
			get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ),
			'The network setting is unchanged'
		);
	}

	/**
	 * Only registered providers are saved, including ones the current setting disables.
	 *
	 * @covers Two_Factor_Settings::save_network_settings
	 */
	public function test_network_settings_save_only_registered_providers() {
		// TOTP is currently disabled, so it isn't among the providers in effect.
		update_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		wp_set_current_user( self::$super_admin_id );
		$this->set_settings_form_post( array( 'Two_Factor_Totp', 'Not_A_Provider', 'Two_Factor_Backup_Codes' ) );

		$this->capture_redirect( array( 'Two_Factor_Settings', 'save_network_settings' ) );

		$this->assertSame(
			array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ),
			get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ),
			'Unknown keys are dropped, and a disabled provider can be enabled again'
		);
		$this->assertNotFalse(
			has_filter( 'two_factor_providers', 'two_factor_filter_enabled_providers' ),
			'The setting is enforced again after saving'
		);
	}

	/**
	 * The network-wide setting applies on every site.
	 *
	 * @covers Two_Factor_Core::get_enabled_providers_policy
	 * @covers ::two_factor_filter_enabled_providers
	 * @covers ::two_factor_filter_enabled_providers_for_user
	 */
	public function test_network_policy_applies_on_every_site() {
		$this->enable_email_and_totp( self::$member_id );
		update_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ) );

		foreach ( array( get_main_site_id(), self::$blog_id ) as $blog_id ) {
			switch_to_blog( $blog_id );
			$enabled   = Two_Factor_Core::get_enabled_providers_for_user( self::$member_id );
			$available = Two_Factor_Core::get_available_providers_for_user( self::$member_id );
			restore_current_blog();

			$this->assertNotContains( 'Two_Factor_Email', $enabled, "Emailed codes are not enabled on site {$blog_id}" );
			$this->assertSame( array( 'Two_Factor_Totp' ), array_keys( $available ), "Only TOTP is available on site {$blog_id}" );
		}
	}

	/**
	 * A super admin saves the network-wide setting and it applies on every site.
	 *
	 * @covers Two_Factor_Settings::save_network_settings
	 * @covers Two_Factor_Core::update_enabled_providers_policy
	 */
	public function test_super_admin_can_set_network_policy() {
		$this->enable_email_and_totp( self::$member_id );

		wp_set_current_user( self::$super_admin_id );
		$this->set_settings_form_post( array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ) );

		$redirect = $this->capture_redirect( array( 'Two_Factor_Settings', 'save_network_settings' ) );

		$this->assertSame(
			add_query_arg(
				array(
					'page'    => 'two-factor-settings',
					'updated' => 'true',
				),
				network_admin_url( 'settings.php' )
			),
			$redirect,
			'Redirects back to the network settings screen'
		);
		$this->assertSame(
			array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ),
			get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ),
			'The network option was written'
		);

		foreach ( array( get_main_site_id(), self::$blog_id ) as $blog_id ) {
			switch_to_blog( $blog_id );
			$enabled = Two_Factor_Core::get_enabled_providers_for_user( self::$member_id );
			restore_current_blog();

			$this->assertSame( array( 'Two_Factor_Totp' ), array_values( $enabled ), "The setting applies on site {$blog_id}" );
		}
	}

	/**
	 * The main site's saved value is carried over to the network setting once.
	 *
	 * @covers Two_Factor_Core::get_enabled_providers_policy
	 */
	public function test_main_site_value_is_migrated_once() {
		delete_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_MIGRATED_OPTION_KEY );
		update_blog_option( get_main_site_id(), Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ) );
		update_blog_option( self::$blog_id, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		// The first read happens on the sub-site, which must still use the main site's value.
		switch_to_blog( self::$blog_id );
		$policy = Two_Factor_Core::get_enabled_providers_policy();
		restore_current_blog();

		$this->assertSame( array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ), $policy, 'The main site value is carried over' );
		$this->assertSame( $policy, get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ), 'The value was saved as the network setting' );

		update_blog_option( get_main_site_id(), Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$this->assertSame(
			array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ),
			Two_Factor_Core::get_enabled_providers_policy(),
			'Later per-site changes are ignored'
		);

		delete_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );

		$this->assertNull(
			Two_Factor_Core::get_enabled_providers_policy(),
			'Clearing the network setting does not bring back the per-site value'
		);
	}

	/**
	 * Without a value on the main site there is nothing to carry over, now or later.
	 *
	 * @covers Two_Factor_Core::get_enabled_providers_policy
	 */
	public function test_migration_without_main_site_value_runs_once() {
		delete_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_MIGRATED_OPTION_KEY );

		$this->assertNull( Two_Factor_Core::get_enabled_providers_policy(), 'All providers are allowed' );

		update_blog_option( get_main_site_id(), Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$this->assertNull( Two_Factor_Core::get_enabled_providers_policy(), 'A per-site value saved later is ignored' );
	}

	/**
	 * Saving the network setting completes the migration.
	 *
	 * @covers Two_Factor_Core::update_enabled_providers_policy
	 */
	public function test_saving_network_setting_completes_migration() {
		delete_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_MIGRATED_OPTION_KEY );
		update_blog_option( get_main_site_id(), Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		Two_Factor_Core::update_enabled_providers_policy( array( 'Two_Factor_Totp' ) );
		delete_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );

		$this->assertNull( Two_Factor_Core::get_enabled_providers_policy(), 'The main site value is not carried over afterwards' );
	}

	/**
	 * Only network administrators can manage the setting.
	 *
	 * @covers Two_Factor_Core::policy_capability
	 */
	public function test_policy_capability_is_network_wide() {
		$this->assertSame( 'manage_network_options', Two_Factor_Core::policy_capability() );
	}

	/**
	 * The settings page is registered in the network admin, and sites get the read-only page.
	 *
	 * @covers ::two_factor_register_admin_hooks
	 * @covers ::two_factor_add_network_settings_page
	 * @covers ::two_factor_add_settings_page
	 */
	public function test_settings_pages_are_registered() {
		set_current_screen( 'dashboard-network' );
		wp_set_current_user( self::$super_admin_id );

		two_factor_register_admin_hooks();

		$this->assertNotFalse( has_action( 'network_admin_menu', 'two_factor_add_network_settings_page' ), 'Network admin page is registered' );
		$this->assertNotFalse(
			has_action( 'network_admin_edit_two_factor_settings', array( 'Two_Factor_Settings', 'save_network_settings' ) ),
			'Network admin save handler is registered'
		);
		$this->assertNotFalse( has_action( 'admin_menu', 'two_factor_add_settings_page' ), 'Read-only site page is registered' );

		$network_pages = $this->get_registered_page_capabilities( 'settings.php', 'two_factor_add_network_settings_page' );
		$site_pages    = $this->get_registered_page_capabilities( 'options-general.php', 'two_factor_add_settings_page' );

		$this->assertArrayHasKey( 'two-factor-settings', $network_pages );
		$this->assertSame( 'manage_network_options', $network_pages['two-factor-settings'] );
		$this->assertArrayHasKey( 'two-factor-settings', $site_pages );
		$this->assertSame( 'manage_options', $site_pages['two-factor-settings'] );
	}

	/**
	 * Sites show the network-wide setting read-only, and submitting the page saves nothing.
	 *
	 * @covers ::two_factor_render_settings_page
	 * @covers Two_Factor_Settings::render_network_managed_page
	 */
	public function test_site_settings_page_is_read_only() {
		update_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp' ) );

		switch_to_blog( self::$blog_id );
		wp_set_current_user( self::$site_admin_id );
		set_current_screen( 'settings_page_two-factor-settings' );

		$output      = $this->submit_settings_page( array( 'Two_Factor_Email' ) );
		$site_option = get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );

		restore_current_blog();

		$this->assertStringContainsString( 'managed for all sites in this network', $output, 'Explains where the setting is managed' );
		$this->assertStringNotContainsString( '<form', $output, 'There is no form' );
		$this->assertStringNotContainsString( 'name="two_factor_enabled_providers[]"', $output, 'The checkboxes are not submitted' );
		$this->assertMatchesRegularExpression( '/id="provider_Two_Factor_Totp"[^>]*checked=\'checked\'[^>]*disabled=\'disabled\'/', $output, 'TOTP is shown as enabled' );
		$this->assertMatchesRegularExpression( '/id="provider_Two_Factor_Email"(?![^>]*checked)[^>]*disabled=\'disabled\'/', $output, 'Emailed codes are shown as disabled' );
		$this->assertStringNotContainsString( 'page=two-factor-settings', $output, 'Site administrators get no link to the network settings' );
		$this->assertFalse( $site_option, 'Nothing was saved for the site' );
		$this->assertSame(
			array( 'Two_Factor_Totp' ),
			get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ),
			'The network setting is unchanged'
		);
	}

	/**
	 * On a site's read-only page, network administrators get a link to the network settings.
	 *
	 * @covers Two_Factor_Settings::render_network_managed_page
	 */
	public function test_site_settings_page_links_network_admins_to_network_settings() {
		switch_to_blog( self::$blog_id );
		wp_set_current_user( self::$super_admin_id );

		ob_start();
		two_factor_render_settings_page();
		$output = ob_get_clean();

		restore_current_blog();

		$this->assertStringContainsString( esc_url( network_admin_url( 'settings.php?page=two-factor-settings' ) ), $output );
	}

	/**
	 * The network settings page explains a refused empty selection.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_network_settings_page_shows_empty_selection_error() {
		wp_set_current_user( self::$super_admin_id );
		set_current_screen( 'settings_page_two-factor-settings-network' );
		$_GET['page']  = 'two-factor-settings';
		$_GET['error'] = 'no_providers';

		ob_start();
		two_factor_render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'At least one provider must be enabled. No changes were saved.', $output );
		$this->assertStringNotContainsString( 'Settings saved.', $output );
	}

	/**
	 * The network settings page submits to the network admin save handler, and a submission
	 * to the page itself saves nothing.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_network_settings_page_renders_network_form() {
		update_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp' ) );

		wp_set_current_user( self::$super_admin_id );
		set_current_screen( 'settings_page_two-factor-settings-network' );
		$_GET['page']    = 'two-factor-settings';
		$_GET['updated'] = 'true';

		$output = $this->submit_settings_page( array( 'Two_Factor_Email' ) );

		$this->assertStringContainsString(
			'action="' . esc_url( network_admin_url( 'edit.php?action=two_factor_settings' ) ) . '"',
			$output,
			'The form submits to the network admin save handler'
		);
		$this->assertStringContainsString( 'Settings saved.', $output, 'The notice shows after the redirect back' );
		$this->assertStringContainsString( 'value="Two_Factor_Email"', $output, 'Disabled providers are listed' );
		$this->assertSame(
			array( 'Two_Factor_Totp' ),
			get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ),
			'The submission to the page itself was not saved'
		);
	}

	/**
	 * The network settings screen lists every provider so disabled ones can be turned back on,
	 * while the page parameter alone doesn't lift the restriction elsewhere.
	 *
	 * @covers ::two_factor_filter_enabled_providers
	 */
	public function test_network_settings_screen_lists_all_providers() {
		update_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp' ) );
		$_GET['page'] = 'two-factor-settings';

		set_current_screen( 'settings_page_two-factor-settings-network' );
		$network_screen_providers = array_keys( Two_Factor_Core::get_providers() );

		set_current_screen( 'profile' );
		$site_screen_providers = array_keys( Two_Factor_Core::get_providers() );

		$this->assertContains( 'Two_Factor_Email', $network_screen_providers, 'All providers are listed on the network settings screen' );
		$this->assertSame( array( 'Two_Factor_Totp' ), $site_screen_providers, 'The setting applies on other screens' );
	}

	/**
	 * The plugin action link points to the network settings, for network administrators only.
	 *
	 * @covers Two_Factor_Core::add_settings_action_link
	 */
	public function test_settings_action_link_points_to_network_settings() {
		$links = array( 'deactivate' => '<a href="#">Deactivate</a>' );

		wp_set_current_user( self::$super_admin_id );
		$super_admin_links = Two_Factor_Core::add_settings_action_link( $links );

		switch_to_blog( self::$blog_id );
		wp_set_current_user( self::$site_admin_id );
		$site_admin_links = Two_Factor_Core::add_settings_action_link( $links );
		restore_current_blog();

		$this->assertCount( 3, $super_admin_links );
		$this->assertStringContainsString(
			esc_url( network_admin_url( 'settings.php?page=two-factor-settings' ) ),
			reset( $super_admin_links ),
			'Network administrators get a link to the network settings'
		);
		$this->assertCount( 2, $site_admin_links, 'Site administrators only get the user settings link' );
		$this->assertNotFalse(
			has_filter(
				'network_admin_plugin_action_links_' . plugin_basename( TWO_FACTOR_DIR . 'two-factor.php' ),
				array( 'Two_Factor_Core', 'add_settings_action_link' )
			),
			'The link is added on the network plugins screen'
		);
	}
}
