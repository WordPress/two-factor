<?php
/**
 * Test the Two-Factor settings.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Settings
 *
 * @package Two_Factor
 * @group settings
 */
class Tests_Two_Factor_Settings extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Set up test fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory instance.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Clean up the simulated request after each test.
	 */
	public function tear_down() {
		unset(
			$_POST['two_factor_settings_submit'],
			$_POST['two_factor_settings_nonce'],
			$_POST['two_factor_enabled_providers'],
			$_REQUEST['two_factor_settings_nonce']
		);

		parent::tear_down();
	}

	/**
	 * Submit the settings form to the settings page callback and return its output.
	 *
	 * @param string[] $providers Provider keys to submit.
	 * @return string
	 */
	private function submit_settings_page( array $providers ) {
		$nonce = wp_create_nonce( 'two_factor_save_settings' );

		$_POST['two_factor_settings_submit']   = 'Save Settings';
		$_POST['two_factor_settings_nonce']    = $nonce;
		$_REQUEST['two_factor_settings_nonce'] = $nonce;
		$_POST['two_factor_enabled_providers'] = $providers;

		ob_start();

		try {
			two_factor_render_settings_page();
		} finally {
			$output = ob_get_clean();
		}

		return $output;
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
	 * The legacy helper returns the same value as the core accessor.
	 *
	 * @covers ::two_factor_get_enabled_providers_option
	 * @covers Two_Factor_Core::get_enabled_providers_policy
	 */
	public function test_enabled_providers_option_helper_matches_policy() {
		$this->assertNull( two_factor_get_enabled_providers_option(), 'Never saved means all providers are allowed' );
		$this->assertNull( Two_Factor_Core::get_enabled_providers_policy(), 'Never saved means all providers are allowed' );

		Two_Factor_Core::update_enabled_providers_policy( array( 'Two_Factor_Totp' ) );

		$this->assertSame( array( 'Two_Factor_Totp' ), two_factor_get_enabled_providers_option() );
		$this->assertSame( array( 'Two_Factor_Totp' ), Two_Factor_Core::get_enabled_providers_policy() );
	}

	/**
	 * Saved provider keys are de-duplicated, and anything but strings is dropped.
	 *
	 * @covers Two_Factor_Core::update_enabled_providers_policy
	 */
	public function test_update_policy_normalizes_providers() {
		Two_Factor_Core::update_enabled_providers_policy(
			array( 'Two_Factor_Totp', 'Two_Factor_Totp', array( 'Two_Factor_Dummy' ), 'Two_Factor_Email' )
		);

		$this->assertSame( array( 'Two_Factor_Totp', 'Two_Factor_Email' ), Two_Factor_Core::get_enabled_providers_policy() );
	}

	/**
	 * Uninstall removes the setting whether it was saved per site or network-wide.
	 *
	 * @covers Two_Factor_Core::uninstall
	 */
	public function test_uninstall_removes_policy_in_both_scopes() {
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );
		update_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );
		update_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_MIGRATED_OPTION_KEY, 1 );

		Two_Factor_Core::uninstall();

		$this->assertFalse( get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ), 'The site option was deleted' );
		$this->assertFalse( get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ), 'The network option was deleted' );
		$this->assertFalse( get_network_option( null, Two_Factor_Core::ENABLED_PROVIDERS_MIGRATED_OPTION_KEY ), 'The migration flag was deleted' );
	}

	/**
	 * Narrowing the setting so a user has no usable provider left locks them out instead of
	 * letting them in with only their password.
	 *
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 * @covers Two_Factor_Core::is_user_using_two_factor
	 */
	public function test_policy_without_usable_provider_fails_closed() {
		$user_id = self::factory()->user->create();
		Two_Factor_Totp::get_instance()->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );

		Two_Factor_Core::update_enabled_providers_policy( array( 'Two_Factor_Backup_Codes' ) );

		$available = Two_Factor_Core::get_available_providers_for_user( $user_id );

		$this->assertWPError( $available, 'No provider is available' );
		$this->assertSame( 'no_available_2fa_methods', $available->get_error_code() );
		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $user_id ), 'Two-factor is still required for the user' );
	}

	/**
	 * Single site: the setting is managed with `manage_options`.
	 *
	 * @covers Two_Factor_Core::policy_capability
	 */
	public function test_single_site_policy_capability() {
		$this->skipWithMultisite();

		$this->assertSame( 'manage_options', Two_Factor_Core::policy_capability() );
	}

	/**
	 * Single site: the setting is a regular option.
	 *
	 * @covers ::two_factor_get_enabled_providers_option
	 * @covers ::two_factor_filter_enabled_providers
	 */
	public function test_single_site_policy_is_a_site_option() {
		$this->skipWithMultisite();

		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp' ) );

		$this->assertSame( array( 'Two_Factor_Totp' ), two_factor_get_enabled_providers_option() );
		$this->assertSame( array( 'Two_Factor_Totp' ), array_keys( Two_Factor_Core::get_providers() ) );
	}

	/**
	 * Single site: an administrator saves the settings from the settings screen.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 * @covers ::two_factor_render_settings_page
	 */
	public function test_single_site_admin_can_save_settings() {
		$this->skipWithMultisite();

		wp_set_current_user( self::$admin_id );

		$output = $this->submit_settings_page( array( 'Two_Factor_Totp', 'Two_Factor_Totp', '', 'Two_Factor_Backup_Codes' ) );

		$this->assertStringContainsString( 'Settings saved.', $output );
		$this->assertSame( array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ), get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ) );
	}

	/**
	 * Single site: users without `manage_options` can't save the settings.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 * @covers ::two_factor_render_settings_page
	 */
	public function test_single_site_editor_cannot_save_settings() {
		$this->skipWithMultisite();

		wp_set_current_user( self::$editor_id );

		$output = $this->submit_settings_page( array( 'Two_Factor_Email' ) );

		$this->assertSame( '', $output, 'Nothing is rendered' );
		$this->assertFalse( get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ), 'Nothing was saved' );
	}

	/**
	 * Single site: the settings screen is registered under Settings.
	 *
	 * @covers ::two_factor_register_admin_hooks
	 * @covers ::two_factor_add_settings_page
	 */
	public function test_single_site_settings_page_is_registered_under_settings() {
		$this->skipWithMultisite();

		set_current_screen( 'dashboard' );
		wp_set_current_user( self::$admin_id );

		two_factor_register_admin_hooks();

		$this->assertNotFalse( has_action( 'admin_menu', 'two_factor_add_settings_page' ) );
		$this->assertFalse( has_action( 'network_admin_menu', 'two_factor_add_network_settings_page' ) );

		$pages = $this->get_registered_page_capabilities( 'options-general.php', 'two_factor_add_settings_page' );

		$this->assertArrayHasKey( 'two-factor-settings', $pages );
		$this->assertSame( 'manage_options', $pages['two-factor-settings'] );
	}
}
