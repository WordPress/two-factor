<?php
/**
 * Tests for the site-wide Two-Factor settings screen and the filters
 * that enforce the saved enabled-providers option.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Settings
 *
 * @package Two_Factor
 * @group core
 * @group settings
 */
class Tests_Two_Factor_Settings extends WP_UnitTestCase {

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase_Base::set_up()
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );
		$this->clear_settings_request();
	}

	/**
	 * Clean up after tests.
	 *
	 * @see WP_UnitTestCase::tear_down()
	 */
	public function tear_down() {
		delete_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );
		$this->clear_settings_request();

		parent::tear_down();
	}

	/**
	 * Reset request globals used by the settings screen and filters.
	 */
	private function clear_settings_request() {
		unset( $_GET['page'] );
		unset( $_POST['two_factor_settings_submit'] );
		unset( $_POST['two_factor_settings_nonce'] );
		unset( $_POST['two_factor_enabled_providers'] );
		unset( $_REQUEST['two_factor_settings_nonce'] );
		unset( $_REQUEST['_wp_http_referer'] );
	}

	/**
	 * Log in an administrator and mark the request as the settings screen.
	 *
	 * @return WP_User Administrator user.
	 */
	private function become_settings_admin() {
		$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );
		set_current_screen( 'options-general' );
		$_GET['page'] = 'two-factor-settings';

		return $admin;
	}

	/**
	 * Capture output from the settings renderer.
	 *
	 * @return string
	 */
	private function render_settings_page() {
		ob_start();
		Two_Factor_Settings::render_settings_page();
		return ob_get_clean();
	}

	/**
	 * Place a valid settings nonce in both POST and REQUEST.
	 *
	 * `check_admin_referer()` reads `$_REQUEST`, not `$_POST`.
	 */
	private function set_valid_settings_nonce() {
		$nonce = wp_create_nonce( 'two_factor_save_settings' );

		$_POST['two_factor_settings_nonce']    = $nonce;
		$_REQUEST['two_factor_settings_nonce'] = $nonce;
	}

	/**
	 * Sample provider map in the format passed to `two_factor_providers`.
	 *
	 * @return array
	 */
	private function sample_provider_map() {
		return array(
			'Two_Factor_Email'        => '/path/email.php',
			'Two_Factor_Totp'         => '/path/totp.php',
			'Two_Factor_Backup_Codes' => '/path/backup.php',
		);
	}

	/**
	 * The settings class is loaded by the plugin bootstrap.
	 */
	public function test_settings_class_exists() {
		$this->assertTrue( class_exists( 'Two_Factor_Settings' ) );
	}

	/**
	 * Site-wide enforcement filters are registered on init.
	 */
	public function test_admin_hooks_register_enforcement_filters() {
		$this->assertSame( 10, has_filter( 'two_factor_providers', 'two_factor_filter_enabled_providers' ) );
		$this->assertSame( 10, has_filter( 'two_factor_enabled_providers_for_user', 'two_factor_filter_enabled_providers_for_user' ) );
	}

	/**
	 * Users without manage_options see nothing and cannot persist settings.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_render_settings_page_requires_manage_options() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user->ID );

		$_POST['two_factor_settings_submit']   = '1';
		$_POST['two_factor_settings_nonce']    = wp_create_nonce( 'two_factor_save_settings' );
		$_POST['two_factor_enabled_providers'] = array( 'Two_Factor_Email' );

		$output = $this->render_settings_page();

		$this->assertSame( '', $output, 'Unprivileged users should not see the settings screen' );
		$this->assertFalse(
			get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ),
			'Unprivileged users must not write the enabled providers option'
		);
	}

	/**
	 * The wrapper function also bails without manage_options.
	 *
	 * @covers ::two_factor_render_settings_page
	 */
	public function test_render_settings_page_wrapper_requires_manage_options() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user->ID );

		ob_start();
		two_factor_render_settings_page();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Admins see the provider checkboxes, nonce, and save button.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_render_settings_page_outputs_provider_form() {
		$this->become_settings_admin();

		$output = $this->render_settings_page();

		$this->assertStringContainsString( 'Two-Factor Settings', $output );
		$this->assertStringContainsString( 'Enabled Providers', $output );
		$this->assertStringContainsString( 'name="two_factor_enabled_providers[]"', $output );
		$this->assertStringContainsString( 'id="provider_Two_Factor_Email"', $output );
		$this->assertStringContainsString( 'id="provider_Two_Factor_Totp"', $output );
		$this->assertStringContainsString( 'id="provider_Two_Factor_Backup_Codes"', $output );
		$this->assertStringContainsString( 'name="two_factor_settings_nonce"', $output );
		$this->assertStringContainsString( 'name="two_factor_settings_submit"', $output );
	}

	/**
	 * When the option has never been saved, every registered provider is checked.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_render_settings_page_checks_all_providers_by_default() {
		$this->become_settings_admin();

		$output = $this->render_settings_page();

		$this->assertSame( 1, preg_match( '/id="provider_Two_Factor_Email"[^>]*\bchecked\b/', $output ) );
		$this->assertSame( 1, preg_match( '/id="provider_Two_Factor_Totp"[^>]*\bchecked\b/', $output ) );
		$this->assertSame( 1, preg_match( '/id="provider_Two_Factor_Backup_Codes"[^>]*\bchecked\b/', $output ) );
	}

	/**
	 * Saved selections are reflected in the checkbox checked state.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_render_settings_page_checks_only_saved_providers() {
		$this->become_settings_admin();
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$output = $this->render_settings_page();

		$this->assertSame( 1, preg_match( '/id="provider_Two_Factor_Email"[^>]*\bchecked\b/', $output ) );
		$this->assertSame( 0, preg_match( '/id="provider_Two_Factor_Totp"[^>]*\bchecked\b/', $output ) );
		$this->assertStringContainsString( 'id="provider_Two_Factor_Totp"', $output );
	}

	/**
	 * Saving with a valid nonce persists unique, sanitized provider keys.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_render_settings_page_saves_sanitized_unique_providers() {
		$this->become_settings_admin();

		$_POST['two_factor_settings_submit'] = '1';
		$this->set_valid_settings_nonce();
		$_POST['two_factor_enabled_providers'] = array(
			'Two_Factor_Email',
			'Two_Factor_Email',
			'<b>Two_Factor_Totp</b>',
			'',
		);

		$output = $this->render_settings_page();

		$this->assertStringContainsString( 'Settings saved.', $output );
		$this->assertSame(
			array( 'Two_Factor_Email', 'Two_Factor_Totp' ),
			get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY )
		);
	}

	/**
	 * Submitting with no checkboxes selected stores an empty list.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_render_settings_page_saves_empty_provider_list() {
		$this->become_settings_admin();
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$_POST['two_factor_settings_submit'] = '1';
		$this->set_valid_settings_nonce();

		$this->render_settings_page();

		$this->assertSame(
			array(),
			get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY )
		);
	}

	/**
	 * An invalid nonce aborts the save via wp_die.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_render_settings_page_invalid_nonce_does_not_save() {
		$this->become_settings_admin();

		$_POST['two_factor_settings_submit']   = '1';
		$_POST['two_factor_settings_nonce']    = 'not-a-valid-nonce';
		$_REQUEST['two_factor_settings_nonce'] = 'not-a-valid-nonce';
		$_POST['two_factor_enabled_providers'] = array( 'Two_Factor_Email' );

		try {
			Two_Factor_Settings::render_settings_page();
			$this->fail( 'Invalid nonce should trigger wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertFalse(
				get_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY ),
				'Invalid nonce must not persist the enabled providers option'
			);
		}
	}

	/**
	 * Unsaved option means every provider is allowed.
	 *
	 * @covers ::two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_returns_null_when_never_saved() {
		$this->assertNull( two_factor_get_enabled_providers_option() );
	}

	/**
	 * A saved array is returned as-is.
	 *
	 * @covers ::two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_returns_saved_array() {
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			two_factor_get_enabled_providers_option()
		);
	}

	/**
	 * A saved empty array is distinct from "never saved".
	 *
	 * @covers ::two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_returns_empty_array_when_saved_empty() {
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array() );

		$this->assertSame( array(), two_factor_get_enabled_providers_option() );
	}

	/**
	 * Corrupt non-array option values collapse to an empty allow-list.
	 *
	 * @covers ::two_factor_get_enabled_providers_option
	 */
	public function test_get_enabled_providers_option_returns_empty_array_for_non_array() {
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, 'Two_Factor_Email' );

		$this->assertSame( array(), two_factor_get_enabled_providers_option() );
	}

	/**
	 * Never-saved option leaves the registered provider map unchanged.
	 *
	 * @covers ::two_factor_filter_enabled_providers
	 */
	public function test_filter_enabled_providers_passthrough_when_never_saved() {
		$providers = $this->sample_provider_map();

		$this->assertSame( $providers, two_factor_filter_enabled_providers( $providers ) );
	}

	/**
	 * Saved option removes providers that are not in the site allow-list.
	 *
	 * @covers ::two_factor_filter_enabled_providers
	 */
	public function test_filter_enabled_providers_removes_disabled_providers() {
		update_option(
			Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY,
			array( 'Two_Factor_Email', 'Two_Factor_Totp' )
		);

		$result = two_factor_filter_enabled_providers( $this->sample_provider_map() );

		$this->assertSame(
			array(
				'Two_Factor_Email' => '/path/email.php',
				'Two_Factor_Totp'  => '/path/totp.php',
			),
			$result
		);
	}

	/**
	 * An intentionally empty saved list disables every provider.
	 *
	 * @covers ::two_factor_filter_enabled_providers
	 */
	public function test_filter_enabled_providers_empty_list_disables_all() {
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array() );

		$this->assertSame( array(), two_factor_filter_enabled_providers( $this->sample_provider_map() ) );
	}

	/**
	 * The settings screen itself still lists every provider so admins can change the selection.
	 *
	 * @covers ::two_factor_filter_enabled_providers
	 */
	public function test_filter_enabled_providers_bypassed_on_settings_screen() {
		$this->become_settings_admin();
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$providers = $this->sample_provider_map();

		$this->assertSame( $providers, two_factor_filter_enabled_providers( $providers ) );
	}

	/**
	 * Bypass is admin-only: the same query arg on the front end still filters.
	 *
	 * @covers ::two_factor_filter_enabled_providers
	 */
	public function test_filter_enabled_providers_not_bypassed_outside_admin() {
		$_GET['page'] = 'two-factor-settings';
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$result = two_factor_filter_enabled_providers( $this->sample_provider_map() );

		$this->assertSame(
			array( 'Two_Factor_Email' => '/path/email.php' ),
			$result
		);
	}

	/**
	 * Never-saved option leaves a user's enabled providers unchanged.
	 *
	 * @covers ::two_factor_filter_enabled_providers_for_user
	 */
	public function test_filter_enabled_providers_for_user_passthrough_when_never_saved() {
		$enabled = array( 'Two_Factor_Email', 'Two_Factor_Totp' );

		$this->assertSame(
			$enabled,
			two_factor_filter_enabled_providers_for_user( $enabled, 1 )
		);
	}

	/**
	 * Saved option intersects the user's enabled providers with the site allow-list.
	 *
	 * @covers ::two_factor_filter_enabled_providers_for_user
	 */
	public function test_filter_enabled_providers_for_user_intersects_site_list() {
		update_option(
			Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY,
			array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' )
		);

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			two_factor_filter_enabled_providers_for_user(
				array( 'Two_Factor_Email', 'Two_Factor_Totp' ),
				1
			)
		);
	}

	/**
	 * An empty site allow-list removes every user-enabled provider.
	 *
	 * @covers ::two_factor_filter_enabled_providers_for_user
	 */
	public function test_filter_enabled_providers_for_user_empty_site_list() {
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array() );

		$this->assertSame(
			array(),
			two_factor_filter_enabled_providers_for_user(
				array( 'Two_Factor_Email', 'Two_Factor_Totp' ),
				1
			)
		);
	}

	/**
	 * Core provider retrieval honors the saved site-wide allow-list.
	 *
	 * @covers ::two_factor_filter_enabled_providers
	 */
	public function test_get_providers_honors_saved_site_allow_list() {
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$providers = Two_Factor_Core::get_providers();

		$this->assertSame( array( 'Two_Factor_Email' ), array_keys( $providers ) );
		$this->assertInstanceOf( 'Two_Factor_Email', $providers['Two_Factor_Email'] );
	}

	/**
	 * User-enabled providers are reduced to the site-wide allow-list.
	 *
	 * @covers ::two_factor_filter_enabled_providers_for_user
	 */
	public function test_get_enabled_providers_for_user_honors_site_allow_list() {
		$user = self::factory()->user->create_and_get();

		Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Email' );
		Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Totp' );

		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			Two_Factor_Core::get_enabled_providers_for_user( $user )
		);
	}
}
