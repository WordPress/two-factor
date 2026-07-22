<?php
/**
 * Tests for the core provider settings REST API.
 *
 * @package Two_Factor
 */

/**
 * Core provider settings REST API tests.
 *
 * @group core
 * @group rest-api
 */
class Tests_Two_Factor_REST_API extends WP_Test_REST_TestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * Set up shared fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory instance.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Clean up shared fixtures.
	 */
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$admin_id );
		self::delete_user( self::$subscriber_id );
	}

	/**
	 * Build a request for the provider settings route.
	 *
	 * @param string $method  HTTP method.
	 * @param int    $user_id User ID.
	 * @param array  $params  Optional body parameters.
	 * @return WP_REST_Request Request object.
	 */
	private function get_request( $method, $user_id, $params = array() ) {
		$request = new WP_REST_Request( $method, '/' . Two_Factor_Core::REST_NAMESPACE . '/users/' . $user_id . '/providers' );
		$request->set_body_params( $params );

		return $request;
	}

	/**
	 * Find provider data in a settings response.
	 *
	 * @param array  $data         Response data.
	 * @param string $provider_key Provider key.
	 * @return array Provider data.
	 */
	private function get_provider_data( $data, $provider_key ) {
		foreach ( $data['providers'] as $provider ) {
			if ( $provider_key === $provider['key'] ) {
				return $provider;
			}
		}

		$this->fail( 'Provider not present in response: ' . $provider_key );
	}

	/**
	 * Provider settings require an authenticated user who can edit the target.
	 *
	 * @covers Two_Factor_Core::rest_get_user_provider_settings
	 */
	public function test_get_provider_settings_permissions() {
		$response = rest_do_request( $this->get_request( 'GET', self::$subscriber_id ) );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );

		wp_set_current_user( self::$subscriber_id );
		$response = rest_do_request( $this->get_request( 'GET', self::$admin_id ) );
		$this->assertErrorResponse( 'rest_forbidden', $response, 403 );

		wp_set_current_user( self::$admin_id );
		$response = rest_do_request( $this->get_request( 'GET', self::$subscriber_id ) );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Status includes provider state without disclosing a configured TOTP secret.
	 *
	 * @covers Two_Factor_Core::rest_get_user_provider_settings
	 */
	public function test_get_provider_settings_redacts_totp_secret_and_counts_recovery_codes() {
		wp_set_current_user( self::$subscriber_id );
		$totp   = Two_Factor_Totp::get_instance();
		$backup = Two_Factor_Backup_Codes::get_instance();
		$secret = $totp->generate_key();

		$totp->set_user_totp_key( self::$subscriber_id, $secret );
		$backup->generate_codes( get_user_by( 'id', self::$subscriber_id ), array( 'number' => 3 ) );
		Two_Factor_Core::enable_provider_for_user( self::$subscriber_id, 'Two_Factor_Totp' );

		$response = rest_do_request( $this->get_request( 'GET', self::$subscriber_id ) );
		$data     = $response->get_data();
		$totp     = $this->get_provider_data( $data, 'Two_Factor_Totp' );
		$backup   = $this->get_provider_data( $data, 'Two_Factor_Backup_Codes' );

		$this->assertTrue( $totp['supported'] );
		$this->assertTrue( $totp['configured'] );
		$this->assertTrue( $totp['enabled'] );
		$this->assertTrue( $totp['primary'] );
		$this->assertSame( 3, $backup['remaining'] );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $data ) );
	}

	/**
	 * Email and generated recovery codes can be enabled and selected generically.
	 *
	 * @covers Two_Factor_Core::rest_update_user_provider_settings
	 * @covers Two_Factor_Core::update_user_provider_settings
	 */
	public function test_update_email_and_recovery_code_settings() {
		wp_set_current_user( self::$subscriber_id );
		Two_Factor_Backup_Codes::get_instance()->generate_codes( get_user_by( 'id', self::$subscriber_id ) );

		$response = rest_do_request(
			$this->get_request(
				'POST',
				self::$subscriber_id,
				array(
					'enabled_providers' => array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ),
					'primary_provider'  => 'Two_Factor_Email',
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->get_provider_data( $data, 'Two_Factor_Email' )['primary'] );
		$this->assertTrue( $this->get_provider_data( $data, 'Two_Factor_Backup_Codes' )['enabled'] );
		$this->assertSame(
			array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ),
			Two_Factor_Core::get_enabled_providers_for_user( self::$subscriber_id )
		);
	}

	/**
	 * Admin edits apply profile-compatible session and primary-provider semantics.
	 *
	 * @covers Two_Factor_Core::rest_update_user_provider_settings
	 * @covers Two_Factor_Core::update_user_provider_settings
	 */
	public function test_admin_update_invalidates_target_sessions_and_can_disable_last_provider() {
		wp_set_current_user( self::$admin_id );
		$user            = get_user_by( 'id', self::$subscriber_id );
		$session_manager = WP_Session_Tokens::get_instance( self::$subscriber_id );

		Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );
		$session_manager->create( time() + HOUR_IN_SECONDS );
		$session_manager->create( time() + DAY_IN_SECONDS );

		$response = rest_do_request(
			$this->get_request(
				'POST',
				self::$subscriber_id,
				array(
					'enabled_providers' => array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ),
					'primary_provider'  => 'Two_Factor_Email',
				)
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $session_manager->get_all() );

		$session_manager->create( time() + HOUR_IN_SECONDS );
		$response = rest_do_request(
			$this->get_request(
				'POST',
				self::$subscriber_id,
				array(
					'enabled_providers' => array( 'Two_Factor_Email' ),
					'primary_provider'  => 'Two_Factor_Email',
				)
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $session_manager->get_all() );

		$response = rest_do_request(
			$this->get_request(
				'POST',
				self::$subscriber_id,
				array(
					'enabled_providers' => array(),
					'primary_provider'  => '',
				)
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user( self::$subscriber_id ) );
		$this->assertNull( Two_Factor_Core::get_primary_provider_for_user( self::$subscriber_id ) );
	}

	/**
	 * Unconfigured providers and disabled primary providers are rejected atomically.
	 *
	 * @covers Two_Factor_Core::update_user_provider_settings
	 */
	public function test_update_rejects_invalid_provider_invariants() {
		wp_set_current_user( self::$subscriber_id );

		$response = rest_do_request(
			$this->get_request(
				'POST',
				self::$subscriber_id,
				array( 'enabled_providers' => array() )
			)
		);
		$this->assertErrorResponse( 'rest_missing_callback_param', $response, 400 );

		$response = rest_do_request(
			$this->get_request(
				'POST',
				self::$subscriber_id,
				array(
					'enabled_providers' => array( 'Two_Factor_Totp' ),
					'primary_provider'  => '',
				)
			)
		);
		$this->assertErrorResponse( 'two_factor_provider_not_configured', $response, 400 );
		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user( self::$subscriber_id ) );

		$response = rest_do_request(
			$this->get_request(
				'POST',
				self::$subscriber_id,
				array(
					'enabled_providers' => array( 'Two_Factor_Email' ),
					'primary_provider'  => 'Two_Factor_Totp',
				)
			)
		);
		$this->assertErrorResponse( 'two_factor_primary_provider_not_enabled', $response, 400 );
		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user( self::$subscriber_id ) );
	}

	/**
	 * Sensitive mutations return a structured target when recent 2FA is required.
	 *
	 * @covers Two_Factor_Core::rest_api_can_edit_user_and_update_two_factor_options
	 */
	public function test_update_requires_recent_revalidation() {
		wp_set_current_user( self::$subscriber_id );
		wp_set_auth_cookie( self::$subscriber_id );
		Two_Factor_Core::enable_provider_for_user( self::$subscriber_id, 'Two_Factor_Email' );

		$response = rest_do_request(
			$this->get_request(
				'POST',
				self::$subscriber_id,
				array(
					'enabled_providers' => array(),
					'primary_provider'  => '',
				)
			)
		);
		$data     = $response->get_data();

		$this->assertErrorResponse( 'revalidation_required', $response, 403 );
		$this->assertTrue( $data['data']['revalidation']['required'] );
		$this->assertSame( 'revalidate_2fa', $data['data']['revalidation']['action'] );
		$this->assertStringContainsString( 'action=revalidate_2fa', $data['data']['revalidation']['url'] );
	}
}
