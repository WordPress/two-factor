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
				'key'     => 'abcdef'
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
				'code'    => 'abcdef'
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

}
