<?php
/**
 * Test the TOTP provider's Secrets API storage.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Totp_Secrets_Tests
 *
 * @package Two_Factor
 * @group providers
 * @group totp
 * @group secrets
 */
class Two_Factor_Totp_Secrets_Tests extends Two_Factor_Secrets_UnitTestCase {

	/**
	 * Provider under test.
	 *
	 * @var Two_Factor_Totp
	 */
	private $provider;

	/**
	 * Recorded action calls.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = Two_Factor_Totp::get_instance();
		$this->calls    = array();

		$record = function ( $name ) {
			return function () use ( $name ) {
				$this->calls[] = array_merge( array( $name ), func_get_args() );
			};
		};

		add_action( 'two_factor_secrets_migrated', $record( 'migrated' ), 10, 2 );
		add_action( 'two_factor_secrets_migration_failed', $record( 'failed' ), 10, 3 );
	}

	/**
	 * Create a user.
	 *
	 * @return int
	 */
	private function user() {
		return self::factory()->user->create();
	}

	/**
	 * Count recorded calls of a kind.
	 *
	 * @param string $name Action alias.
	 * @return int
	 */
	private function count_calls( $name ) {
		return count(
			array_filter(
				$this->calls,
				function ( $call ) use ( $name ) {
					return $call[0] === $name;
				}
			)
		);
	}

	/**
	 * Read the marker meta.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function marker( $user_id ) {
		return (string) get_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true );
	}

	/**
	 * Read the plaintext meta.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function plaintext( $user_id ) {
		return (string) get_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, true );
	}

	/**
	 * Marker constant matches the adapter.
	 */
	public function test_marker_key_matches_adapter() {
		$this->assertSame( Two_Factor_Secrets::get_marker_meta_key( 'totp' ), Two_Factor_Totp::SECRET_NETWORK_META_KEY );
	}

