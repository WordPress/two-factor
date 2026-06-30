<?php
/**
 * Two Factor Network Settings Tests.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Network_Settings
 *
 * @package Two_Factor
 * @group core
 * @group network
 */
class Tests_Two_Factor_Network_Settings extends WP_UnitTestCase {

	/**
	 * Admin user ID for tests that render admin screens.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	/**
	 * Cleanup after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		wp_set_current_user( 0 );

		remove_filter( 'two_factor_network_mode', '__return_true' );
		remove_filter( 'two_factor_network_mode', '__return_false' );

		delete_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );
		delete_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY );
		delete_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY );
	}

	/**
	 * Render the site settings page without the provider-enforcement filter.
	 *
	 * @return string
	 */
	private function render_site_settings_page() {
		remove_filter( 'two_factor_providers', 'two_factor_filter_enabled_providers' );
		ob_start();
		Two_Factor_Settings::render_settings_page();
		$output = ob_get_clean();
		add_filter( 'two_factor_providers', 'two_factor_filter_enabled_providers' );
		return $output;
	}

	/**
	 * Network mode is off by default in a single-site test environment.
	 *
	 * @covers two_factor_is_network_mode
	 */
	public function test_is_network_mode_false_by_default() {
		$this->assertFalse( two_factor_is_network_mode() );
	}

	/**
	 * The two_factor_network_mode filter can force network mode for tests.
	 *
	 * @covers two_factor_is_network_mode
	 */
	public function test_is_network_mode_true_with_filter() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		$this->assertTrue( two_factor_is_network_mode() );
	}

	/**
	 * When no option is saved, the effective list is null (allow all providers).
	 *
	 * @covers two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_returns_null_when_unsaved() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		$this->assertNull( two_factor_get_enabled_providers_option() );
	}

	/**
	 * Site option is used when the plugin is not in network mode.
	 *
	 * @covers two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_uses_site_option_when_not_network_mode() {
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );
		$this->assertSame( array( 'Two_Factor_Email' ), two_factor_get_enabled_providers_option() );
	}

	/**
	 * Site option remains effective when network mode is active but the network
	 * option has never been saved.
	 *
	 * @covers two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_uses_site_option_when_network_option_unsaved() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email', 'Two_Factor_Totp' ) );

		$this->assertSame( array( 'Two_Factor_Email', 'Two_Factor_Totp' ), two_factor_get_enabled_providers_option() );
	}

	/**
	 * Network option is the exact effective list when override is not allowed.
	 *
	 * @covers two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_uses_network_option_when_no_override() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		update_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, array( 'Two_Factor_Email' ) );
		update_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, 0 );
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp' ) );

		$this->assertSame( array( 'Two_Factor_Email' ), two_factor_get_enabled_providers_option() );
	}

	/**
	 * When override is allowed, the effective list is the intersection of the
	 * network and site lists.
	 *
	 * @covers two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_intersects_when_override_allowed() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		update_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, array( 'Two_Factor_Email', 'Two_Factor_Totp' ) );
		update_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, 1 );
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ) );

		$this->assertSame( array( 'Two_Factor_Totp' ), two_factor_get_enabled_providers_option() );
	}

	/**
	 * When override is allowed but the subsite has never saved a list, the
	 * network list is used.
	 *
	 * @covers two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_uses_network_when_override_and_site_unsaved() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		update_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, array( 'Two_Factor_Email' ) );
		update_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, 1 );

		$this->assertSame( array( 'Two_Factor_Email' ), two_factor_get_enabled_providers_option() );
	}

	/**
	 * The registered providers filter enforces the network provider list.
	 *
	 * @covers two_factor_filter_enabled_providers
	 */
	public function test_filter_enabled_providers_enforces_network_option() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		update_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$providers = array(
			'Two_Factor_Email'        => '/path/to/email.php',
			'Two_Factor_Totp'         => '/path/to/totp.php',
			'Two_Factor_Backup_Codes' => '/path/to/backup.php',
		);
		$filtered  = two_factor_filter_enabled_providers( $providers );

		$this->assertSame( array( 'Two_Factor_Email' ), array_keys( $filtered ) );
	}

	/**
	 * The per-user enabled providers filter enforces the network provider list.
	 *
	 * @covers two_factor_filter_enabled_providers_for_user
	 */
	public function test_filter_enabled_providers_for_user_enforces_network_option() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		update_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$user_enabled = array( 'Two_Factor_Email', 'Two_Factor_Totp' );
		$filtered     = two_factor_filter_enabled_providers_for_user( $user_enabled, 0 );

		$this->assertSame( array( 'Two_Factor_Email' ), $filtered );
	}

	/**
	 * Plugin uninstall removes network options.
	 *
	 * @covers Two_Factor_Core::uninstall
	 */
	public function test_uninstall_removes_network_options() {
		update_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, array( 'Two_Factor_Email' ) );
		update_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, 1 );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			get_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY ),
			'Network enabled providers option was set'
		);
		$this->assertSame(
			1,
			get_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY ),
			'Network override option was set'
		);

		Two_Factor_Core::uninstall();

		$this->assertFalse(
			get_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, false ),
			'Network enabled providers option was deleted during uninstall'
		);
		$this->assertFalse(
			get_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, false ),
			'Network override option was deleted during uninstall'
		);
	}

	/**
	 * Site settings page shows a network-managed notice when network override is disabled.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_site_settings_page_shows_network_managed_notice() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		update_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, array( 'Two_Factor_Email' ) );
		update_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, 0 );

		$output = $this->render_site_settings_page();

		$this->assertStringContainsString( 'Provider settings are managed at the network level.', $output );
		$this->assertStringNotContainsString( 'name="two_factor_settings_submit"', $output );
		$this->assertStringContainsString( 'disabled="disabled"', $output );
	}

	/**
	 * Site settings page shows a narrowing notice when network override is enabled.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_site_settings_page_shows_override_notice() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		update_site_option( Two_Factor_Core::ENABLED_PROVIDERS_NETWORK_OPTION_KEY, array( 'Two_Factor_Email' ) );
		update_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, 1 );

		$output = $this->render_site_settings_page();

		$this->assertStringContainsString( 'The network has enabled the following providers. This site can only narrow the list.', $output );
		$this->assertStringContainsString( 'name="two_factor_settings_submit"', $output );
		$this->assertStringContainsString( 'disabled="disabled"', $output );
	}

	/**
	 * Site settings page remains editable when the network has not configured providers.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_site_settings_page_editable_when_network_unconfigured() {
		add_filter( 'two_factor_network_mode', '__return_true' );
		// Intentionally not saving the network option.
		update_site_option( Two_Factor_Core::NETWORK_ALLOW_SITE_OVERRIDE_OPTION_KEY, 0 );

		$output = $this->render_site_settings_page();

		$this->assertStringContainsString( 'Choose which Two-Factor providers are available on this site.', $output );
		$this->assertStringContainsString( 'name="two_factor_settings_submit"', $output );
		$this->assertStringNotContainsString( 'disabled="disabled"', $output );
	}
}
