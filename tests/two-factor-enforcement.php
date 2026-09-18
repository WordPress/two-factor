<?php
/**
 * Test role-based Two-Factor enforcement.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Enforcement
 *
 * @package Two_Factor
 * @group enforcement
 */
class Tests_Two_Factor_Enforcement extends WP_UnitTestCase {

	/**
	 * Enforced user with no configured providers gets the Email provider injected.
	 *
	 * @covers ::two_factor_enforce_for_user
	 */
	public function test_enforced_role_user_without_providers_gets_email() {
		update_option( 'two_factor_enforced_roles', array( 'subscriber' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $user_id );
		$this->assertSame( array( 'Two_Factor_Email' ), $enabled );

		$available = Two_Factor_Core::get_available_providers_for_user( $user_id );
		$this->assertArrayHasKey( 'Two_Factor_Email', $available );

		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
	}

	/**
	 * Users outside enforced roles are not affected.
	 *
	 * @covers ::two_factor_enforce_for_user
	 */
	public function test_non_enforced_role_user_is_not_affected() {
		update_option( 'two_factor_enforced_roles', array( 'editor' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( array(), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
	}

	/**
	 * No enforcement when the option is not set.
	 *
	 * @covers ::two_factor_enforce_for_user
	 */
	public function test_no_enforcement_by_default() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( array(), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
	}

	/**
	 * A user's own provider configuration is left untouched by enforcement.
	 *
	 * @covers ::two_factor_enforce_for_user
	 */
	public function test_enforcement_does_not_override_existing_providers() {
		update_option( 'two_factor_enforced_roles', array( 'subscriber' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );

		$this->assertSame( array( 'Two_Factor_Totp' ), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
	}

	/**
	 * Enforcement fails closed (no injection) when Email is disabled site-wide.
	 *
	 * @covers ::two_factor_enforce_for_user
	 */
	public function test_enforcement_skipped_when_email_disabled_site_wide() {
		update_option( 'two_factor_enforced_roles', array( 'subscriber' ) );
		update_option( 'two_factor_enabled_providers', array( 'Two_Factor_Totp' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( array(), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
	}

	/**
	 * Enforcement matches any of the user's roles, not just the primary one.
	 *
	 * @covers ::two_factor_enforce_for_user
	 */
	public function test_enforcement_matches_secondary_role() {
		update_option( 'two_factor_enforced_roles', array( 'editor' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_userdata( $user_id );
		$user->add_role( 'editor' );

		$this->assertSame( array( 'Two_Factor_Email' ), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
	}

	/**
	 * New users in an enforced role are enrolled with Email at registration.
	 *
	 * @covers ::two_factor_force_on_user_register
	 */
	public function test_new_user_in_enforced_role_is_enrolled() {
		update_option( 'two_factor_enforced_roles', array( 'subscriber' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true )
		);
		$this->assertSame(
			'Two_Factor_Email',
			get_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true )
		);
	}

	/**
	 * New users outside enforced roles are not enrolled.
	 *
	 * @covers ::two_factor_force_on_user_register
	 */
	public function test_new_user_outside_enforced_role_is_not_enrolled() {
		update_option( 'two_factor_enforced_roles', array( 'editor' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}

	/**
	 * New users are not enrolled when Email is disabled site-wide.
	 *
	 * @covers ::two_factor_force_on_user_register
	 */
	public function test_new_user_not_enrolled_when_email_disabled() {
		update_option( 'two_factor_enforced_roles', array( 'subscriber' ) );
		update_option( 'two_factor_enabled_providers', array( 'Two_Factor_Totp' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}

	/**
	 * Uninstall removes the settings options.
	 *
	 * @covers Two_Factor_Core::uninstall
	 */
	public function test_uninstall_removes_options() {
		update_option( 'two_factor_enforced_roles', array( 'subscriber' ) );
		update_option( 'two_factor_enabled_providers', array( 'Two_Factor_Email' ) );

		Two_Factor_Core::uninstall();

		$this->assertFalse( get_option( 'two_factor_enforced_roles' ) );
		$this->assertFalse( get_option( 'two_factor_enabled_providers' ) );
	}
}
