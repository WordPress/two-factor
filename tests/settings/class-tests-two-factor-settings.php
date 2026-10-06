<?php
/**
 * Test the Two-Factor settings screen.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Settings
 *
 * @package Two_Factor
 * @group settings
 * @group secrets
 */
class Tests_Two_Factor_Settings extends Two_Factor_Secrets_UnitTestCase {

	/**
	 * Start from the default state and act as an administrator who may change the setting.
	 */
	public function set_up() {
		parent::set_up();
		$this->opt_out();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );
	}

	/**
	 * Clear the simulated form submission.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Render the settings page, optionally as a form submission.
	 *
	 * @param array|null $post Posted fields, or null for a plain page view.
	 * @return string
	 */
	private function render( $post = null ) {
		if ( null !== $post ) {
			$_POST = array_merge(
				array(
					'two_factor_settings_submit'   => '1',
					'two_factor_settings_nonce'    => wp_create_nonce( 'two_factor_save_settings' ),
					'two_factor_enabled_providers' => array_keys( Two_Factor_Core::get_providers() ),
				),
				$post
			);

			$_REQUEST = $_POST;
		}

		ob_start();
		Two_Factor_Settings::render_settings_page();

		return ob_get_clean();
	}

	/**
	 * Without the API there is nothing to turn on.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_section_explains_absent_api_without_a_checkbox() {
		$this->simulate_api_absent();

		$output = $this->render();

		$this->assertStringContainsString( 'Authenticator App Secret Storage', $output );
		$this->assertStringNotContainsString( 'two_factor_secrets_api_enabled', $output );
	}

	/**
	 * With the API present and no opt-in, the checkbox is off and the acknowledgement is shown.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_section_offers_opt_in_with_acknowledgement() {
		$this->require_secrets_api();

		$output = $this->render();

		$this->assertStringContainsString( 'name="two_factor_secrets_api_enabled"', $output );
		$this->assertStringContainsString( 'name="two_factor_secrets_api_acknowledged"', $output );
		$this->assertStringContainsString( 'WP_SECRETS_KEY', $output );
		$this->assertStringNotContainsString( "checked='checked'", substr( $output, strpos( $output, 'two-factor-secrets-storage' ) ) );
	}

	/**
	 * Turning storage on without the acknowledgement is refused.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_opt_in_refused_without_acknowledgement() {
		$this->require_secrets_api();

		$output = $this->render(
			array(
				'two_factor_secrets_api_setting' => '1',
				'two_factor_secrets_api_enabled' => '1',
			)
		);

		$this->assertFalse( Two_Factor_Secrets::is_opted_in() );
		$this->assertStringContainsString( 'Encrypted storage was not turned on', $output );
	}

	/**
	 * Turning storage on with the acknowledgement is saved.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_opt_in_saved_with_acknowledgement() {
		$this->require_secrets_api();

		$output = $this->render(
			array(
				'two_factor_secrets_api_setting'      => '1',
				'two_factor_secrets_api_enabled'      => '1',
				'two_factor_secrets_api_acknowledged' => '1',
			)
		);

		$this->assertTrue( Two_Factor_Secrets::is_opted_in() );
		$this->assertStringNotContainsString( 'Encrypted storage was not turned on', $output );
		$this->assertStringNotContainsString( 'name="two_factor_secrets_api_acknowledged"', $output );
	}

	/**
	 * Staying opted in needs no fresh acknowledgement, and unchecking turns storage off.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_opt_in_kept_then_turned_off() {
		$this->require_secrets_api();
		Two_Factor_Secrets::set_opted_in( true );

		$this->render(
			array(
				'two_factor_secrets_api_setting' => '1',
				'two_factor_secrets_api_enabled' => '1',
			)
		);
		$this->assertTrue( Two_Factor_Secrets::is_opted_in() );

		$this->render( array( 'two_factor_secrets_api_setting' => '1' ) );
		$this->assertFalse( Two_Factor_Secrets::is_opted_in() );
	}

	/**
	 * A save that did not include the storage section leaves the opt-in alone.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_save_without_storage_section_keeps_opt_in() {
		Two_Factor_Secrets::set_opted_in( true );
		$this->simulate_api_absent();

		$this->render( array() );

		$this->assertTrue( Two_Factor_Secrets::is_opted_in() );
	}

	/**
	 * On multisite a site administrator sees the setting but cannot change it.
	 *
	 * @covers Two_Factor_Settings::render_settings_page
	 */
	public function test_site_admin_cannot_change_network_setting_on_multisite() {
		$this->require_secrets_api();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only applies on multisite.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$output = $this->render(
			array(
				'two_factor_secrets_api_setting'      => '1',
				'two_factor_secrets_api_enabled'      => '1',
				'two_factor_secrets_api_acknowledged' => '1',
			)
		);

		$this->assertFalse( Two_Factor_Secrets::is_opted_in() );
		$this->assertStringContainsString( 'only a network administrator', $output );
		$this->assertStringNotContainsString( 'name="two_factor_secrets_api_setting"', $output );
	}
}
