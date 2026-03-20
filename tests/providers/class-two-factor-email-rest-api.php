<?php
/**
 * Test Two Factor Email REST API.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Email_REST_API
 *
 * @package Two_Factor
 * @group providers
 * @group email
 */
class Tests_Two_Factor_Email_REST_API extends WP_Test_REST_TestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_Email
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
	 * Instance of the PHPMailer class.
	 *
	 * @var PHPMailer
	 */
	protected static $phpmailer = null;

	/**
	 * Instance of the MockPHPMailer class.
	 *
	 * @var MockPHPMailer
	 */
	protected static $mockmailer;

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

		self::$provider = Two_Factor_Email::get_instance();

		self::$mockmailer = new MockPHPMailer();

		if ( isset( $GLOBALS['phpmailer'] ) ) {
			self::$phpmailer      = $GLOBALS['phpmailer'];
			$GLOBALS['phpmailer'] = self::$mockmailer;
		}

		$_SERVER['SERVER_NAME'] = 'example.com';
	}

	/**
	 * Clean up test fixtures.
	 */
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$admin_id );
		self::delete_user( self::$editor_id );

		unset( $_SERVER['SERVER_NAME'] );

		if ( isset( self::$phpmailer ) ) {
			$GLOBALS['phpmailer'] = self::$phpmailer;
			self::$phpmailer      = null;
		}
	}

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Clear mock emails before each test.
		self::$mockmailer->mock_sent = array();
	}

	/**
	 * Verify setting up email without a code triggers email sending.
	 *
	 * @covers Two_Factor_Email::rest_setup_email
	 */
	public function test_user_two_factor_rest_setup_email_sends_code() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/email' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
			)
		);

		$emails_before = count( self::$mockmailer->mock_sent );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		$this->assertCount( $emails_before + 1, self::$mockmailer->mock_sent, 'A new email should be sent' );

		// User should have a token saved.
		$this->assertTrue( self::$provider->user_has_token( self::$admin_id ) );
	}

	/**
	 * Verify setting up email with an invalid code.
	 *
	 * @covers Two_Factor_Email::rest_setup_email
	 */
	public function test_user_two_factor_rest_setup_email_bad_code() {
		wp_set_current_user( self::$admin_id );

		self::$provider->generate_token( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/email' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
				'code'    => 'invalid123',
			)
		);

		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'invalid_code', $response, 400 );
		$this->assertFalse( self::$provider->is_available_for_user( wp_get_current_user() ) );
	}

	/**
	 * Verify setting up email with a valid code enables the provider.
	 *
	 * @covers Two_Factor_Email::rest_setup_email
	 */
	public function test_user_two_factor_rest_setup_email_valid_code() {
		wp_set_current_user( self::$admin_id );

		$token = self::$provider->generate_token( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/email' );
		$request->set_body_params(
			array(
				'user_id'         => self::$admin_id,
				'code'            => $token,
				'enable_provider' => true,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'html', $data );

		// Should be verified and enabled.
		$this->assertTrue( (bool) get_user_meta( self::$admin_id, Two_Factor_Email::VERIFIED_META_KEY, true ) );
		$this->assertTrue( Two_Factor_Core::is_provider_enabled_for_user( self::$admin_id, 'Two_Factor_Email' ) );
	}

	/**
	 * Verify deleting email verification via REST API.
	 *
	 * @covers Two_Factor_Email::rest_delete_email
	 */
	public function test_user_can_delete_email_verification() {
		wp_set_current_user( self::$admin_id );
		Two_Factor_Core::enable_provider_for_user( self::$admin_id, 'Two_Factor_Email' );
		update_user_meta( self::$admin_id, Two_Factor_Email::VERIFIED_META_KEY, true );

		$request = new WP_REST_Request( 'DELETE', '/' . Two_Factor_Core::REST_NAMESPACE . '/email' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		// Should no longer be verified.
		$this->assertFalse( get_user_meta( self::$admin_id, Two_Factor_Email::VERIFIED_META_KEY, true ) );
	}

	/**
	 * Verify admin can delete email verification for others.
	 *
	 * @covers Two_Factor_Email::rest_delete_email
	 */
	public function test_admin_can_delete_email_for_others() {
		wp_set_current_user( self::$admin_id );
		update_user_meta( self::$editor_id, Two_Factor_Email::VERIFIED_META_KEY, true );

		$request = new WP_REST_Request( 'DELETE', '/' . Two_Factor_Core::REST_NAMESPACE . '/email' );
		$request->set_body_params(
			array(
				'user_id' => self::$editor_id,
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertFalse( get_user_meta( self::$editor_id, Two_Factor_Email::VERIFIED_META_KEY, true ) );
	}

	/**
	 * Verify deleting email via REST API denied for other users.
	 *
	 * @covers Two_Factor_Email::rest_delete_email
	 */
	public function test_user_cannot_delete_email_for_others() {
		wp_set_current_user( self::$editor_id );
		update_user_meta( self::$admin_id, Two_Factor_Email::VERIFIED_META_KEY, true );

		$request = new WP_REST_Request( 'DELETE', '/' . Two_Factor_Core::REST_NAMESPACE . '/email' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
			)
		);
		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'rest_forbidden', $response, 403 );

		// Verified state shouldn't be altered.
		$this->assertTrue( (bool) get_user_meta( self::$admin_id, Two_Factor_Email::VERIFIED_META_KEY, true ) );
	}

	/**
	 * Verify setup email via REST API denied for other users.
	 *
	 * @covers Two_Factor_Email::rest_setup_email
	 */
	public function test_user_cannot_setup_email_for_others() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/email' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
			)
		);
		$response = rest_do_request( $request );

		$this->assertErrorResponse( 'rest_forbidden', $response, 403 );
	}
}
