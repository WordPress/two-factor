<?php
/**
 * Test Two Factor.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor
 *
 * @package Two_Factor
 * @group core
 */
class Tests_Two_Factor extends WP_UnitTestCase {

	/**
	 * Check that the TWO_FACTOR_DIR constant is defined.
	 */
	public function test_constant_defined() {

		$this->assertTrue( defined( 'TWO_FACTOR_DIR' ) );
	}

	/**
	 * Check that the files were included.
	 */
	public function test_classes_exist() {

		$this->assertTrue( class_exists( 'Two_Factor_Provider' ) );
		$this->assertTrue( class_exists( 'Two_Factor_Core' ) );
	}

	/**
	 * The wp-config bypass hooks are registered on init.
	 *
	 * @covers ::two_factor_register_admin_hooks
	 */
	public function test_bypass_hooks_are_registered() {
		$this->assertNotFalse(
			has_filter( 'two_factor_primary_provider_for_user', 'two_factor_bypass_primary_provider_for_user' ),
			'Bypass filter should be hooked to two_factor_primary_provider_for_user'
		);

		$this->assertNotFalse(
			has_action( 'admin_notices', 'two_factor_bypass_admin_notice' ),
			'Bypass admin notice should be hooked to admin_notices'
		);
	}

	/**
	 * With the constant undefined, the primary provider is returned unchanged.
	 *
	 * TWO_FACTOR_DISABLE_FOR_USER is never defined in the main test process, so
	 * this exercises the "constant not set" short-circuit without isolation.
	 *
	 * @covers ::two_factor_bypass_primary_provider_for_user
	 */
	public function test_bypass_is_noop_when_constant_not_defined() {
		$this->assertFalse(
			defined( 'TWO_FACTOR_DISABLE_FOR_USER' ),
			'Guard: this test assumes the constant is not defined in the main process'
		);

		$user_id = self::factory()->user->create();

		$this->assertSame(
			'Two_Factor_Dummy',
			two_factor_bypass_primary_provider_for_user( 'Two_Factor_Dummy', $user_id ),
			'Provider should be returned unchanged when the constant is not set'
		);

		$this->assertNull(
			two_factor_bypass_primary_provider_for_user( null, $user_id ),
			'A null provider is passed through unchanged when the constant is not set'
		);
	}