	/**
	 * Keys are stored in the Secrets API.
	 */
	public function test_set_key_with_api_stores_in_secrets_api() {
		$this->require_secrets_api();
		$user_id = $this->user();

		$this->assertTrue( $this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( (string) get_current_network_id(), $this->marker( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertNotNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
	}

	/**
	 * Keys fall back to plaintext without the API.
	 */
	public function test_set_key_without_api_stores_plaintext() {
		$this->simulate_api_absent();
		$user_id = $this->user();

		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
	}

	/**
	 * A failed Secrets API write never falls back to plaintext.
	 */
	public function test_set_key_returns_false_when_secrets_write_fails() {
		$this->require_secrets_api();
		$user_id                                   = $this->user();
		Two_Factor_Secrets::$test_overrides['set'] = function () {
			return new WP_Error( 'write_failed' );
		};

		$this->assertFalse( $this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}

	/**
	 * A read-back mismatch fails the write and cleans up.
	 */
	public function test_set_key_returns_false_when_readback_mismatches() {
		$this->require_secrets_api();
		$user_id                                   = $this->user();
		Two_Factor_Secrets::$test_overrides['get'] = function () {
			return 'SOMETHINGELSE';
		};

		$this->assertFalse( $this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' ) );
		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}

	/**
	 * Setting an empty key deletes.
	 */
	public function test_set_empty_key_deletes() {
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		$this->provider->set_user_totp_key( $user_id, '' );

		$this->assertSame( '', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
	}

	/**
	 * Delete clears everything.
	 */
	public function test_delete_key_removes_secret_marker_and_plaintext() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'LEFTOVER' );

		$this->assertTrue( $this->provider->delete_user_totp_key( $user_id ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
	}

	/**
	 * Delete clears the marker without the API.
	 */
	public function test_delete_key_removes_marker_when_api_absent() {
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		$this->simulate_api_absent();

		$this->assertTrue( $this->provider->delete_user_totp_key( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}

	/**
	 * Reading a plaintext key migrates it.
	 */
	public function test_lazy_migration_moves_plaintext_to_secrets_api() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( (string) get_current_network_id(), $this->marker( $user_id ) );
		$this->assertSame( 1, $this->count_calls( 'migrated' ) );
		$this->assertSame( array( 'migrated', $user_id, 'totp' ), $this->calls[0] );
	}

	/**
	 * Migration runs once.
	 */
	public function test_lazy_migration_is_idempotent() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$this->provider->get_user_totp_key( $user_id );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( 1, $this->count_calls( 'migrated' ) );
		$this->assertNull( $this->provider->migrate_user_totp_key( $user_id ) );
	}

	/**
	 * A failed write keeps plaintext.
	 */
	public function test_lazy_migration_write_failure_keeps_plaintext_and_fires_failed_action() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );
		Two_Factor_Secrets::$test_overrides['set'] = function () {
			return new WP_Error( 'write_failed' );
		};

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( 1, $this->count_calls( 'failed' ) );
		$this->assertSame( 0, $this->count_calls( 'migrated' ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}

	/**
	 * A read-back mismatch keeps plaintext and cleans up.
	 */
	public function test_lazy_migration_readback_mismatch_keeps_plaintext_and_cleans_up() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );
		Two_Factor_Secrets::$test_overrides['get'] = function () {
			return 'SOMETHINGELSE';
		};

		$result = $this->provider->migrate_user_totp_key( $user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_secrets_migration_mismatch', $result->get_error_code() );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
		$this->assertSame( 1, $this->count_calls( 'failed' ) );
	}

	/**
	 * Opting out skips migration.
	 */
	public function test_lazy_migration_skipped_when_filter_opts_out() {
		$this->require_secrets_api();
		add_filter( 'two_factor_use_secrets_api', '__return_false' );
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * An unreadable secret with the API present keeps TOTP enrolled and does not force the fallback.
	 */
	public function test_unreadable_secret_with_api_present_keeps_totp_and_does_not_force_fallback() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$key     = Two_Factor_Totp::generate_key();
		$this->provider->set_user_totp_key( $user_id, $key );
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );
		$this->make_unreadable();
		$user = get_userdata( $user_id );

		$this->assertTrue( $this->provider->is_available_for_user( $user ) );
		$this->assertFalse( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );
		$this->assertSame( array( 'Two_Factor_Totp' ), array_keys( Two_Factor_Core::get_available_providers_for_user( $user_id ) ) );
		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
		$this->assertFalse( $this->provider->validate_code_for_user( $user, Two_Factor_Totp::calc_totp( $key ) ) );
	}

	/**
	 * Migration is one-directional: opting out never moves a migrated secret back to plaintext.
	 */
	public function test_opt_out_never_moves_migrated_secret_back_to_plaintext() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		add_filter( 'two_factor_use_secrets_api', '__return_false' );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertNull( $this->provider->migrate_user_totp_key( $user_id ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( (string) get_current_network_id(), $this->marker( $user_id ) );
		$this->assertFalse( method_exists( $this->provider, 'export_user_totp_key' ) );
	}

	/**
	 * Plaintext wins over a marker.
	 */
	public function test_plaintext_beats_marker() {
		$this->require_secrets_api();
		add_filter( 'two_factor_use_secrets_api', '__return_false' );
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'PLAINTEXT' );
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );

		$this->assertSame( 'PLAINTEXT', $this->provider->get_user_totp_key_state( $user_id ) );
	}

	/**
	 * No key means null.
	 */
	public function test_key_state_null_without_secret() {
		$this->assertNull( $this->provider->get_user_totp_key_state( $this->user() ) );
	}

	/**
	 * A marker without the API is an error.
	 */
	public function test_key_state_error_when_api_absent_with_marker() {
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		$this->simulate_api_absent();

		$state = $this->provider->get_user_totp_key_state( $user_id );

		$this->assertWPError( $state );
		$this->assertSame( 'two_factor_secrets_api_missing', $state->get_error_code() );
		$this->assertSame( '', $this->provider->get_user_totp_key( $user_id ) );
	}

	/**
	 * A marker for another network is an error.
	 */
	public function test_key_state_error_when_marker_names_other_network() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) ( get_current_network_id() + 1 ) );

		$state = $this->provider->get_user_totp_key_state( $user_id );

		$this->assertWPError( $state );
		$this->assertSame( 'two_factor_secret_wrong_network', $state->get_error_code() );
	}

