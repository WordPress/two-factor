<?php

class Test_ClassTwoFactorCore extends WP_UnitTestCase {

	private $old_user_id;

	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();

		set_error_handler( array( 'Test_ClassTwoFactorCore', 'error_handler' ) );
	}

	public static function tearDownAfterClass() {
		restore_error_handler();

		parent::tearDownAfterClass();
	}

	public static function error_handler( $errno, $errstr ) {
		if ( E_USER_NOTICE != $errno ) {
			echo 'Received a non-notice error: ' . $errstr;

			return false;
		}

		return true;
	}

	public function get_dummy_user( $meta_key = array( 'Two_Factor_Dummy' => 'Two_Factor_Dummy' ) ) {
		$user = new WP_User( $this->factory->user->create() );
		$this->old_user_id = get_current_user_id();
		wp_set_current_user( $user->ID );

		$key = '_nonce_user_two_factor_options';
		$_POST[$key] = wp_create_nonce( 'user_two_factor_options' );
		$_REQUEST[$key] = $_POST[$key];

		$_POST[Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY] = $meta_key;

		Two_Factor_Core::user_two_factor_options_update( $user->ID );

		return $user;
	}

	public function clean_dummy_user() {
		unset( $_POST[Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY] );

		$key = '_nonce_user_two_factor_options';
		unset( $_REQUEST[$key] );
		unset( $_POST[$key] );
	}

	/**
	 * @covers Two_Factor_Core::add_hooks
	 */
	public function test_add_hooks() {
		Two_Factor_Core::add_hooks();

		$this->assertGreaterThan(
			0,
			has_action(
				'init',
				array( 'Two_Factor_Core', 'get_providers' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'login_form_validate_2fa',
				array( 'Two_Factor_Core', 'login_form_validate_2fa' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'login_form_backup_2fa',
				array( 'Two_Factor_Core', 'backup_2fa' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'init',
				array( 'Two_Factor_Core', 'register_scripts' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'wpmu_options',
				array( 'Two_Factor_Core', 'force_two_factor_setting_options' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'update_wpmu_options',
				array( 'Two_Factor_Core', 'save_network_force_two_factor_update' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'wp_ajax_two_factor_force_form_submit',
				array( 'Two_Factor_Core', 'handle_force_2fa_submission' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'two_factor_ajax_options_update',
				array( 'Two_Factor_Core', 'user_two_factor_options_update' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'parse_request',
				array( 'Two_Factor_Core', 'maybe_force_2fa_settings' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'admin_init',
				array( 'Two_Factor_Core', 'maybe_force_2fa_settings' )
			)
		);
	}

	/**
	 * @covers Two_Factor_Core::register_scripts
	 */
	public function test_register_scripts() {
		Two_Factor_Core::register_scripts();

		$this->assertTrue( wp_script_is( 'two-factor-form-script', 'registered' ) );
		$this->assertTrue( wp_style_is( 'user-edit-2fa', 'registered' ) );
	}

	/**
	 * @covers Two_Factor_Core::get_providers
	 */
	public function test_get_providers_not_empty() {
		$this->assertNotEmpty( Two_Factor_Core::get_providers() );
	}

	/**
	 * @covers Two_Factor_Core::get_providers
	 */
	public function test_get_providers_class_exists() {
		$result = Two_Factor_Core::get_providers();

		foreach ( array_keys( $result ) as $class ) {
			$this->assertNotNull( class_exists( $class ) );
		}
	}

	/**
	 * @covers Two_Factor_Core::get_enabled_providers_for_user
	 */
	public function test_get_enabled_providers_for_user_not_logged_in() {
		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user() );
	}

	/**
	 * @covers Two_Factor_Core::get_enabled_providers_for_user
	 */
	public function test_get_enabled_providers_for_user_logged_in() {
		$user = new WP_User( $this->factory->user->create() );
		$old_user_id = get_current_user_id();
		wp_set_current_user( $user->ID );

		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user() );

		wp_set_current_user( $old_user_id );
	}

	/**
	 * @covers Two_Factor_Core::get_enabled_providers_for_user
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 * @covers Two_Factor_Core::user_two_factor_options_update
	 */
	public function test_get_enabled_providers_for_user_logged_in_and_set_provider() {
		$user = $this->get_dummy_user();

		$this->assertCount( 1, Two_Factor_Core::get_available_providers_for_user( $user->ID ) );
		$this->assertCount( 1, Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) );

		wp_set_current_user( $this->old_user_id );
		$this->clean_dummy_user();
	}

	/**
	 * @covers Two_Factor_Core::get_enabled_providers_for_user
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 * @covers Two_Factor_Core::user_two_factor_options_update
	 */
	public function test_get_enabled_providers_for_user_logged_in_and_set_provider_bad_enabled() {
		$user = $this->get_dummy_user( 'test_badness' );

		$this->assertEmpty( Two_Factor_Core::get_available_providers_for_user( $user->ID ) );
		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) );

		wp_set_current_user( $this->old_user_id );
		$this->clean_dummy_user();
	}

	/**
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 */
	public function test_get_available_providers_for_user_not_logged_in() {
		$this->assertEmpty( Two_Factor_Core::get_available_providers_for_user() );
	}

	/**
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 */
	public function test_get_available_providers_for_user_logged_in() {
		$user = new WP_User( $this->factory->user->create() );
		$old_user_id = get_current_user_id();
		wp_set_current_user( $user->ID );

		$this->assertEmpty( Two_Factor_Core::get_available_providers_for_user() );

		wp_set_current_user( $old_user_id );
	}

	/**
	 * @covers Two_Factor_Core::get_primary_provider_for_user
	 */
	public function test_get_primary_provider_for_user_not_logged_in() {
		$this->assertEmpty( Two_Factor_Core::get_primary_provider_for_user() );
	}

	/**
	 * @covers Two_Factor_Core::is_user_using_two_factor
	 */
	public function test_is_user_using_two_factor_not_logged_in() {
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor() );
	}

	/**
	 * @covers Two_Factor_Core::maybe_force_2fa_settings
	 */
	public function test_maybe_force_2fa_settings_logged_in_wrong_role() {
		// Set universal value to false.
		update_site_option( Two_Factor_Core::FORCED_SITE_META_KEY, 0 );
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Core::FORCED_ROLES_META_KEY, [ 'editor', 'administrator' ] );

		$user = new WP_User( $this->factory->user->create( [ 'role' => 'author' ] ) );
		wp_set_current_user( $user->ID );

		$this->assertFalse( Two_Factor_Core::maybe_force_2fa_settings() );
	}

	/**
	 * @covers Two_Factor_Core::maybe_force_2fa_settings
	 */
	public function test_maybe_force_2fa_settings_logged_in_no_requirement() {
		// Set universal value to false.
		update_site_option( Two_Factor_Core::FORCED_SITE_META_KEY, 0 );

		$user = new WP_User( $this->factory->user->create() );
		wp_set_current_user( $user->ID );

		$this->assertFalse( Two_Factor_Core::maybe_force_2fa_settings() );
	}

	/**
	 * @covers Two_Factor_Core::maybe_force_2fa_settings
	 */
	public function test_maybe_force_2fa_settings_logged_out() {
		wp_logout();

		$this->assertFalse( Two_Factor_Core::maybe_force_2fa_settings() );
	}

	/**
	 * @covers Two_Factor_Core::maybe_force_2fa_settings
	 */
	public function test_maybe_force_2fa_settings_is_rest() {
		define( 'REST_REQUEST', true );

		$this->assertFalse( Two_Factor_Core::maybe_force_2fa_settings() );
	}

	/**
	 * @covers Two_Factor_Core::maybe_force_2fa_settings
	 */
	public function test_maybe_force_2fa_settings_is_ajax() {
		define( 'DOING_AJAX', true );

		$this->assertFalse( Two_Factor_Core::maybe_force_2fa_settings() );
	}

	/**
	 * @covers Two_Factor_Core::is_two_factor_forced
	 */
	public function test_is_two_factor_forced_universal_option() {
		update_site_option( Two_Factor_Core::FORCED_SITE_META_KEY, 1 );

		$this->assertTrue( Two_Factor_Core::is_two_factor_forced( 123456 ) );
	}

	/**
	 * @covers Two_Factor_Core::is_two_factor_forced
	 */
	public function test_is_two_factor_forced_non_existant_user() {
		update_site_option( Two_Factor_Core::FORCED_SITE_META_KEY, 0 );

		$this->assertFalse( Two_Factor_Core::is_two_factor_forced( 123456 ) );
	}

	/**
	 * @covers Two_Factor_Core::is_two_factor_forced
	 */
	public function test_is_two_factor_forced_different_role() {
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Core::FORCED_ROLES_META_KEY, [ 'editor', 'administrator' ] );

		$user = new WP_User( $this->factory->user->create( [ 'role' => 'author' ] ) );
		wp_set_current_user( $user->ID );

		$this->assertFalse( Two_Factor_Core::is_two_factor_forced( $user->ID ) );
	}

	/**
	 * @covers Two_Factor_Core::is_two_factor_forced
	 */
	public function test_is_two_factor_forced_captured_role() {
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Core::FORCED_ROLES_META_KEY, [ 'editor', 'author' ] );

		$user = new WP_User( $this->factory->user->create( [ 'role' => 'author' ] ) );
		wp_set_current_user( $user->ID );

		$this->assertTrue( Two_Factor_Core::is_two_factor_forced( $user->ID ) );
	}

	/**
	 * @covers Two_Factor_Core::get_universally_forced_option
	 */
	public function test_get_universally_forced_option_multisite() {
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Core::FORCED_SITE_META_KEY, 1 );

		$this->assertTrue( Two_Factor_Core::get_universally_forced_option() );
	}

	/**
	 * @covers Two_Factor_Core::get_forced_user_roles
	 */
	public function test_get_forced_user_roles_multisite() {
		// Set role-based value to editors and adminstrators.
		update_site_option( Two_Factor_Core::FORCED_ROLES_META_KEY, [ 'author', 'editor', 'administrator' ] );

		$this->assertEquals( [ 'author', 'editor', 'administrator' ], Two_Factor_Core::get_forced_user_roles() );
	}
}
