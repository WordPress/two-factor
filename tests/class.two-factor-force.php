<?php

/**
 * Class Test_ClassTwoFactorForce
 *
 * @package TwoFactor
 * @group core
 */
class Test_ClassTwoFactorForce extends WP_UnitTestCase {
	/**
	 * @covers Two_Factor_Force::add_hooks
	 */
	public function test_add_hooks() {
		Two_Factor_Force::add_hooks();
		
		$this->assertGreaterThan(
			0,
			has_action(
				'init',
				array( 'Two_Factor_Force', 'register_scripts' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'wpmu_options',
				array( 'Two_Factor_Force', 'force_two_factor_setting_options' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'update_wpmu_options',
				array( 'Two_Factor_Force', 'save_network_force_two_factor_update' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'wp_ajax_two_factor_force_form_submit',
				array( 'Two_Factor_Force', 'handle_force_2fa_submission' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'parse_request',
				array( 'Two_Factor_Force', 'maybe_redirect_to_2fa_settings' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'admin_init',
				array( 'Two_Factor_Force', 'maybe_redirect_to_2fa_settings' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'admin_init',
				array( 'Two_Factor_Force', 'maybe_display_2fa_settings' )
			)
		);
	}

	/**
	 * @covers Two_Factor_Force::register_scripts
	 */
	public function test_register_scripts() {
		Two_Factor_Force::register_scripts();

		$this->assertTrue( wp_script_is( 'two-factor-form-script', 'registered' ) );
	}

	/**
	 * @covers Two_Factor_Force::should_user_redirect
	 */
	public function test_should_user_redirect_logged_in_wrong_role() {
		// Set universal value to false.
		update_site_option( Two_Factor_Force::FORCED_SITE_META_KEY, 0 );
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Force::FORCED_ROLES_META_KEY, [ 'editor', 'administrator' ] );

		$user = new WP_User( $this->factory->user->create( [ 'role' => 'author' ] ) );

		$this->assertFalse( Two_Factor_Force::should_user_redirect( $user->ID ) );
	}

	/**
	 * @covers Two_Factor_Force::should_user_redirect
	 */
	public function test_should_user_redirect_logged_in_no_requirement() {
		// Set universal value to false.
		update_site_option( Two_Factor_Force::FORCED_SITE_META_KEY, 0 );

		$user = new WP_User( $this->factory->user->create() );

		$this->assertFalse( Two_Factor_Force::should_user_redirect( $user->ID ) );
	}

	/**
	 * @covers Two_Factor_Force::should_user_redirect
	 */
	public function test_should_user_redirect_logged_out() {
		wp_logout();

		$this->assertFalse( Two_Factor_Force::should_user_redirect( 123456 ) );
	}

	/**
	 * @covers Two_Factor_Force::should_user_redirect
	 */
	public function test_should_user_redirect_is_rest() {
		define( 'REST_REQUEST', true );

		$this->assertFalse( Two_Factor_Force::should_user_redirect( 123456 ) );
	}

	/**
	 * @covers Two_Factor_Force::should_user_redirect
	 */
	public function test_should_user_redirect_is_ajax() {
		define( 'DOING_AJAX', true );

		$this->assertFalse( Two_Factor_Force::should_user_redirect( 123456 ) );
	}

	/**
	 * @covers Two_Factor_Force::is_two_factor_forced
	 */
	public function test_is_two_factor_forced_universal_option() {
		update_site_option( Two_Factor_Force::FORCED_SITE_META_KEY, 1 );

		$this->assertTrue( Two_Factor_Force::is_two_factor_forced( 123456 ) );
	}

	/**
	 * @covers Two_Factor_Force::is_two_factor_forced
	 */
	public function test_is_two_factor_forced_non_existant_user() {
		update_site_option( Two_Factor_Force::FORCED_SITE_META_KEY, 0 );

		$this->assertFalse( Two_Factor_Force::is_two_factor_forced( 123456 ) );
	}

	/**
	 * @covers Two_Factor_Force::is_two_factor_forced
	 */
	public function test_is_two_factor_forced_different_role() {
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Force::FORCED_ROLES_META_KEY, [ 'editor', 'administrator' ] );

		$user = new WP_User( $this->factory->user->create( [ 'role' => 'author' ] ) );
		wp_set_current_user( $user->ID );

		$this->assertFalse( Two_Factor_Force::is_two_factor_forced( $user->ID ) );
	}

	/**
	 * @covers Two_Factor_Force::is_two_factor_forced
	 */
	public function test_is_two_factor_forced_captured_role() {
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Force::FORCED_ROLES_META_KEY, [ 'editor', 'author' ] );

		$user = new WP_User( $this->factory->user->create( [ 'role' => 'author' ] ) );
		wp_set_current_user( $user->ID );

		$this->assertTrue( Two_Factor_Force::is_two_factor_forced( $user->ID ) );
	}

	/**
	 * @covers Two_Factor_Force::get_universally_forced_option
	 */
	public function test_get_universally_forced_option_multisite() {
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Force::FORCED_SITE_META_KEY, 1 );

		$this->assertTrue( Two_Factor_Force::get_universally_forced_option() );
	}

	/**
	 * @covers Two_Factor_Force::get_forced_user_roles
	 */
	public function test_get_forced_user_roles_multisite() {
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Force::FORCED_ROLES_META_KEY, [ 'author', 'editor', 'administrator' ] );

		$this->assertEquals( [ 'author', 'editor', 'administrator' ], Two_Factor_Force::get_forced_user_roles() );
	}
}
