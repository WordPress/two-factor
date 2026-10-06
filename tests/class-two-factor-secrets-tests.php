<?php
/**
 * Test the Secrets API storage adapter.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Secrets_Tests
 *
 * @package Two_Factor
 * @group secrets
 */
class Two_Factor_Secrets_Tests extends Two_Factor_Secrets_UnitTestCase {

	/**
	 * Secret name format.
	 */
	public function test_get_secret_name_format() {
		$this->assertSame( 'two-factor/totp-42', Two_Factor_Secrets::get_secret_name( 42, 'totp' ) );

		if ( function_exists( 'wp_secrets_validate_name' ) ) {
			$this->assertNotWPError( wp_secrets_validate_name( 'two-factor/totp-42' ) );
		}
	}

	/**
	 * Invalid slugs are rejected.
	 */
	public function test_get_secret_name_rejects_invalid_slug() {
		$this->expectException( InvalidArgumentException::class );
		Two_Factor_Secrets::get_secret_name( 1, 'Bad Slug' );
	}

	/**
	 * Presence reflects loaded functions.
	 */
	public function test_is_api_present_reflects_functions() {
		$this->assertSame( function_exists( 'wp_get_network_secret' ) && class_exists( 'WP_Secret' ), Two_Factor_Secrets::is_api_present() );
	}

	/**
	 * The internal filter can force absence.
	 */
	public function test_is_api_present_internal_filter_forces_absent() {
		$this->simulate_api_absent();
		$this->assertFalse( Two_Factor_Secrets::is_api_present() );
	}

	/**
	 * Writes are allowed with the API present once an administrator has opted in.
	 */
	public function test_can_write_true_with_api_present() {
		$this->require_secrets_api();
		$this->assertTrue( Two_Factor_Secrets::can_write( 1 ) );
	}

	/**
	 * Storage is off until an administrator opts in.
	 */
	public function test_can_write_false_until_opted_in() {
		$this->require_secrets_api();
		$this->opt_out();

		$this->assertFalse( Two_Factor_Secrets::is_opted_in() );
		$this->assertFalse( Two_Factor_Secrets::is_enabled( 1 ) );
		$this->assertFalse( Two_Factor_Secrets::can_write( 1 ) );
	}

	/**
	 * The opt-in round trips and leaves no option behind when turned off.
	 */
	public function test_set_opted_in_round_trip() {
		$this->opt_out();
		$this->assertFalse( get_site_option( Two_Factor_Secrets::OPT_IN_OPTION_KEY ) );

		Two_Factor_Secrets::set_opted_in( true );
		$this->assertTrue( Two_Factor_Secrets::is_opted_in() );

		Two_Factor_Secrets::set_opted_in( false );
		$this->assertFalse( Two_Factor_Secrets::is_opted_in() );
		$this->assertFalse( get_site_option( Two_Factor_Secrets::OPT_IN_OPTION_KEY ) );
	}

	/**
	 * The filter receives the opt-in as its default and can turn storage on without it.
	 */
	public function test_filter_receives_opt_in_and_can_force_on() {
		$this->require_secrets_api();
		$this->opt_out();
		$seen = null;
		add_filter(
			'two_factor_use_secrets_api',
			function ( $enabled ) use ( &$seen ) {
				$seen = $enabled;
				return true;
			}
		);

		$this->assertTrue( Two_Factor_Secrets::can_write( 1 ) );
		$this->assertFalse( $seen );
		$this->assertFalse( Two_Factor_Secrets::is_opted_in() );
	}

	/**
	 * Writes are refused without the API.
	 */
	public function test_can_write_false_when_api_absent() {
		$this->simulate_api_absent();
		$this->assertFalse( Two_Factor_Secrets::can_write( 1 ) );
	}

	/**
	 * The opt-out filter disables writes and receives the user ID.
	 */
	public function test_can_write_false_when_filter_opts_out() {
		$this->require_secrets_api();
		$seen = null;
		add_filter(
			'two_factor_use_secrets_api',
			function ( $enabled, $user_id ) use ( &$seen ) {
				$seen = $user_id;
				return false;
			},
			10,
			2
		);

		$this->assertFalse( Two_Factor_Secrets::can_write( 77 ) );
		$this->assertSame( 77, $seen );
	}

	/**
	 * A read-only provider disables writes.
	 */
	public function test_can_write_false_when_provider_read_only() {
		$this->require_secrets_api();
		Two_Factor_Secrets::$test_overrides['writable'] = '__return_false';

		$this->assertTrue( Two_Factor_Secrets::is_api_present() );
		$this->assertFalse( Two_Factor_Secrets::can_write( 1 ) );
	}

	/**
	 * No marker means null.
	 */
	public function test_get_user_secret_null_without_marker() {
		$user_id = self::factory()->user->create();
		$this->assertNull( Two_Factor_Secrets::get_user_secret( $user_id, 'totp' ) );
	}

