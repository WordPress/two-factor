<?php
/**
 * Test the enrollment enforcement method and its onboarding flow.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Onboarding
 *
 * @package Two_Factor
 * @group enforcement
 * @group onboarding
 */
class Tests_Two_Factor_Onboarding extends WP_UnitTestCase {

	/**
	 * Clean up the request state touched by the screen helpers.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_GET['page'] );
		unset( $_SERVER['SCRIPT_NAME'] );

		parent::tear_down();
	}

	/**
	 * Turn on enrollment enforcement for a role.
	 *
	 * @param string $role Role slug to enforce.
	 * @return void
	 */
	private function enforce_enrollment_for( $role ) {
		update_option( 'two_factor_enforced_roles', array( $role ) );
		update_option( Two_Factor_Onboarding::ENFORCEMENT_METHOD_OPTION_KEY, Two_Factor_Onboarding::METHOD_ENROLLMENT );
	}

	/**
	 * The email method is used unless the enrollment method is saved.
	 *
	 * @covers Two_Factor_Onboarding::get_enforcement_method
	 * @covers Two_Factor_Onboarding::is_enrollment_required
	 */
	public function test_email_is_the_default_enforcement_method() {
		$this->assertSame( Two_Factor_Onboarding::METHOD_EMAIL, Two_Factor_Onboarding::get_enforcement_method() );
		$this->assertFalse( Two_Factor_Onboarding::is_enrollment_required() );
	}

	/**
	 * An unknown stored value falls back to the email method.
	 *
	 * @covers Two_Factor_Onboarding::get_enforcement_method
	 */
	public function test_unknown_enforcement_method_falls_back_to_email() {
		update_option( Two_Factor_Onboarding::ENFORCEMENT_METHOD_OPTION_KEY, 'something-else' );

		$this->assertSame( Two_Factor_Onboarding::METHOD_EMAIL, Two_Factor_Onboarding::get_enforcement_method() );
	}

