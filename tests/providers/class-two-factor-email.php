<?php
/**
 * Test Two Factor Email.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Email
 *
 * @package Two_Factor
 * @group providers
 */
class Tests_Two_Factor_Email extends WP_UnitTestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_Email
	 */
	protected $provider;

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
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase_Base::set_up()
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = Two_Factor_Email::get_instance();
	}

	/**
	 * Clean up after tests.
	 *
	 * @see WP_UnitTestCase::tearDown()
	 */
	public function tear_down() {
		unset( $this->provider );

		parent::tear_down();
	}

	/**
	 * Set up before class.
	 */
	public static function wpSetUpBeforeClass() {
		self::$mockmailer = new MockPHPMailer();

		if ( isset( $GLOBALS['phpmailer'] ) ) {
			self::$phpmailer      = $GLOBALS['phpmailer'];
			$GLOBALS['phpmailer'] = self::$mockmailer;
		}

		$_SERVER['SERVER_NAME'] = 'example.com';
	}

	/**
	 * Tear down after class.
	 */
	public static function wpTearDownAfterClass() {
		unset( $_SERVER['SERVER_NAME'] );

		if ( isset( self::$phpmailer ) ) {
			$GLOBALS['phpmailer'] = self::$phpmailer;
			self::$phpmailer      = null;
		}
	}

	/**
	 * Verify an instance exists.
	 *
	 * @covers Two_Factor_Email::get_instance
	 */
	public function test_get_instance() {
		$this->assertNotNull( $this->provider->get_instance() );
	}

	/**
	 * Verify the label value.
	 *
	 * @covers Two_Factor_Email::get_label
	 */
	public function test_get_label() {
		$this->assertStringContainsString( 'Email', $this->provider->get_label() );
	}

	/**
	 * Verify that validate_token validates a generated token.
	 *
	 * @covers Two_Factor_Email::generate_token
	 * @covers Two_Factor_Email::validate_token
	 */
	public function test_generate_token_and_validate_token() {
		$user_id = 1;

		$token = $this->provider->generate_token( $user_id );

		$this->assertTrue( $this->provider->validate_token( $user_id, $token ) );
	}

	/**
	 * Show that validate_token fails for a different user's token.
	 *
	 * @covers Two_Factor_Email::generate_token
	 * @covers Two_Factor_Email::validate_token
	 */
	public function test_generate_token_and_validate_token_false_different_users() {
		$user_id = 1;

		$token = $this->provider->generate_token( $user_id );

		$this->assertFalse( $this->provider->validate_token( $user_id + 1, $token ) );
	}

	/**
	 * Show that a deleted token can't validate for a user.
	 *
	 * @covers Two_Factor_Email::generate_token
	 * @covers Two_Factor_Email::validate_token
	 * @covers Two_Factor_Email::delete_token
	 */
	public function test_generate_token_and_validate_token_false_deleted() {
		$user_id = 1;

		$token = $this->provider->generate_token( $user_id );
		$this->provider->delete_token( $user_id );

		$this->assertFalse( $this->provider->validate_token( $user_id, $token ) );
	}

	/**
	 * Verify emailed tokens can be validated.
	 *
	 * @covers Two_Factor_Email::generate_and_email_token
	 * @covers Two_Factor_Email::validate_token
	 */
	public function test_generate_and_email_token() {
		$user = new WP_User( self::factory()->user->create() );

		$this->provider->generate_and_email_token( $user );

		$pattern = '/Enter (\d*) to log in./';
		$content = $GLOBALS['phpmailer']->Body;

		$this->assertGreaterThan( 0, preg_match( $pattern, $content, $match ) );
		$this->assertTrue( $this->provider->validate_token( $user->ID, $match[1] ) );
	}

	/**
	 * Verify the contents of the authentication page when no user is provided.
	 *
	 * @covers Two_Factor_Email::authentication_page
	 */
	public function test_authentication_page_no_user() {
		ob_start();
		$this->provider->authentication_page( false );
		$contents = ob_get_clean();

		$this->assertEmpty( $contents );
	}

	/**
	 * Verify that email validation with no user returns false.
	 *
	 * @covers Two_Factor_Email::validate_authentication
	 */
	public function test_validate_authentication_no_user_is_false() {
		$this->assertFalse( $this->provider->validate_authentication( false ) );
	}

	/**
	 * Verify that email validation with no user returns false.
	 *
	 * @covers Two_Factor_Email::validate_authentication
	 */
	public function test_validate_authentication() {
		$user = new WP_User( self::factory()->user->create() );

		$token                             = $this->provider->generate_token( $user->ID );
		$_REQUEST['two-factor-email-code'] = $token;

		$this->assertTrue( $this->provider->validate_authentication( $user ) );

		unset( $_REQUEST['two-factor-email-code'] );
	}

	/**
	 * Can strip away blank spaces and new line characters in code input.
	 *
	 * @covers Two_Factor_Email::validate_authentication
	 */
	public function test_validate_authentication_code_with_spaces() {
		$user = new WP_User( self::factory()->user->create() );

		$token                             = $this->provider->generate_token( $user->ID );
		$_REQUEST['two-factor-email-code'] = sprintf( ' %s ', $token );

		$this->assertTrue( $this->provider->validate_authentication( $user ) );

		unset( $_REQUEST['two-factor-email-code'] );
	}

	/**
	 * Verify that availability returns true.
	 *
	 * @covers Two_Factor_Email::is_available_for_user
	 */
	public function test_is_available_for_user() {
		$this->assertTrue( $this->provider->is_available_for_user( false ) );
	}

	/**
	 * Verify that user tokens are checked correctly.
	 *
	 * @covers Two_Factor_Email::get_user_token
	 */
	public function test_get_user_token() {
		$user_with_token    = self::factory()->user->create_and_get();
		$user_without_token = self::factory()->user->create_and_get();

		$token = wp_hash( $this->provider->generate_token( $user_with_token->ID ) );

		$this->assertEquals( $token, $this->provider->get_user_token( $user_with_token->ID ), 'Failed to retrieve a valid user token.' );
		$this->assertFalse( $this->provider->get_user_token( $user_without_token->ID ), 'Failed to recognize a missing token.' );
	}

	/**
	 * Check if an email code is re-sent.
	 *
	 * @covers Two_Factor_Email::pre_process_authentication
	 */
	public function test_pre_process_authentication() {
		$user           = self::factory()->user->create_and_get();
		$token_original = wp_hash( $this->provider->generate_token( $user->ID ) );

		// Check pre_process_authentication() will prevent any further authentication.
		$_REQUEST[ Two_Factor_Email::INPUT_NAME_RESEND_CODE ] = 1;
		$this->assertTrue( $this->provider->pre_process_authentication( $user ), 'Failed to recognize a code resend request.' );
		unset( $_REQUEST[ Two_Factor_Email::INPUT_NAME_RESEND_CODE ] );

		// Verify that a new token has been generated.
		$token_new = $this->provider->get_user_token( $user->ID );
		$this->assertNotEquals( $token_original, $token_new, 'Failed to generate a new code as requested.' );
	}

	/**
	 * Ensure that a default TTL is set.
	 *
	 * @covers Two_Factor_Email::user_token_ttl
	 */
	public function test_user_token_has_ttl() {
		$this->assertEquals(
			15 * 60,
			$this->provider->user_token_ttl( 123 ),
			'Default TTL is 15 minutes'
		);
	}

	/**
	 * Ensure the token generation time is stored.
	 *
	 * @covers Two_Factor_Email::user_token_lifetime
	 */
	public function test_tokens_have_generation_time() {
		$user_id = self::factory()->user->create();

		$this->assertFalse(
			$this->provider->user_has_token( $user_id ),
			'User does not have a valid token before requesting it'
		);

		$this->assertNull(
			$this->provider->user_token_lifetime( $user_id ),
			'Token lifetime is not present until a token is generated'
		);

		$this->provider->generate_token( $user_id );

		$this->assertTrue(
			$this->provider->user_has_token( $user_id ),
			'User has a token after requesting it'
		);

		$this->assertTrue(
			is_int( $this->provider->user_token_lifetime( $user_id ) ),
			'Lifetime is a valid integer if present'
		);

		$this->assertFalse(
			$this->provider->user_token_has_expired( $user_id ),
			'Fresh token do not expire'
		);
	}

	/**
	 * Ensure the token generation time is stored.
	 *
	 * @covers Two_Factor_Email::user_token_has_expired
	 * @covers Two_Factor_Email::validate_token
	 */
	public function test_tokens_can_expire() {
		$user_id = self::factory()->user->create();
		$token   = $this->provider->generate_token( $user_id );

		$this->assertFalse(
			$this->provider->user_token_has_expired( $user_id ),
			'Fresh token have not expired'
		);

		$this->assertTrue(
			$this->provider->validate_token( $user_id, $token ),
			'Fresh tokens are also valid'
		);

		// Update the generation time to one second before the TTL.
		$expired_token_timestamp = time() - $this->provider->user_token_ttl( $user_id ) - 1;
		update_user_meta( $user_id, Two_Factor_Email::TOKEN_META_KEY_TIMESTAMP, $expired_token_timestamp );

		$this->assertTrue(
			$this->provider->user_token_has_expired( $user_id ),
			'Tokens expire after their TTL'
		);

		$this->assertFalse(
			$this->provider->validate_token( $user_id, $token ),
			'Expired tokens are invalid'
		);
	}

}
