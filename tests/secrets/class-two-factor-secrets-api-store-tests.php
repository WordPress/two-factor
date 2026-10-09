<?php
/**
 * Test the Secrets API store against the real Secrets API.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Secrets_Api_Store_Tests
 *
 * The rest of the suite stores secrets in memory. These tests are the check that
 * the real store behaves the way the in-memory one assumes.
 *
 * @package Two_Factor
 * @group secrets
 */
class Two_Factor_Secrets_Api_Store_Tests extends Two_Factor_Secrets_UnitTestCase {

	/**
	 * The store under test.
	 *
	 * @var Two_Factor_Secrets_Api_Store
	 */
	private $store;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->store = new Two_Factor_Secrets_Api_Store();
	}

	/**
	 * Availability reflects whether the Secrets API is loaded.
	 *
	 * @covers Two_Factor_Secrets_Api_Store::is_available
	 */
	public function test_is_available_reflects_loaded_functions() {
		$this->assertSame( function_exists( 'wp_get_network_secret' ) && class_exists( 'WP_Secret' ), $this->store->is_available() );
	}

	/**
	 * The default provider is writable and has a label.
	 *
	 * @covers Two_Factor_Secrets_Api_Store::is_writable
	 * @covers Two_Factor_Secrets_Api_Store::get_label
	 */
	public function test_default_provider_is_writable_and_labelled() {
		$this->require_secrets_api();

		$this->assertTrue( $this->store->is_writable() );
		$this->assertNotSame( '', $this->store->get_label() );
	}

	/**
	 * A secret round trips in network scope, as a revealed string.
	 *
	 * @covers Two_Factor_Secrets_Api_Store::set
	 * @covers Two_Factor_Secrets_Api_Store::get
	 * @covers Two_Factor_Secrets_Api_Store::delete
	 */
	public function test_round_trip_uses_network_scope() {
		$this->require_secrets_api();
		$name = 'two-factor/totp-424242';

		$this->assertNotWPError( wp_secrets_validate_name( $name ) );
		$this->assertTrue( $this->store->set( $name, 'ABCDEF' ) );
		$this->assertSame( 'ABCDEF', $this->store->get( $name ) );
		$this->assertNotNull( wp_get_network_secret( $name ) );
		$this->assertNull( wp_get_secret( $name ) );

		$this->assertTrue( $this->store->delete( $name ) );
		$this->assertNull( $this->store->get( $name ) );
	}

	/**
	 * A missing secret reads as null and deletes without error, as the in-memory store does.
	 *
	 * @covers Two_Factor_Secrets_Api_Store::get
	 * @covers Two_Factor_Secrets_Api_Store::delete
	 */
	public function test_missing_secret_reads_null_and_deletes_cleanly() {
		$this->require_secrets_api();

		$this->assertNull( $this->store->get( 'two-factor/totp-434343' ) );
		$this->assertTrue( $this->store->delete( 'two-factor/totp-434343' ) );
	}

	/**
	 * The manager works end to end over the real store.
	 *
	 * @covers Two_Factor_Secrets_Manager::set_user_secret
	 * @covers Two_Factor_Secrets_Manager::get_user_secret
	 */
	public function test_manager_round_trip_over_the_real_store() {
		$this->require_secrets_api();
		$manager = new Two_Factor_Secrets_Manager( $this->store );
		$user_id = self::factory()->user->create();

		$this->assertTrue( $manager->set_user_secret( $user_id, 'totp', 'ABCDEF' ) );
		$this->assertSame( 'ABCDEF', $manager->get_user_secret( $user_id, 'totp' ) );
		$this->assertTrue( $manager->delete_user_secret( $user_id, 'totp' ) );
		$this->assertNull( wp_get_network_secret( "two-factor/totp-{$user_id}" ) );
	}
}