	/**
	 * The enrollment method does not inject a provider the user has not configured.
	 *
	 * @covers ::two_factor_enforce_for_user
	 */
	public function test_enrollment_method_does_not_inject_email() {
		$this->enforce_enrollment_for( 'subscriber' );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( array(), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
	}

	/**
	 * New users are not auto-enrolled when the enrollment method is used.
	 *
	 * @covers ::two_factor_force_on_user_register
	 */
	public function test_enrollment_method_does_not_enrol_new_users() {
		$this->enforce_enrollment_for( 'subscriber' );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}

	/**
	 * Users in an enforced role without a configured provider are pending.
	 *
	 * @covers Two_Factor_Onboarding::is_user_pending
	 */
	public function test_user_in_enforced_role_is_pending() {
		$this->enforce_enrollment_for( 'editor' );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( Two_Factor_Onboarding::is_user_pending( $user_id ) );
	}

	/**
	 * Users outside the enforced roles are never pending.
	 *
	 * @covers Two_Factor_Onboarding::is_user_pending
	 */
	public function test_user_outside_enforced_role_is_not_pending() {
		$this->enforce_enrollment_for( 'editor' );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( Two_Factor_Onboarding::is_user_pending( $user_id ) );
	}

	/**
	 * A user who configured a provider is no longer pending.
	 *
	 * @covers Two_Factor_Onboarding::is_user_pending
	 */
	public function test_user_with_provider_is_not_pending() {
		$this->enforce_enrollment_for( 'editor' );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		Two_Factor_Core::enable_provider_for_user( $user_id, 'Two_Factor_Dummy' );

		$this->assertFalse( Two_Factor_Onboarding::is_user_pending( $user_id ) );
	}

	/**
	 * Nothing is pending while the email enforcement method is used.
	 *
	 * @covers Two_Factor_Onboarding::is_user_pending
	 */
	public function test_nobody_is_pending_with_the_email_method() {
		update_option( 'two_factor_enforced_roles', array( 'editor' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertFalse( Two_Factor_Onboarding::is_user_pending( $user_id ) );
	}

	/**
	 * The pending state can be overridden by other plugins.
	 *
	 * @covers Two_Factor_Onboarding::is_user_pending
	 */
	public function test_pending_state_is_filterable() {
		$this->enforce_enrollment_for( 'editor' );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		add_filter( 'two_factor_onboarding_is_user_pending', '__return_false' );
		$this->assertFalse( Two_Factor_Onboarding::is_user_pending( $user_id ) );

		remove_filter( 'two_factor_onboarding_is_user_pending', '__return_false' );
		$this->assertTrue( Two_Factor_Onboarding::is_user_pending( $user_id ) );
	}

	/**
	 * Pending users are sent to the setup screen after logging in.
	 *
	 * @covers Two_Factor_Onboarding::filter_login_redirect
	 */
	public function test_login_redirects_pending_user_to_setup_page() {
		$this->enforce_enrollment_for( 'editor' );
		$user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );

		$this->assertSame(
			Two_Factor_Onboarding::get_setup_page_url(),
			Two_Factor_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user )
		);
	}

	/**
	 * Users who are not pending keep their requested destination.
	 *
	 * @covers Two_Factor_Onboarding::filter_login_redirect
	 */
	public function test_login_redirect_is_untouched_for_other_users() {
		$this->enforce_enrollment_for( 'editor' );
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->assertSame(
			admin_url( 'edit.php' ),
			Two_Factor_Onboarding::filter_login_redirect( admin_url( 'edit.php' ), admin_url( 'edit.php' ), $user )
		);
	}

	/**
	 * The setup screen, the profile screen and the form endpoints stay reachable.
	 *
	 * @covers Two_Factor_Onboarding::is_allowed_screen
	 */
	public function test_allowed_screens_during_pending_enrollment() {
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/profile.php';
		$this->assertTrue( Two_Factor_Onboarding::is_allowed_screen() );

		$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';
		$this->assertTrue( Two_Factor_Onboarding::is_allowed_screen() );

		$_SERVER['SCRIPT_NAME'] = '/wp-admin/edit.php';
		$this->assertFalse( Two_Factor_Onboarding::is_allowed_screen() );

		$_SERVER['SCRIPT_NAME'] = '/wp-admin/index.php';
		$_GET['page']           = Two_Factor_Onboarding::PAGE_SLUG;
		$this->assertTrue( Two_Factor_Onboarding::is_allowed_screen() );
	}

	/**
	 * The list of reachable screens can be extended.
	 *
	 * @covers Two_Factor_Onboarding::is_allowed_screen
	 */
	public function test_allowed_screens_are_filterable() {
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/edit.php';

		add_filter(
			'two_factor_onboarding_allowed_screens',
			function ( $screens ) {
				$screens[] = 'edit.php';

				return $screens;
			}
		);

		$this->assertTrue( Two_Factor_Onboarding::is_allowed_screen() );
	}

	/**
	 * REST requests from pending users are refused, except the plugin's own routes.
	 *
	 * @covers Two_Factor_Onboarding::filter_rest_authentication_errors
	 */
	public function test_rest_requests_are_refused_while_pending() {
		$this->enforce_enrollment_for( 'editor' );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/posts';
		$result                                  = Two_Factor_Onboarding::filter_rest_authentication_errors( null );
		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_enrollment_required', $result->get_error_code() );

		$GLOBALS['wp']->query_vars['rest_route'] = '/' . Two_Factor_Core::REST_NAMESPACE . '/totp';
		$this->assertNull( Two_Factor_Onboarding::filter_rest_authentication_errors( null ) );

		unset( $GLOBALS['wp']->query_vars['rest_route'] );
	}

	/**
	 * An existing authentication result is never replaced.
	 *
	 * @covers Two_Factor_Onboarding::filter_rest_authentication_errors
	 */
	public function test_rest_filter_keeps_existing_result() {
		$this->enforce_enrollment_for( 'editor' );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( Two_Factor_Onboarding::filter_rest_authentication_errors( true ) );
	}

	/**
	 * Uninstall removes the enforcement method option.
	 *
	 * @covers Two_Factor_Core::uninstall
	 */
	public function test_uninstall_removes_the_enforcement_method_option() {
		update_option( Two_Factor_Onboarding::ENFORCEMENT_METHOD_OPTION_KEY, Two_Factor_Onboarding::METHOD_ENROLLMENT );

		Two_Factor_Core::uninstall();

		$this->assertFalse( get_option( Two_Factor_Onboarding::ENFORCEMENT_METHOD_OPTION_KEY ) );
	}
}