	/**
	 * With the constant undefined, the admin notice renders nothing.
	 *
	 * @covers ::two_factor_bypass_admin_notice
	 */
	public function test_bypass_admin_notice_is_empty_when_constant_not_defined() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		two_factor_bypass_admin_notice();
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'No notice should render when the constant is not set' );
	}

	/**
	 * The constant matches a named user by ID, login, or email (array form),
	 * leaves unlisted users alone, and ignores unknown user IDs.
	 *
	 * @covers ::two_factor_bypass_primary_provider_for_user
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bypass_matches_named_user_by_id_login_or_email() {
		$by_id    = self::factory()->user->create( array( 'user_login' => 'match_by_id' ) );
		$by_login = self::factory()->user->create( array( 'user_login' => 'match_by_login' ) );
		$by_email = self::factory()->user->create(
			array(
				'user_login' => 'match_by_email',
				'user_email' => 'recover-me@example.com',
			)
		);
		$unlisted = self::factory()->user->create( array( 'user_login' => 'not_listed' ) );

		$user_by_login = get_userdata( $by_login );
		$user_by_email = get_userdata( $by_email );

		// Array form covers all three identifier types plus a non-existent one.
		if ( ! defined( 'TWO_FACTOR_DISABLE_FOR_USER' ) ) {
			define(
				'TWO_FACTOR_DISABLE_FOR_USER',
				array(
					(string) $by_id,
					$user_by_login->user_login,
					$user_by_email->user_email,
					'ghost-does-not-exist',
				)
			);
		}

		$this->assertNull(
			two_factor_bypass_primary_provider_for_user( 'Two_Factor_Dummy', $by_id ),
			'User matched by ID should be bypassed'
		);
		$this->assertNull(
			two_factor_bypass_primary_provider_for_user( 'Two_Factor_Dummy', $by_login ),
			'User matched by login should be bypassed'
		);
		$this->assertNull(
			two_factor_bypass_primary_provider_for_user( 'Two_Factor_Dummy', $by_email ),
			'User matched by email should be bypassed'
		);

		$this->assertSame(
			'Two_Factor_Dummy',
			two_factor_bypass_primary_provider_for_user( 'Two_Factor_Dummy', $unlisted ),
			'A user not named in the constant should keep their provider'
		);

		$this->assertSame(
			'Two_Factor_Dummy',
			two_factor_bypass_primary_provider_for_user( 'Two_Factor_Dummy', PHP_INT_MAX ),
			'An unknown user ID should keep the provider unchanged'
		);
	}

	/**
	 * A comma-separated string with surrounding whitespace matches each user.
	 *
	 * @covers ::two_factor_bypass_primary_provider_for_user
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bypass_matches_comma_separated_string_and_trims_whitespace() {
		$first    = self::factory()->user->create( array( 'user_login' => 'csv_first' ) );
		$second   = self::factory()->user->create( array( 'user_login' => 'csv_second' ) );
		$unlisted = self::factory()->user->create( array( 'user_login' => 'csv_unlisted' ) );

		$first_login  = get_userdata( $first )->user_login;
		$second_login = get_userdata( $second )->user_login;

		if ( ! defined( 'TWO_FACTOR_DISABLE_FOR_USER' ) ) {
			// Deliberately padded with spaces to prove identifiers are trimmed.
			define( 'TWO_FACTOR_DISABLE_FOR_USER', $first_login . ' ,  ' . $second_login );
		}

		$this->assertNull(
			two_factor_bypass_primary_provider_for_user( 'Two_Factor_Dummy', $first ),
			'First CSV entry should be bypassed'
		);
		$this->assertNull(
			two_factor_bypass_primary_provider_for_user( 'Two_Factor_Dummy', $second ),
			'Second CSV entry (with padding) should be bypassed'
		);
		$this->assertSame(
			'Two_Factor_Dummy',
			two_factor_bypass_primary_provider_for_user( 'Two_Factor_Dummy', $unlisted ),
			'A user absent from the CSV list should keep their provider'
		);
	}

	/**
	 * End-to-end: the constant disables the two-factor requirement for the named
	 * user while leaving other two-factor users challenged.
	 *
	 * @covers ::two_factor_bypass_primary_provider_for_user
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bypass_disables_two_factor_for_named_user_only() {
		$bypassed_id = self::factory()->user->create( array( 'user_login' => 'locked_out_admin' ) );
		$control_id  = self::factory()->user->create( array( 'user_login' => 'other_2fa_user' ) );

		// Enable the always-available dummy provider for both users.
		foreach ( array( $bypassed_id, $control_id ) as $uid ) {
			update_user_meta( $uid, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Dummy' ) );
			update_user_meta( $uid, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Dummy' );
		}

		// Sanity check before the bypass is in effect.
		$this->assertTrue(
			Two_Factor_Core::is_user_using_two_factor( $control_id ),
			'Control user should be using two-factor'
		);

		if ( ! defined( 'TWO_FACTOR_DISABLE_FOR_USER' ) ) {
			define( 'TWO_FACTOR_DISABLE_FOR_USER', 'locked_out_admin' );
		}

		$this->assertFalse(
			Two_Factor_Core::is_user_using_two_factor( $bypassed_id ),
			'The named user should no longer be treated as using two-factor'
		);
		$this->assertTrue(
			Two_Factor_Core::is_user_using_two_factor( $control_id ),
			'A user not named in the constant should still be using two-factor'
		);
	}

	/**
	 * The admin notice renders (with the configured value) for users who can
	 * manage options, and stays hidden from everyone else.
	 *
	 * @covers ::two_factor_bypass_admin_notice
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bypass_admin_notice_visibility_and_contents() {
		if ( ! defined( 'TWO_FACTOR_DISABLE_FOR_USER' ) ) {
			define( 'TWO_FACTOR_DISABLE_FOR_USER', array( 'admin', 'secondadmin' ) );
		}

		// Administrator (manage_options) sees the notice with the configured value.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		two_factor_bypass_admin_notice();
		$admin_output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $admin_output, 'Admin should see a warning notice' );
		$this->assertStringContainsString( 'TWO_FACTOR_DISABLE_FOR_USER', $admin_output, 'Notice should name the constant' );
		$this->assertStringContainsString( 'admin, secondadmin', $admin_output, 'Notice should echo the configured value' );

		// A user without manage_options sees nothing.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		two_factor_bypass_admin_notice();
		$subscriber_output = ob_get_clean();

		$this->assertSame( '', $subscriber_output, 'Users without manage_options should not see the notice' );
	}
}
