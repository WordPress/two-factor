<?php
/**
 * Test how core looks after the secrets providers declare.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Secrets_Lifecycle_Tests
 *
 * Uses Two_Factor_Dummy_Secret, a provider other than TOTP that declares a secret,
 * to show that nothing here is specific to TOTP.
 *
 * @package Two_Factor
 * @group secrets
 */
class Two_Factor_Secrets_Lifecycle_Tests extends Two_Factor_Secrets_UnitTestCase {

	/**
	 * Register the fixture provider.
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__ ) . '/class-two-factor-dummy-secret.php';
		Two_Factor_Dummy_Secret::$secrets = array( 'dummy' => Two_Factor_Dummy_Secret::SECRET_META_KEY );

		// Before the site-wide setting is enforced at the default priority, as a plugin loaded earlier would be.
		add_filter( 'two_factor_providers', array( $this, 'register_fixture' ), 5 );
	}

	/**
	 * Unregister the fixture provider.
	 */
	public function tear_down() {
		remove_filter( 'two_factor_providers', array( $this, 'register_fixture' ), 5 );
		Two_Factor_Dummy_Secret::$secrets = array( 'dummy' => Two_Factor_Dummy_Secret::SECRET_META_KEY );
		delete_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Add the fixture provider to the registered providers.
	 *
	 * @param array $providers Registered providers.
	 * @return array
	 */
	public function register_fixture( $providers ) {
		$providers['Two_Factor_Dummy_Secret'] = dirname( __DIR__ ) . '/class-two-factor-dummy-secret.php';

		return $providers;
	}

	/**
	 * Store the fixture's secret for a new user.
	 *
	 * @return int User ID.
	 */
	private function user_with_stored_secret() {
		$user_id = self::factory()->user->create();
		Two_Factor_Secrets::save_stored_secret( $user_id, 'dummy', Two_Factor_Dummy_Secret::SECRET_META_KEY, 'DUMMYSECRET' );

		return $user_id;
	}

	/**
	 * A provider that declares nothing keeps no secrets.
	 *
	 * @covers Two_Factor_Provider::user_secret_meta_keys
	 */
	public function test_base_provider_declares_no_secrets() {
		$this->assertSame( array(), Two_Factor_Dummy::user_secret_meta_keys() );
		$this->assertSame( array(), Two_Factor_Email::user_secret_meta_keys() );
	}

	/**
	 * The registry lists TOTP and the fixture, with the provider that declared each.
	 *
	 * @covers Two_Factor_Core::get_provider_secrets
	 */
	public function test_registry_lists_declared_secrets() {
		$secrets = Two_Factor_Core::get_provider_secrets();

		$this->assertSame(
			array(
				'meta_key' => Two_Factor_Totp::SECRET_META_KEY,
				'provider' => 'Two_Factor_Totp',
			),
			$secrets['totp']
		);
		$this->assertSame(
			array(
				'meta_key' => Two_Factor_Dummy_Secret::SECRET_META_KEY,
				'provider' => 'Two_Factor_Dummy_Secret',
			),
			$secrets['dummy']
		);
	}

	/**
	 * A provider the site has turned off is still in the registry, and the setting is still enforced afterwards.
	 *
	 * @covers Two_Factor_Core::get_provider_secrets
	 */
	public function test_registry_includes_providers_disabled_site_wide() {
		$this->assertNotFalse( has_filter( 'two_factor_providers', 'two_factor_filter_enabled_providers' ) );
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		$this->assertArrayNotHasKey( 'Two_Factor_Dummy_Secret', Two_Factor_Core::get_providers() );

		$secrets = Two_Factor_Core::get_provider_secrets();

		$this->assertArrayHasKey( 'dummy', $secrets );
		$this->assertArrayHasKey( 'totp', $secrets );
		$this->assertFalse( Two_Factor_Core::is_listing_all_providers() );
		$this->assertArrayNotHasKey( 'Two_Factor_Dummy_Secret', Two_Factor_Core::get_providers() );
	}

	/**
	 * Invalid declarations are ignored.
	 *
	 * @covers Two_Factor_Core::get_provider_secrets
	 */
	public function test_registry_ignores_invalid_declarations() {
		Two_Factor_Dummy_Secret::$secrets = array(
			'Bad Slug' => '_two_factor_bad',
			'empty'    => '',
			0          => '_two_factor_numeric',
			'good'     => '_two_factor_good',
		);

		$secrets = Two_Factor_Core::get_provider_secrets();

		$this->assertSame( array( 'totp', 'good' ), array_keys( $secrets ) );
	}

	/**
	 * A slug another provider already declared is refused, and the first declaration wins.
	 *
	 * @covers Two_Factor_Core::get_provider_secrets
	 */
	public function test_registry_refuses_duplicate_slug() {
		Two_Factor_Dummy_Secret::$secrets = array( 'totp' => Two_Factor_Dummy_Secret::SECRET_META_KEY );
		$this->setExpectedIncorrectUsage( 'Two_Factor_Core::get_provider_secrets' );

		$secrets = Two_Factor_Core::get_provider_secrets();

		$this->assertSame( 'Two_Factor_Totp', $secrets['totp']['provider'] );
		$this->assertSame( Two_Factor_Totp::SECRET_META_KEY, $secrets['totp']['meta_key'] );
	}

	/**
	 * A declared secret is written to the store, read back and reported, under its own name.
	 *
	 * @covers Two_Factor_Secrets_Manager::save_stored_secret
	 * @covers Two_Factor_Secrets_Manager::get_stored_secret_state
	 * @covers Two_Factor_Secrets_Manager::get_stored_secret_storage
	 */
	public function test_declared_secret_round_trips_through_the_store() {
		$user_id = $this->user_with_stored_secret();

		$this->assertSame( 'DUMMYSECRET', $this->secrets_store->get( "two-factor/dummy-{$user_id}" ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Two_Factor_Dummy_Secret::SECRET_META_KEY, true ) );
		$this->assertSame( 'DUMMYSECRET', Two_Factor_Secrets::get_stored_secret_state( $user_id, 'dummy', Two_Factor_Dummy_Secret::SECRET_META_KEY ) );
		$this->assertSame( 'secrets-api', Two_Factor_Secrets::get_stored_secret_storage( $user_id, 'dummy', Two_Factor_Dummy_Secret::SECRET_META_KEY ) );
	}

	/**
	 * A plaintext value is migrated when it is read, and the action names the secret.
	 *
	 * @covers Two_Factor_Secrets_Manager::migrate_stored_secret
	 */
	public function test_declared_secret_migrates_lazily() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Two_Factor_Dummy_Secret::SECRET_META_KEY, 'DUMMYSECRET' );
		$seen = array();
		add_action(
			'two_factor_secrets_migrated',
			function ( $migrated_user_id, $slug ) use ( &$seen ) {
				$seen[] = array( $migrated_user_id, $slug );
			},
			10,
			2
		);