	/**
	 * Opting out does not stop reads of migrated users.
	 */
	public function test_migrated_user_still_readable_when_filter_opts_out() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );
		add_filter( 'two_factor_use_secrets_api', '__return_false' );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
	}

	/**
	 * Available with marker and API.
	 */
	public function test_is_available_for_user_true_with_marker_and_api() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		$this->assertTrue( $this->provider->is_available_for_user( get_userdata( $user_id ) ) );
	}

	/**
	 * Unavailable with marker but no API.
	 */
	public function test_is_available_for_user_false_with_marker_and_api_absent() {
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		$this->simulate_api_absent();

		$this->assertFalse( $this->provider->is_available_for_user( get_userdata( $user_id ) ) );
	}

	/**
	 * Unavailable with a marker for another network.
	 */
	public function test_is_available_for_user_false_with_marker_for_other_network() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) ( get_current_network_id() + 1 ) );

		$this->assertFalse( $this->provider->is_available_for_user( get_userdata( $user_id ) ) );
	}

	/**
	 * Availability checks never migrate.
	 */
	public function test_is_available_for_user_does_not_migrate() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$this->assertTrue( $this->provider->is_available_for_user( get_userdata( $user_id ) ) );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Enrolled-but-unavailable matrix.
	 */
	public function test_is_enrolled_but_unavailable_for_user_matrix() {
		$user_id = $this->user();
		$user    = get_userdata( $user_id );

		// Nothing stored.
		$this->assertFalse( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );

		// Plaintext.
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );
		$this->assertFalse( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );
		delete_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY );

		// Marker for this network, API present.
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		if ( function_exists( 'wp_get_network_secret' ) ) {
			$this->assertFalse( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );

			// Marker for another network.
			update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) ( get_current_network_id() + 1 ) );
			$this->assertTrue( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );
			update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		}

		// Marker with the API absent.
		$this->simulate_api_absent();
		$this->assertTrue( $this->provider->is_enrolled_but_unavailable_for_user( $user ) );
	}

	/**
	 * Storage descriptions.
	 */
	public function test_get_user_totp_key_storage_values() {
		$this->require_secrets_api();
		$user_id = $this->user();

		$this->assertSame( 'none', $this->provider->get_user_totp_key_storage( $user_id ) );

		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );
		$this->assertSame( 'plaintext', $this->provider->get_user_totp_key_storage( $user_id ) );
		$this->assertSame( 'ABCDEFGH', $this->plaintext( $user_id ), 'Reading storage does not migrate.' );

		$this->provider->delete_user_totp_key( $user_id );
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );
		$this->assertSame( 'secrets-api', $this->provider->get_user_totp_key_storage( $user_id ) );

		$this->simulate_api_absent();
		$this->assertSame( 'unavailable', $this->provider->get_user_totp_key_storage( $user_id ) );
	}

	/**
	 * Uninstall removes the marker.
	 */
	public function test_uninstall_user_meta_keys_include_marker() {
		$this->assertContains( Two_Factor_Totp::SECRET_NETWORK_META_KEY, Two_Factor_Totp::uninstall_user_meta_keys() );
	}

	/**
	 * A secret set on one site validates on another.
	 */
	public function test_secret_set_on_blog_one_validates_on_blog_two() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->require_secrets_api();

		$user_id = $this->user();
		$blog_id = self::factory()->blog->create();
		$key     = Two_Factor_Totp::generate_key();

		$this->provider->set_user_totp_key( $user_id, $key );

		switch_to_blog( $blog_id );
		try {
			$this->assertSame( $key, $this->provider->get_user_totp_key( $user_id ) );
			$this->assertTrue( Two_Factor_Totp::is_valid_authcode( $key, Two_Factor_Totp::calc_totp( $key ) ) );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Record calls to the unavailable action.
	 *
	 * @return void
	 */
	private function record_unavailable() {
		add_action(
			'two_factor_secret_unavailable',
			function () {
				$this->calls[] = array_merge( array( 'unavailable' ), func_get_args() );
			},
			10,
			3
		);
	}

	/**
	 * Make the stored secret unreadable with a decryption error.
	 *
	 * @return void
	 */
	private function make_unreadable() {
		Two_Factor_Secrets::$test_overrides['get'] = function () {
			return new WP_Error( 'secret_decryption_failed', 'sensitive detail' );
		};
	}

	/**
	 * Capture output of a callback.
	 *
	 * @param callable $callback Callback.
	 * @return string
	 */
	private function capture( $callback ) {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * Unreadable secrets fail validation.
	 */
	public function test_validate_code_fails_when_secret_unreadable() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$key     = Two_Factor_Totp::generate_key();
		$this->provider->set_user_totp_key( $user_id, $key );
		$this->make_unreadable();
		$this->record_unavailable();

		$this->assertFalse( $this->provider->validate_code_for_user( get_userdata( $user_id ), Two_Factor_Totp::calc_totp( $key ) ) );
		$this->assertSame( 1, $this->count_calls( 'unavailable' ) );
		$this->assertSame( $user_id, $this->calls[0][1] );
		$this->assertSame( 'totp', $this->calls[0][2] );
		$this->assertInstanceOf( WP_Error::class, $this->calls[0][3] );
	}

	/**
	 * No secret fails validation.
	 */
	public function test_validate_code_fails_when_secret_absent() {
		$user_id = $this->user();

		$this->assertFalse( $this->provider->validate_code_for_user( get_userdata( $user_id ), '123456' ) );
	}

	/**
	 * A Secrets API key validates.
	 */
	public function test_validate_code_succeeds_from_secrets_api() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$key     = Two_Factor_Totp::generate_key();
		$this->provider->set_user_totp_key( $user_id, $key );

		$this->assertTrue( $this->provider->validate_code_for_user( get_userdata( $user_id ), Two_Factor_Totp::calc_totp( $key ) ) );
	}

	/**
	 * A marker without the API fails validation.
	 */
	public function test_validate_authentication_fails_when_api_absent_with_marker() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$key     = Two_Factor_Totp::generate_key();
		$this->provider->set_user_totp_key( $user_id, $key );
		$this->simulate_api_absent();
		$_POST['authcode'] = Two_Factor_Totp::calc_totp( $key );

		try {
			$this->assertFalse( $this->provider->validate_authentication( get_userdata( $user_id ) ) );
		} finally {
			unset( $_POST['authcode'] );
		}
	}

	/**
	 * The login prompt explains the problem.
	 */
	public function test_authentication_page_shows_unavailable_message() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		$this->make_unreadable();
		$this->record_unavailable();
		$user = get_userdata( $user_id );

		$html = $this->capture(
			function () use ( $user ) {
				$this->provider->authentication_page( $user );
			}
		);

		$this->assertStringContainsString( 'currently unavailable on this site', $html );
		$this->assertStringNotContainsString( 'name="authcode"', $html );
		$this->assertStringNotContainsString( 'secret_decryption_failed', $html );
		$this->assertStringNotContainsString( 'sensitive detail', $html );
		$this->assertSame( 1, $this->count_calls( 'unavailable' ) );
	}

	/**
	 * The login prompt is normal when the secret is readable.
	 */
	public function test_authentication_page_normal_when_readable() {
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		$this->record_unavailable();
		$user = get_userdata( $user_id );

		$html = $this->capture(
			function () use ( $user ) {
				$this->provider->authentication_page( $user );
			}
		);

		$this->assertStringContainsString( 'name="authcode"', $html );
		$this->assertStringNotContainsString( 'currently unavailable', $html );
		$this->assertSame( 0, $this->count_calls( 'unavailable' ) );
	}

	/**
	 * The profile offers a reset, not setup, for an unreadable secret.
	 */
	public function test_user_options_shows_reset_not_setup_when_unreadable() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		$this->make_unreadable();
		$this->record_unavailable();
		$user = get_userdata( $user_id );

		$html = $this->capture(
			function () use ( $user ) {
				$this->provider->user_two_factor_options( $user );
			}
		);

		$this->assertStringContainsString( 'Reset authenticator app', $html );
		$this->assertStringContainsString( 'cannot be read', $html );
		$this->assertStringNotContainsString( 'Authentication Code:', $html );
		$this->assertStringNotContainsString( 'two-factor-totp-key', $html );
		$this->assertSame( 1, $this->count_calls( 'unavailable' ) );
	}

	/**
	 * The profile offers setup when there is no secret.
	 */
	public function test_user_options_shows_setup_when_null() {
		$user = get_userdata( $this->user() );

		$html = $this->capture(
			function () use ( $user ) {
				$this->provider->user_two_factor_options( $user );
			}
		);

		$this->assertStringContainsString( 'Authentication Code:', $html );
		$this->assertStringContainsString( 'two-factor-totp-key', $html );
	}

	/**
	 * An affected TOTP-only user is forced onto the fallback, never single factor.
	 */
	public function test_login_fails_closed_for_affected_totp_only_user() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );
		$this->simulate_api_absent();
		$user = get_userdata( $user_id );

		$this->assertFalse( $this->provider->is_available_for_user( $user ) );

		try {
			$available = Two_Factor_Core::get_available_providers_for_user( $user_id );

			$this->assertIsArray( $available );
			$this->assertSame( array( 'Two_Factor_Email' ), array_keys( $available ) );
			$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
			$this->assertSame( $user, Two_Factor_Core::filter_authenticate( $user ) );
			$this->assertNotFalse( has_filter( 'send_auth_cookies', '__return_false' ) );
		} finally {
			remove_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		}
	}

	/**
	 * Without a valid fallback the user is locked out, not let in.
	 */
	public function test_login_fails_closed_for_affected_totp_only_user_without_fallback() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );
		$this->simulate_api_absent();
		add_filter(
			'two_factor_fallback_provider_for_user',
			function () {
				return 'Two_Factor_Nonexistent';
			}
		);

		$this->assertWPError( Two_Factor_Core::get_available_providers_for_user( $user_id ) );
		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
	}

	/**
	 * Backup codes remain usable for an affected user.
	 */
	public function test_affected_user_keeps_backup_codes() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$user    = get_userdata( $user_id );
		$this->provider->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ) );
		$this->simulate_api_absent();

		$this->assertSame( array( 'Two_Factor_Backup_Codes' ), array_keys( Two_Factor_Core::get_available_providers_for_user( $user_id ) ) );
	}

	/**
	 * Deleting a user removes the secret on single site.
	 */
	public function test_delete_user_removes_secret_on_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single site only.' );
		}
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		wp_delete_user( $user_id );

		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
	}

	/**
	 * Removing a user from one site keeps the network secret.
	 */
	public function test_delete_user_keeps_secret_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->require_secrets_api();
		$user_id = $this->user();
		$blog_id = self::factory()->blog->create();
		add_user_to_blog( $blog_id, $user_id, 'subscriber' );
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		switch_to_blog( $blog_id );
		try {
			wp_delete_user( $user_id );
		} finally {
			restore_current_blog();
		}

		$this->assertNotNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
		$this->assertSame( (string) get_current_network_id(), $this->marker( $user_id ) );
	}

	/**
	 * Network deletion removes the secret.
	 */
	public function test_wpmu_delete_user_removes_secret_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->require_secrets_api();
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		wpmu_delete_user( $user_id );

		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}

	/**
	 * The right deletion hook is registered.
	 */
	public function test_registers_correct_deletion_hook() {
		$callback = array( 'Two_Factor_Totp', 'delete_user_secrets_on_user_deletion' );

		$this->assertNotFalse( has_action( is_multisite() ? 'wpmu_delete_user' : 'delete_user', $callback ) );
		$this->assertFalse( has_action( is_multisite() ? 'delete_user' : 'wpmu_delete_user', $callback ) );
	}

	/**
	 * Lifecycle hooks stay registered, and still delete secrets, when TOTP is disabled site-wide.
	 */
	public function test_lifecycle_hooks_work_when_totp_disabled_site_wide() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );

		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		try {
			$this->assertArrayNotHasKey( 'Two_Factor_Totp', Two_Factor_Core::get_providers() );

			$this->assertNotFalse( has_filter( 'site_status_tests', array( 'Two_Factor_Totp', 'register_site_health_test' ) ) );
			$this->assertNotFalse( has_action( 'admin_notices', array( 'Two_Factor_Totp', 'admin_notice_secrets_api_missing' ) ) );
			$this->assertNotFalse( has_action( 'network_admin_notices', array( 'Two_Factor_Totp', 'admin_notice_secrets_api_missing' ) ) );

			if ( is_multisite() ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
				wpmu_delete_user( $user_id );
			} else {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id );
			}

			$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
		} finally {
			delete_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );
		}
	}

	/**
	 * Uninstall removes all secrets.
	 */
	public function test_uninstall_user_data_removes_secrets_for_all_marked_users() {
		$this->require_secrets_api();
		$user_ids = array( $this->user(), $this->user(), $this->user() );
		foreach ( $user_ids as $user_id ) {
			$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );
		}

		Two_Factor_Totp::uninstall_user_data();

		foreach ( $user_ids as $user_id ) {
			$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
			$this->assertSame( '', $this->marker( $user_id ) );
		}
	}

	/**
	 * Uninstall does nothing without the API.
	 */
	public function test_uninstall_user_data_noop_when_api_absent() {
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		$this->simulate_api_absent();

		Two_Factor_Totp::uninstall_user_data();

		$this->assertSame( (string) get_current_network_id(), $this->marker( $user_id ) );
	}

	/**
	 * Seed a marker for a fresh user.
	 *
	 * @param string|null $network Marker value; defaults to the current network.
	 * @return int User ID.
	 */
	private function seed_marker( $network = null ) {
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, null === $network ? (string) get_current_network_id() : $network );
		Two_Factor_Totp::clear_affected_users_cache();

		return $user_id;
	}

	/**
	 * No markers means no affected users.
	 */
	public function test_has_affected_users_false_without_markers() {
		$this->assertFalse( Two_Factor_Totp::has_affected_users() );
	}

	/**
	 * A marker with no API affects users.
	 */
	public function test_has_affected_users_true_when_api_absent_with_marker() {
		$this->seed_marker();
		$this->simulate_api_absent();

		$this->assertTrue( Two_Factor_Totp::has_affected_users() );
	}

	/**
	 * A marker for another network affects users.
	 */
	public function test_has_affected_users_true_for_other_network_marker() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->require_secrets_api();
		$this->seed_marker( (string) ( get_current_network_id() + 1 ) );

		$this->assertTrue( Two_Factor_Totp::has_affected_users() );
	}

	/**
	 * The result is cached.
	 */
	public function test_has_affected_users_is_cached() {
		$this->simulate_api_absent();
		Two_Factor_Totp::clear_affected_users_cache();

		$this->assertFalse( Two_Factor_Totp::has_affected_users() );

		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );

		$this->assertFalse( Two_Factor_Totp::has_affected_users() );

		Two_Factor_Totp::clear_affected_users_cache();

		$this->assertTrue( Two_Factor_Totp::has_affected_users() );
	}

	/**
	 * Administrators see the notice.
	 */
	public function test_admin_notice_shown_to_manage_options_user() {
		$this->seed_marker();
		$this->simulate_api_absent();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		$html = $this->capture(
			function () {
				$this->provider->admin_notice_secrets_api_missing();
			}
		);

		$this->assertStringContainsString( 'reset those users', $html );
		$this->assertStringNotContainsString( 'export', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( 'is-dismissible', $html );
	}

	/**
	 * Others do not.
	 */
	public function test_admin_notice_hidden_without_capability() {
		$this->seed_marker();
		$this->simulate_api_absent();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame(
			'',
			$this->capture(
				function () {
					$this->provider->admin_notice_secrets_api_missing();
				}
			)
		);
	}

	/**
	 * No notice without affected users.
	 */
	public function test_admin_notice_hidden_when_no_affected_users() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		$this->assertSame(
			'',
			$this->capture(
				function () {
					$this->provider->admin_notice_secrets_api_missing();
				}
			)
		);
	}

	/**
	 * The network admin notice needs manage_network_options.
	 */
	public function test_admin_notice_network_admin_requires_manage_network_options() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->seed_marker();
		$this->simulate_api_absent();
		set_current_screen( 'dashboard-network' );

		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->assertSame(
				'',
				$this->capture(
					function () {
						$this->provider->admin_notice_secrets_api_missing();
					}
				)
			);

			grant_super_admin( get_current_user_id() );
			$this->assertStringContainsString(
				'reset those users',
				$this->capture(
					function () {
						$this->provider->admin_notice_secrets_api_missing();
					}
				)
			);
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * The Site Health test is registered.
	 */
	public function test_site_health_test_is_registered() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayHasKey( 'two_factor_totp_secret_storage', $tests['direct'] );
	}

	/**
	 * Affected users are critical.
	 */
	public function test_site_health_critical_when_affected_users() {
		$this->seed_marker();
		$this->simulate_api_absent();

		$result = $this->provider->site_health_secret_storage();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'red', $result['badge']['color'] );
		$this->assertStringContainsString( 'reset those users', $result['description'] );
		$this->assertStringNotContainsString( 'export', $result['description'] );
		$this->assertSame( 'two_factor_totp_secret_storage', $result['test'] );
	}

	/**
	 * Remaining plaintext is recommended to migrate.
	 */
	public function test_site_health_recommended_when_plaintext_remains() {
		$this->require_secrets_api();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$result = $this->provider->site_health_secret_storage();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertStringContainsString( 'wp two-factor secrets migrate', $result['description'] );
	}

	/**
	 * Fully migrated is good and names the provider.
	 */
	public function test_site_health_good_when_fully_migrated() {
		$this->require_secrets_api();

		$result = $this->provider->site_health_secret_storage();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( esc_html( Two_Factor_Secrets::provider_label() ), $result['description'] );
	}

	/**
	 * Opting out with plaintext remaining is good.
	 */
	public function test_site_health_good_when_filter_opts_out_with_plaintext() {
		$this->require_secrets_api();
		add_filter( 'two_factor_use_secrets_api', '__return_false' );
		update_user_meta( $this->user(), Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$result = $this->provider->site_health_secret_storage();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'two_factor_use_secrets_api', $result['description'] );
	}

	/**
	 * Without the API the message is neutral.
	 */
	public function test_site_health_good_neutral_when_api_absent() {
		$this->simulate_api_absent();

		$result = $this->provider->site_health_secret_storage();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame(
			'<p>TOTP secrets are stored in user meta. When the WordPress Secrets API is available, an administrator can choose to store them encrypted instead.</p>',
			$result['description']
		);
	}

	/**
	 * Until an administrator opts in, Site Health recommends it and links to the settings screen.
	 */
	public function test_site_health_recommended_when_not_opted_in() {
		$this->require_secrets_api();
		$this->opt_out();

		$result = $this->provider->site_health_secret_storage();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertStringContainsString( 'one-way change', $result['description'] );
		$this->assertStringContainsString( 'page=two-factor-settings', $result['actions'] );
	}

	/**
	 * A read-only provider is reported as such rather than blamed on the filter.
	 */
	public function test_site_health_good_when_provider_read_only() {
		$this->require_secrets_api();
		Two_Factor_Secrets::$test_overrides['writable'] = '__return_false';

		$result = $this->provider->site_health_secret_storage();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'read-only', $result['description'] );
	}

	/**
	 * Without the opt-in a new key stays in user meta, even with the API present.
	 */
	public function test_set_key_without_opt_in_stores_plaintext() {
		$this->require_secrets_api();
		$this->opt_out();
		$user_id = $this->user();

		$this->assertTrue( (bool) $this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' ) );

		$this->assertSame( 'ABCDEFGH', get_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true ) );
		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
	}

	/**
	 * Without the opt-in a plaintext key is read but not migrated.
	 */
	public function test_lazy_migration_skipped_without_opt_in() {
		$this->require_secrets_api();
		$this->opt_out();
		$user_id = $this->user();
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( 'ABCDEFGH', get_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true ) );
	}

	/**
	 * Opting back out keeps already-migrated users working.
	 */
	public function test_migrated_user_still_readable_after_opting_out() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, 'ABCDEFGH' );
		$this->opt_out();

		$this->assertSame( 'ABCDEFGH', $this->provider->get_user_totp_key( $user_id ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, true ) );
	}

	/**
	 * Make the Secrets API behave as it does after WP_SECRETS_KEY changed: its root key
	 * cannot be unwrapped, so every read and write fails, while deletes still work.
	 */
	private function make_key_unavailable() {
		$error = function () {
			return new WP_Error( 'secret_key_unavailable', 'The wrapped key material could not be decrypted with the configured site key.' );
		};

		Two_Factor_Secrets::$test_overrides['get'] = $error;
		Two_Factor_Secrets::$test_overrides['set'] = $error;
	}

	/**
	 * POST a TOTP setup request for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     TOTP key.
	 * @return WP_REST_Response
	 */
	private function rest_setup( $user_id, $key ) {
		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id'         => $user_id,
				'key'             => $key,
				'code'            => $this->provider->calc_totp( $key ),
				'enable_provider' => true,
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * Create an administrator who can manage their own options on either install type.
	 *
	 * @return int
	 */
	private function admin_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * With an unusable Secrets API key, the profile says to restore the key and offers no reset,
	 * since a reset could not be followed by re-enrollment.
	 */
	public function test_user_options_offers_no_reset_when_key_unavailable() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, Two_Factor_Totp::generate_key() );
		$this->make_key_unavailable();
		$user = get_userdata( $user_id );

		$html = $this->capture(
			function () use ( $user ) {
				$this->provider->user_two_factor_options( $user );
			}
		);

		$this->assertStringContainsString( 'two-factor-totp-key-unavailable', $html );
		$this->assertStringContainsString( 'WP_SECRETS_KEY', $html );
		$this->assertStringNotContainsString( 'reset-totp-key', $html );
		$this->assertStringNotContainsString( 'two-factor-totp-key"', $html );
	}

	/**
	 * With an unusable Secrets API key, the REST reset is refused and the user stays enrolled,
	 * so they are kept out rather than left with a password alone.
	 *
	 * @covers Two_Factor_Totp::rest_delete_totp
	 */
	public function test_rest_reset_refused_when_key_unavailable() {
		$this->require_secrets_api();
		$user_id = $this->user();
		$this->provider->set_user_totp_key( $user_id, $this->provider->generate_key() );
		Two_Factor_Core::enable_provider_for_user( $user_id, 'Two_Factor_Totp' );
		$this->make_key_unavailable();

		// An administrator resets another user, as on the user-edit screen.
		$this->admin_user();

		$request = new WP_REST_Request( 'DELETE', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params( array( 'user_id' => $user_id ) );
		$response = rest_do_request( $request );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'two_factor_secrets_key_unavailable', $response->as_error()->get_error_code() );
		$this->assertSame( (string) get_current_network_id(), $this->marker( $user_id ) );
		$this->assertContains( 'Two_Factor_Totp', Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
	}

	/**
	 * Regression: re-enrolling after a reset under a changed secrets key fails closed with a
	 * clear 503, stores nothing, and works once the key is usable again. The REST reset is
	 * refused in this state, but a reset can still happen another way, for example through
	 * `wp two-factor disable`.
	 *
	 * @covers Two_Factor_Totp::rest_setup_totp
	 * @covers Two_Factor_Totp::set_user_totp_key
	 */
	public function test_reenroll_after_reset_with_unavailable_key_fails_closed_with_clear_error() {
		$this->require_secrets_api();
		$user_id = $this->admin_user();
		$this->assertTrue( $this->provider->set_user_totp_key( $user_id, $this->provider->generate_key() ) );
		Two_Factor_Core::enable_provider_for_user( $user_id, 'Two_Factor_Totp' );

		$this->make_key_unavailable();
		$this->assertWPError( $this->provider->get_user_totp_key_state( $user_id ) );

		// A reset outside the REST endpoint, as `wp two-factor disable` performs it.
		Two_Factor_Core::disable_provider_for_user( $user_id, 'Two_Factor_Totp' );
		$this->assertTrue( $this->provider->delete_user_totp_key( $user_id ) );

		$new_key  = $this->provider->generate_key();
		$response = $this->rest_setup( $user_id, $new_key );
		$error    = $response->as_error();

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'two_factor_secrets_key_unavailable', $error->get_error_code() );
		$this->assertStringContainsString( 'WP_SECRETS_KEY', $error->get_error_message() );
		$this->assertStringNotContainsString( $new_key, wp_json_encode( $response->get_data() ) );

		// Fail closed: no plaintext fallback, no marker, TOTP not enabled.
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
		$this->assertNotContains( 'Two_Factor_Totp', Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
		$this->assertFalse( $this->provider->set_user_totp_key( $user_id, $new_key ) );

		// Once the key is usable again, the same enrollment succeeds.
		Two_Factor_Secrets::$test_overrides = array();

		$response = $this->rest_setup( $user_id, $new_key );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $new_key, $this->provider->get_user_totp_key_state( $user_id ) );
		$this->assertSame( (string) get_current_network_id(), $this->marker( $user_id ) );
		$this->assertSame( '', $this->plaintext( $user_id ) );
	}

	/**
	 * Any other Secrets API write failure is reported as such, and stores nothing.
	 *
	 * @covers Two_Factor_Totp::rest_setup_totp
	 */
	public function test_rest_setup_reports_secrets_write_failure() {
		$this->require_secrets_api();
		$user_id = $this->admin_user();

		Two_Factor_Secrets::$test_overrides['set'] = function () {
			return new WP_Error( 'secret_store_unavailable', 'sensitive detail' );
		};

		$response = $this->rest_setup( $user_id, $this->provider->generate_key() );
		$error    = $response->as_error();

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'two_factor_secrets_write_failed', $error->get_error_code() );
		$this->assertStringNotContainsString( 'sensitive detail', $error->get_error_message() );
		$this->assertSame( '', $this->plaintext( $user_id ) );
		$this->assertSame( '', $this->marker( $user_id ) );
	}
}
