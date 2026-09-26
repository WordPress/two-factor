<?php
/**
 * Test Two Factor Backup Codes.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Backup_Codes_REST_API.
 *
 * @package Two_Factor
 * @group providers
 * @group backup-codes
 */
class Tests_Two_Factor_Backup_Codes_REST_API extends WP_Test_REST_TestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_Backup_Codes
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

		self::$provider = Two_Factor_Backup_Codes::get_instance();
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
	 * Verify that the downloaded file contains the default number of codes.
	 *
	 * @covers Two_Factor_Backup_Codes::rest_generate_codes
	 */
	public function test_generate_code_and_validate_in_download_file() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotEmpty( $data['download_link'] );
		$this->assertNotEmpty( $data['codes'] );
		$this->assertCount( 10, $data['codes'] );
		$this->assertTrue( self::$provider->validate_code( wp_get_current_user(), $data['codes'][0] ) );
		$this->assertStringContainsString( $data['codes'][0], $data['download_link'] );
	}

	/**
	 * Verify that a user without edit_user capabilities cannot generate codes for another.
	 *
	 * @covers Two_Factor_Backup_Codes::rest_generate_codes
	 */
	public function test_cannot_generate_code_for_different_user() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes' );
		$request->set_body_params(
			array(
				'user_id' => self::$admin_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertErrorResponse( 'rest_forbidden', $response, 403 );

		$this->assertArrayNotHasKey( 'download_link', $data );
		$this->assertArrayNotHasKey( 'codes', $data );
	}

	/**
	 * Verify that an admin can create Backup codes for another user.
	 *
	 * @covers Two_Factor_Backup_Codes::rest_generate_codes
	 */
	public function test_generate_codes_for_other_users() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes' );
		$request->set_body_params(
			array(
				'user_id' => self::$editor_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotEmpty( $data['codes'] );

		$this->assertFalse( self::$provider->validate_code( wp_get_current_user(), $data['codes'][0] ) );
		$this->assertTrue( self::$provider->validate_code( get_user_by( 'id', self::$editor_id ), $data['codes'][0] ) );
	}

	/**
	 * Verify that generating recovery codes flags the current session as two-factor.
	 *
	 * @covers Two_Factor_Backup_Codes::rest_generate_codes
	 * @covers Two_Factor_Core::maybe_mark_current_session_two_factor
	 */
	public function test_generate_codes_marks_session_two_factor() {
		$this->set_up_session_for_user( self::$admin_id );

		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes' );
		$request->set_body_params(
			array(
				'user_id'         => self::$admin_id,
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
}