		$this->assertSame( 'DUMMYSECRET', Two_Factor_Secrets::get_stored_secret_state( $user_id, 'dummy', Two_Factor_Dummy_Secret::SECRET_META_KEY ) );

		$this->assertSame( array( array( $user_id, 'dummy' ) ), $seen );
		$this->assertSame( 'DUMMYSECRET', $this->secrets_store->get( "two-factor/dummy-{$user_id}" ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Two_Factor_Dummy_Secret::SECRET_META_KEY, true ) );
	}

	/**
	 * The base provider reports an unreachable declared secret as enrolled but unavailable.
	 *
	 * @covers Two_Factor_Provider::is_enrolled_but_unavailable_for_user
	 */
	public function test_base_provider_reports_unreachable_declared_secret() {
		$user     = get_user_by( 'id', $this->user_with_stored_secret() );
		$provider = Two_Factor_Dummy_Secret::get_instance();

		$this->assertFalse( $provider->is_enrolled_but_unavailable_for_user( $user ) );

		$this->simulate_api_absent();

		$this->assertTrue( $provider->is_enrolled_but_unavailable_for_user( $user ) );
		$this->assertFalse( Two_Factor_Dummy::get_instance()->is_enrolled_but_unavailable_for_user( $user ) );
	}

	/**
	 * Deleting a user deletes every declared secret, including for a provider turned off site-wide.
	 *
	 * @covers Two_Factor_Secrets_Lifecycle::delete_user_secrets
	 */
	public function test_user_deletion_removes_declared_secrets() {
		$user_id = $this->user_with_stored_secret();
		Two_Factor_Totp::get_instance()->set_user_totp_key( $user_id, 'ABCDEFGH' );
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $user_id );
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}

		$this->assertSame( array(), $this->secrets_store->values );
	}

	/**
	 * Uninstall deletes every declared secret and its marker.
	 *
	 * @covers Two_Factor_Core::uninstall
	 * @covers Two_Factor_Secrets_Lifecycle::delete_all_secrets
	 */
	public function test_uninstall_removes_declared_secrets_and_markers() {
		$user_id = $this->user_with_stored_secret();
		Two_Factor_Totp::get_instance()->set_user_totp_key( $user_id, 'ABCDEFGH' );

		Two_Factor_Core::uninstall();

		$this->assertSame( array(), $this->secrets_store->values );
		$this->assertSame( '', (string) get_user_meta( $user_id, Two_Factor_Secrets::get_marker_meta_key( 'dummy' ), true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true ) );
	}

	/**
	 * An unreachable secret of any provider counts as an affected user and turns Site Health critical.
	 *
	 * @covers Two_Factor_Secrets_Lifecycle::has_affected_users
	 * @covers Two_Factor_Secrets_Lifecycle::site_health_secret_storage
	 */
	public function test_unreachable_declared_secret_is_reported() {
		$this->user_with_stored_secret();
		$this->assertFalse( Two_Factor_Secrets_Lifecycle::has_affected_users() );

		$this->simulate_api_absent();
		Two_Factor_Secrets::clear_affected_users_cache();

		$this->assertTrue( Two_Factor_Secrets_Lifecycle::has_affected_users() );
		$this->assertSame( 'critical', Two_Factor_Secrets_Lifecycle::site_health_secret_storage()['status'] );
	}

	/**
	 * A plaintext secret of any provider makes Site Health recommend migrating.
	 *
	 * @covers Two_Factor_Secrets_Lifecycle::has_plaintext_users
	 * @covers Two_Factor_Secrets_Lifecycle::count_users_by_storage
	 */
	public function test_plaintext_declared_secret_is_reported() {
		$this->assertFalse( Two_Factor_Secrets_Lifecycle::has_plaintext_users() );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Two_Factor_Dummy_Secret::SECRET_META_KEY, 'DUMMYSECRET' );
		$this->user_with_stored_secret();

		$this->assertTrue( Two_Factor_Secrets_Lifecycle::has_plaintext_users() );
		$this->assertSame( 'recommended', Two_Factor_Secrets_Lifecycle::site_health_secret_storage()['status'] );
		$this->assertSame(
			array(
				'plaintext' => 1,
				'migrated'  => 1,
				'affected'  => 0,
			),
			Two_Factor_Secrets_Lifecycle::count_users_by_storage( 'dummy', Two_Factor_Dummy_Secret::SECRET_META_KEY )
		);
	}
}
