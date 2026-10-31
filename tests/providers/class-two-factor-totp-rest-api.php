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

		// On multisite, `edit_users` is reserved for network super admins, so a
		// plain site administrator cannot manage another user's options.
		if ( is_multisite() ) {
			grant_super_admin( self::$admin_id );
		}

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
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}

	/**
	 * Log a user in with a real session token.
	 *
	 * The wp_set_auth_cookie() helper does not populate $_COOKIE on its own here, so
	 * build the session and logged-in cookie directly to give the request a token.
	 *
	 * @param int $user_id User ID.
	 */
	private function set_up_session_for_user( $user_id ) {
		wp_set_current_user( $user_id );

		$expiration = time() + HOUR_IN_SECONDS;
		$manager    = WP_Session_Tokens::get_instance( $user_id );
		$token      = $manager->create( $expiration );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
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
	 * Verify that setting up TOTP flags the current session as two-factor.
	 *
	 * @covers Two_Factor_Totp::rest_setup_totp
	 * @covers Two_Factor_Core::maybe_mark_current_session_two_factor
	 */
	public function test_user_two_factor_rest_setup_marks_session_two_factor() {
		$this->set_up_session_for_user( self::$admin_id );

		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		$key  = self::$provider->generate_key();
		$code = self::$provider->calc_totp( $key );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id'         => self::$admin_id,
				'key'             => $key,
				'code'            => $code,
				'enable_provider' => true,
			)
		);

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		// The session is flagged, so the user is not asked to revalidate right after setup.
		$this->assertNotFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		$session = Two_Factor_Core::get_current_user_session();
		$this->assertEquals( '', $session['two-factor-provider'] );

		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ) );
	}

	/**
	 * Verify that setting up TOTP for another user does not flag the admin's session.
	 *
	 * @covers Two_Factor_Totp::rest_setup_totp
	 * @covers Two_Factor_Core::maybe_mark_current_session_two_factor
	 */
	public function test_user_two_factor_rest_setup_for_other_user_does_not_mark_session() {
		$this->set_up_session_for_user( self::$admin_id );

		$key  = self::$provider->generate_key();
		$code = self::$provider->calc_totp( $key );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id'         => self::$editor_id,
				'key'             => $key,
				'code'            => $code,
				'enable_provider' => true,
			)
		);

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		// The editor now uses two-factor, but that says nothing about the admin's session.
		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( self::$editor_id ) );
		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );
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

		$data = $response->get_data();
		$this->assertStringContainsString( 'setup-totp', $data['html'] );
		$this->assertStringNotContainsString( 'otpauth://', $data['html'] );
		$this->assertStringNotContainsString( 'two-factor-totp-key', $data['html'] );
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
	 * Build a request to start TOTP setup.
	 *
	 * @param int $user_id User ID.
	 * @return WP_REST_Request
	 */
	private function begin_request( $user_id ) {
		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp/begin' );
		$request->set_body_params( array( 'user_id' => $user_id ) );

		return $request;
	}

	/**
	 * Verify that starting setup returns the secret and QR code link without storing anything.
	 *
	 * @covers Two_Factor_Totp::rest_begin_totp
	 */
	public function test_begin_returns_setup_steps_without_storing_a_secret() {
		wp_set_current_user( self::$admin_id );

		$response = rest_do_request( $this->begin_request( self::$admin_id ) );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertStringContainsString( 'href="otpauth://totp/', $data['html'] );
		$this->assertStringContainsString( __( 'Authentication Code:', 'two-factor' ), $data['html'] );
		$this->assertStringContainsString( 'totp-submit', $data['html'] );
		$this->assertStringContainsString( 'cancel-totp-setup', $data['html'] );

		$this->assertSame( 1, preg_match( '/name="two-factor-totp-key" value="([^"]+)"/', $data['html'], $matches ) );
		$this->assertTrue( self::$provider->is_valid_key( $matches[1] ) );
		$this->assertStringContainsString( 'secret=' . $matches[1], $data['html'] );
		$this->assertStringContainsString( '<code>' . $matches[1] . '</code>', $data['html'] );

		$this->assertSame( '', self::$provider->get_user_totp_key( self::$admin_id ), 'Nothing is stored before Verify' );
		$this->assertFalse( self::$provider->is_available_for_user( wp_get_current_user() ) );
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( self::$admin_id ) );
	}

	/**
	 * Verify that every call returns a different secret.
	 *
	 * @covers Two_Factor_Totp::rest_begin_totp
	 */
	public function test_begin_generates_a_new_secret_each_time() {
		wp_set_current_user( self::$admin_id );

		$first  = rest_do_request( $this->begin_request( self::$admin_id ) )->get_data();
		$second = rest_do_request( $this->begin_request( self::$admin_id ) )->get_data();

		preg_match( '/name="two-factor-totp-key" value="([^"]+)"/', $first['html'], $first_key );
		preg_match( '/name="two-factor-totp-key" value="([^"]+)"/', $second['html'], $second_key );

		$this->assertNotSame( $first_key[1], $second_key[1] );
	}

	/**
	 * Verify that the secret returned by begin can be confirmed through the setup endpoint.
	 *
	 * @covers Two_Factor_Totp::rest_begin_totp
	 * @covers Two_Factor_Totp::rest_setup_totp
	 */
	public function test_begin_secret_can_be_verified() {
		wp_set_current_user( self::$admin_id );

		$data = rest_do_request( $this->begin_request( self::$admin_id ) )->get_data();
		preg_match( '/name="two-factor-totp-key" value="([^"]+)"/', $data['html'], $matches );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
				'key'     => $matches[1],
				'code'    => self::$provider->calc_totp( $matches[1] ),
			)
		);

		$this->assertEquals( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( $matches[1], self::$provider->get_user_totp_key( self::$admin_id ) );
	}

	/**
	 * Verify that an admin starting setup for another user gets that user's secret and label.
	 *
	 * @covers Two_Factor_Totp::rest_begin_totp
	 */
	public function test_begin_for_other_user_uses_that_user() {
		wp_set_current_user( self::$admin_id );

		$editor = get_userdata( self::$editor_id );
		$data   = rest_do_request( $this->begin_request( self::$editor_id ) )->get_data();

		$this->assertStringContainsString( rawurlencode( $editor->user_login ), $data['html'] );
		$this->assertSame( '', self::$provider->get_user_totp_key( self::$editor_id ) );
	}

	/**
	 * Verify that a user cannot start setup for another user.
	 *
	 * @covers Two_Factor_Totp::register_rest_routes
	 */
	public function test_begin_denied_for_other_users() {
		wp_set_current_user( self::$editor_id );

		$response = rest_do_request( $this->begin_request( self::$admin_id ) );

		$this->assertErrorResponse( 'rest_forbidden', $response, 403 );
	}

	/**
	 * Verify that starting setup for a missing user is rejected.
	 *
	 * @covers Two_Factor_Totp::rest_begin_totp
	 */
	public function test_begin_rejects_missing_user() {
		wp_set_current_user( self::$admin_id );

		$response = rest_do_request( $this->begin_request( PHP_INT_MAX ) );

		$this->assertErrorResponse( 'invalid_user', $response, 404 );
	}

	/**
	 * Verify that logged out requests cannot start setup.
	 *
	 * @covers Two_Factor_Totp::register_rest_routes
	 */
	public function test_begin_denied_when_logged_out() {
		wp_set_current_user( 0 );

		$response = rest_do_request( $this->begin_request( self::$admin_id ) );

		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}
}
