<?php
/**
 * Test Two Factor TOTP.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Totp_REST_API
 *
 * @package Two_Factor
 * @group providers
 * @group totp
 */
class Tests_Two_Factor_Totp_REST_API extends WP_Test_REST_TestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_Totp
	 */
	protected static $provider;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Set up test fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory instance.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		self::$editor_id = $factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		self::$provider = Two_Factor_Totp::get_instance();
	}

	/**
	 * Clean up test fixtures.
	 */
	public static function wpTearDownAfterClass() {
			self::delete_user( self::$admin_id );
			self::delete_user( self::$editor_id );
	}

	/**
	 * Verify setting up TOTP with a bad key code.
	 *
	 * @covers Two_Factor_Totp::rest_setup_totp
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_rest_key_bad_auth_code() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
				'key'     => 'abcdef',
			)
		);

		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'invalid_key', $response, 400 );

		$this->assertFalse( self::$provider->is_available_for_user( wp_get_current_user() ) );
	}

	/**
	 * Verify setting up TOTP without an authcode.
	 *
	 * @covers Two_Factor_Totp::rest_setup_totp
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_rest_set_key_no_authcode() {
		wp_set_current_user( self::$admin_id );

		$key = self::$provider->generate_key();

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
				'key'     => $key,
			)
		);

		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'invalid_key_code', $response, 400 );

		$this->assertFalse( self::$provider->is_available_for_user( wp_get_current_user() ) );
	}


	/**
	 * Verify setting up TOTP with a bad authcode.
	 *
	 * @covers Two_Factor_Totp::rest_setup_totp
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_rest_set_key_bad_auth_code() {
		wp_set_current_user( self::$admin_id );

		$key = self::$provider->generate_key();

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
				'key'     => $key,
				'code'    => 'abcdef',
			)
		);

		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'invalid_key_code', $response, 400 );

		$this->assertFalse( self::$provider->is_available_for_user( wp_get_current_user() ) );
	}

	/**
	 * Verify setting up TOTP with an authcode.
	 *
	 * @covers Two_Factor_Totp::rest_setup_totp
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_rest_update_set_key() {
		wp_set_current_user( self::$admin_id );

		$key  = self::$provider->generate_key();
		$code = self::$provider->calc_totp( $key );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
				'key'     => $key,
				'code'    => $code,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertTrue( $data['success'] );

		$this->assertTrue( self::$provider->is_available_for_user( wp_get_current_user() ) );
	}

	/**
	 * Verify secret deletion via REST API.
	 *
	 * @covers Two_Factor_Totp::rest_delete_totp
	 */
	public function test_user_can_delete_secret() {
		wp_set_current_user( self::$admin_id );

		$user = wp_get_current_user();
		$key  = self::$provider->generate_key();

		// Configure secret for the user.
		self::$provider->set_user_totp_key( $user->ID, $key );

		$this->assertEquals(
			$key,
			self::$provider->get_user_totp_key( $user->ID ),
			'Secret was stored and can be fetched'
		);

		$request = new WP_REST_Request( 'DELETE', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals(
			'',
			self::$provider->get_user_totp_key( $user->ID ),
			'Secret has been deleted'
		);
	}

	/**
	 * Verify secret deletion via REST API.
	 *
	 * @covers Two_Factor_Totp::rest_delete_totp
	 */
	public function test_admin_can_delete_secret_for_others() {
		wp_set_current_user( self::$admin_id );

		$key = self::$provider->generate_key();

		// Configure secret for the user.
		self::$provider->set_user_totp_key( self::$editor_id, $key );

		$this->assertEquals(
			$key,
			self::$provider->get_user_totp_key( self::$editor_id ),
			'Secret was stored and can be fetched'
		);

		$request = new WP_REST_Request( 'DELETE', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id' => self::$editor_id,
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals(
			'',
			self::$provider->get_user_totp_key( self::$editor_id ),
			'Secret has been deleted'
		);
	}

	/**
	 * Verify secret deletion via REST API denied for other users.
	 *
	 * @covers Two_Factor_Totp::rest_delete_totp
	 */
	public function test_user_cannot_delete_secret_for_others() {
		wp_set_current_user( self::$editor_id );

		$user = get_user_by( 'id', self::$admin_id );
		$key  = self::$provider->generate_key();

		// Configure secret for the user.
		self::$provider->set_user_totp_key( $user->ID, $key );

		$this->assertEquals(
			$key,
			self::$provider->get_user_totp_key( $user->ID ),
			'Secret was stored and can be fetched'
		);

		$request = new WP_REST_Request( 'DELETE', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
			)
		);
		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'rest_forbidden', $response, 403 );

		$this->assertEquals(
			$key,
			self::$provider->get_user_totp_key( $user->ID ),
			'Secret has not been deleted'
		);
	}

	/**
	 * A server-owned enrollment can be confirmed without resubmitting its secret.
	 *
	 * @covers Two_Factor_Totp::rest_begin_enrollment
	 * @covers Two_Factor_Totp::rest_setup_totp
	 */
	public function test_begin_and_confirm_server_owned_enrollment() {
		wp_set_current_user( self::$admin_id );
		Two_Factor_Totp::set_time( time() );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp/enrollment' );
		$request->set_body_params( array( 'user_id' => self::$admin_id ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( self::$provider->is_valid_key( $data['secret'] ) );
		$this->assertStringStartsWith( 'otpauth://totp/', $data['otpauth_uri'] );
		$this->assertGreaterThan( time(), $data['expires_at'] );
		$this->assertSame( '', self::$provider->get_user_totp_key( self::$admin_id ) );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id'         => self::$admin_id,
				'code'            => self::$provider->calc_totp( $data['secret'] ),
				'enable_provider' => true,
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $data['secret'], self::$provider->get_user_totp_key( self::$admin_id ) );
		$this->assertEmpty( get_user_meta( self::$admin_id, Two_Factor_Totp::PENDING_ENROLLMENT_META_KEY, true ) );

		self::$provider->delete_user_totp_key( self::$admin_id );
		$response = rest_do_request( $request );
		$this->assertErrorResponse( 'totp_enrollment_not_found', $response, 400 );

		Two_Factor_Totp::set_time( null );
	}

	/**
	 * A newer enrollment replaces the previous pending secret.
	 *
	 * @covers Two_Factor_Totp::rest_begin_enrollment
	 * @covers Two_Factor_Totp::rest_setup_totp
	 */
	public function test_begin_enrollment_replaces_pending_secret() {
		wp_set_current_user( self::$admin_id );
		Two_Factor_Totp::set_time( time() );

		$begin = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp/enrollment' );
		$begin->set_body_params( array( 'user_id' => self::$admin_id ) );
		$first  = rest_do_request( $begin )->get_data();
		$second = rest_do_request( $begin )->get_data();

		$this->assertNotSame( $first['secret'], $second['secret'] );

		$confirm = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$confirm->set_body_params(
			array(
				'user_id' => self::$admin_id,
				'code'    => self::$provider->calc_totp( $first['secret'] ),
			)
		);
		$this->assertErrorResponse( 'invalid_key_code', rest_do_request( $confirm ), 400 );

		$confirm->set_param( 'code', self::$provider->calc_totp( $second['secret'] ) );
		$this->assertSame( 200, rest_do_request( $confirm )->get_status() );

		Two_Factor_Totp::set_time( null );
	}

	/**
	 * Expired pending enrollments are deleted and cannot be confirmed.
	 *
	 * @covers Two_Factor_Totp::rest_begin_enrollment
	 * @covers Two_Factor_Totp::rest_setup_totp
	 */
	public function test_pending_enrollment_expires() {
		wp_set_current_user( self::$admin_id );
		$now = time();
		Two_Factor_Totp::set_time( $now );

		$begin = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp/enrollment' );
		$begin->set_body_params( array( 'user_id' => self::$admin_id ) );
		$data = rest_do_request( $begin )->get_data();

		Two_Factor_Totp::set_time( $now + Two_Factor_Totp::PENDING_ENROLLMENT_TTL );

		$confirm = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$confirm->set_body_params(
			array(
				'user_id' => self::$admin_id,
				'code'    => self::$provider->calc_totp( $data['secret'] ),
			)
		);
		$response = rest_do_request( $confirm );

		$this->assertErrorResponse( 'totp_enrollment_expired', $response, 400 );
		$this->assertEmpty( get_user_meta( self::$admin_id, Two_Factor_Totp::PENDING_ENROLLMENT_META_KEY, true ) );

		Two_Factor_Totp::set_time( null );
	}

	/**
	 * Users cannot begin enrollment for accounts they cannot edit.
	 *
	 * @covers Two_Factor_Totp::rest_begin_enrollment
	 */
	public function test_user_cannot_begin_enrollment_for_another_user() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp/enrollment' );
		$request->set_body_params( array( 'user_id' => self::$admin_id ) );
		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'rest_forbidden', $response, 403 );
	}

	/**
	 * All TOTP mutations reject nonexistent users before provider callbacks.
	 *
	 * @ticket 937
	 * @covers Two_Factor_Core::rest_api_can_edit_user
	 */
	public function test_totp_routes_reject_nonexistent_users_consistently() {
		wp_set_current_user( self::$admin_id );
		$before = get_user_meta( 0 );
		$routes = array(
			array(
				'POST',
				'/' . Two_Factor_Core::REST_NAMESPACE . '/totp/enrollment',
				array( 'user_id' => 0 ),
			),
			array(
				'POST',
				'/' . Two_Factor_Core::REST_NAMESPACE . '/totp',
				array(
					'user_id' => 0,
					'key'     => self::$provider->generate_key(),
					'code'    => '123456',
				),
			),
			array(
				'DELETE',
				'/' . Two_Factor_Core::REST_NAMESPACE . '/totp',
				array( 'user_id' => 0 ),
			),
		);

		foreach ( $routes as $route ) {
			$request = new WP_REST_Request( $route[0], $route[1] );
			$request->set_body_params( $route[2] );
			$response = rest_do_request( $request );

			$this->assertErrorResponse( 'rest_user_invalid_id', $response, 404 );
			$this->assertSame( $before, get_user_meta( 0 ) );
		}
	}
}