	/**
	 * Secrets are stored in network scope.
	 */
	public function test_get_user_secret_round_trip_uses_network_scope() {
		$this->require_secrets_api();
		$user_id = self::factory()->user->create();

		$this->assertTrue( Two_Factor_Secrets::set_user_secret( $user_id, 'totp', 'ABCDEF' ) );
		$this->assertSame( 'ABCDEF', Two_Factor_Secrets::get_user_secret( $user_id, 'totp' ) );
		$this->assertNotNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
		$this->assertNull( wp_get_secret( "two-factor/totp-{$user_id}" ) );
		$this->assertSame(
			(string) get_current_network_id(),
			get_user_meta( $user_id, Two_Factor_Secrets::get_marker_meta_key( 'totp' ), true )
		);

		Two_Factor_Secrets::delete_user_secret( $user_id, 'totp' );
	}

	/**
	 * Marker with no API is an error.
	 */
	public function test_get_user_secret_error_when_api_absent_with_marker() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Two_Factor_Secrets::get_marker_meta_key( 'totp' ), (string) get_current_network_id() );
		$this->simulate_api_absent();

		$result = Two_Factor_Secrets::get_user_secret( $user_id, 'totp' );
		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_secrets_api_missing', $result->get_error_code() );
	}

	/**
	 * Marker naming another network is an error.
	 */
	public function test_get_user_secret_error_when_marker_names_other_network() {
		$this->require_secrets_api();
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Two_Factor_Secrets::get_marker_meta_key( 'totp' ), (string) ( get_current_network_id() + 1 ) );

		$result = Two_Factor_Secrets::get_user_secret( $user_id, 'totp' );
		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_secret_wrong_network', $result->get_error_code() );
	}

	/**
	 * Marker with no stored secret is an error.
	 */
	public function test_get_user_secret_error_when_marker_present_but_secret_gone() {
		$this->require_secrets_api();
		$user_id = self::factory()->user->create();
		Two_Factor_Secrets::set_user_secret( $user_id, 'totp', 'ABCDEF' );
		wp_delete_network_secret( "two-factor/totp-{$user_id}" );

		$result = Two_Factor_Secrets::get_user_secret( $user_id, 'totp' );
		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_secret_missing', $result->get_error_code() );
	}

	/**
	 * API errors pass through.
	 */
	public function test_get_user_secret_passes_through_api_error() {
		$this->require_secrets_api();
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Two_Factor_Secrets::get_marker_meta_key( 'totp' ), (string) get_current_network_id() );
		Two_Factor_Secrets::$test_overrides['get'] = function () {
			return new WP_Error( 'secret_decryption_failed' );
		};

		$result = Two_Factor_Secrets::get_user_secret( $user_id, 'totp' );
		$this->assertWPError( $result );
		$this->assertSame( 'secret_decryption_failed', $result->get_error_code() );
	}

	/**
	 * A failed write leaves no marker.
	 */
	public function test_set_user_secret_error_leaves_marker_absent() {
		$this->require_secrets_api();
		$user_id                                   = self::factory()->user->create();
		Two_Factor_Secrets::$test_overrides['set'] = function () {
			return new WP_Error( 'write_failed' );
		};

		$this->assertWPError( Two_Factor_Secrets::set_user_secret( $user_id, 'totp', 'ABCDEF' ) );
		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Secrets::get_marker_meta_key( 'totp' ), true ) );
	}

	/**
	 * Delete removes secret and marker.
	 */
	public function test_delete_user_secret_removes_secret_and_marker() {
		$this->require_secrets_api();
		$user_id = self::factory()->user->create();
		Two_Factor_Secrets::set_user_secret( $user_id, 'totp', 'ABCDEF' );

		$this->assertTrue( Two_Factor_Secrets::delete_user_secret( $user_id, 'totp' ) );
		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Secrets::get_marker_meta_key( 'totp' ), true ) );
	}

	/**
	 * Delete removes the marker when the API is absent.
	 */
	public function test_delete_user_secret_removes_marker_when_api_absent() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Two_Factor_Secrets::get_marker_meta_key( 'totp' ), (string) get_current_network_id() );
		$this->simulate_api_absent();

		$this->assertTrue( Two_Factor_Secrets::delete_user_secret( $user_id, 'totp' ) );
		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Secrets::get_marker_meta_key( 'totp' ), true ) );
	}

	/**
	 * Deleting a missing secret succeeds.
	 */
	public function test_delete_user_secret_missing_is_success() {
		$this->require_secrets_api();
		$user_id = self::factory()->user->create();

		$this->assertTrue( Two_Factor_Secrets::delete_user_secret( $user_id, 'totp' ) );
	}

	/**
	 * Key-unavailable errors are recognized by code.
	 *
	 * @covers Two_Factor_Secrets::is_key_unavailable_error
	 */
	public function test_is_key_unavailable_error() {
		$this->assertTrue( Two_Factor_Secrets::is_key_unavailable_error( new WP_Error( 'secret_key_unavailable' ) ) );
		$this->assertFalse( Two_Factor_Secrets::is_key_unavailable_error( new WP_Error( 'secret_store_unavailable' ) ) );
		$this->assertFalse( Two_Factor_Secrets::is_key_unavailable_error( 'secret_key_unavailable' ) );
		$this->assertFalse( Two_Factor_Secrets::is_key_unavailable_error( true ) );
	}
}
