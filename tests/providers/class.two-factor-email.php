<?php
/**
 * Test Two Factor Email.
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

	static protected $phpmailer = null, $mockmailer;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase::setup()
	 */
	public function setUp() {
		parent::setUp();

		$this->provider = Two_Factor_Email::get_instance();
	}

	/**
	 * Clean up after tests.
	 *
	 * @see WP_UnitTestCase::tearDown()
	 */
	public function tearDown() {
		unset( $this->provider );

		parent::tearDown();
	}

	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();

		Tests_Two_Factor_Email::$mockmailer = new MockPHPMailer();

		if ( isset( $GLOBALS['phpmailer'] ) ) {
			Tests_Two_Factor_Email::$phpmailer = $GLOBALS['phpmailer'];
			$GLOBALS['phpmailer'] = Tests_Two_Factor_Email::$mockmailer;
		}

		$_SERVER['SERVER_NAME'] = 'example.com';
	}

	public static function tearDownBeforeClass() {
		unset( $_SERVER['SERVER_NAME'] );

		if ( isset( Tests_Two_Factor_Email::$phpmailer ) ) {
			$GLOBALS['phpmailer'] = Tests_Two_Factor_Email::$phpmailer;
			Tests_Two_Factor_Email::$phpmailer = null;
		}

		unset( Tests_Two_Factor_Email::$mockmailer );

		parent::tearDownBeforeClass();
	}

	/**
	 * Verify an instance exists.
	 * @covers Two_Factor_Email::get_instance
	 */
	function test_get_instance() {
		$this->assertNotNull( $this->provider->get_instance() );
	}

	/**
	 * Verify the label value.
	 * @covers Two_Factor_Email::get_label
	 */
	function test_get_label() {
		$this->assertContains( 'Email', $this->provider->get_label() );
	}

	/**
	 * Verify that validate_token validates a generated token.
	 * @covers Two_Factor_Email::generate_token
	 * @covers Two_Factor_Email::validate_token
	 */
	function test_generate_token_and_validate_token() {
		$user_id = 1;

		$token = $this->provider->generate_token( $user_id );

		$this->assertTrue( $this->provider->validate_token( $user_id, $token ) );
	}

	/**
	 * Show that validate_token fails for a different user's token.
	 * @covers Two_Factor_Email::generate_token
	 * @covers Two_Factor_Email::validate_token
	 */
	function test_generate_token_and_validate_token_false_different_users() {
		$user_id = 1;

		$token = $this->provider->generate_token( $user_id );

		$this->assertFalse( $this->provider->validate_token( $user_id + 1, $token ) );
	}

	/**
	 * Show that a deleted token can't validate for a user.
	 * @covers Two_Factor_Email::generate_token
	 * @covers Two_Factor_Email::validate_token
	 * @covers Two_Factor_Email::delete_token
	 */
	function test_generate_token_and_validate_token_false_deleted() {
		$user_id = 1;

		$token = $this->provider->generate_token( $user_id );
		$this->provider->delete_token( $user_id );

		$this->assertFalse( $this->provider->validate_token( $user_id, $token ) );
	}

	/**
	 * Verify emailed tokens can be validated.
	 * @covers Two_Factor_Email::generate_and_email_token
	 * @covers Two_Factor_Email::validate_token
	 */
	function test_generate_and_email_token() {
		$user = new WP_User( $this->factory->user->create() );

		$this->provider->generate_and_email_token( $user );

		$pattern = '/Enter (\d*) to log in./';
		$content = $GLOBALS['phpmailer']->Body;

		$this->assertGreaterThan( 0, preg_match( $pattern, $content, $match ) );
		$this->assertTrue( $this->provider->validate_token( $user->ID, $match[ 1 ] ) );
	}

	/**
	 * Verify the contents of the authentication page when no user is provided.
	 * @covers Two_Factor_Email::authentication_page
	 */
	function test_authentication_page_no_user() {
		ob_start();
		$this->provider->authentication_page( false );
		$contents = ob_get_clean();

		$this->assertEmpty( $contents );
	}

	/**
	 * Verify that email validation with no user returns false.
	 * @covers Two_Factor_Email::validate_authentication
	 */
	function test_validate_authentication_no_user_is_false() {
		$this->assertFalse( $this->provider->validate_authentication( false ) );
	}

	/**
	 * Verify that email validation with no user returns false.
	 * @covers Two_Factor_Email::validate_authentication
	 */
	function test_validate_authentication() {
		$user = new WP_User( $this->factory->user->create() );

		$token = $this->provider->generate_token( $user->ID );
		$_REQUEST['two-factor-email-code'] = $token;

		$this->assertTrue( $this->provider->validate_authentication( $user ) );

		unset( $_REQUEST['two-factor-email-code'] );
	}

	/**
	 * Verify that availability returns true.
	 * @covers Two_Factor_Email::is_available_for_user
	 */
	function test_is_available_for_user() {
		$this->assertTrue( $this->provider->is_available_for_user( false ) );
	}

	/**
	 * Verify that user tokens are checked correctly.
	 * @covers Two_Factor_Email::get_user_token
	 */
	function test_get_user_token() {
		$user_with_token = $this->factory->user->create_and_get();
		$user_without_token = $this->factory->user->create_and_get();

		$token = wp_hash( $this->provider->generate_token( $user_with_token->ID ) );

		$this->assertEquals( $token, $this->provider->get_user_token( $user_with_token->ID ), 'Failed to retrieve a valid user token.' );
		$this->assertFalse( $this->provider->get_user_token( $user_without_token->ID ), 'Failed to recognize a missing token.' );
	}

	/**
	 * Check if an email code is re-sent.
	 * @covers Two_Factor_Email::pre_process_authentication
	 */
	function test_pre_process_authentication() {
		$user = $this->factory->user->create_and_get();
		$token_original = wp_hash( $this->provider->generate_token( $user->ID ) );

		// Check pre_process_authentication() will prevent any further authentication.
		$_REQUEST[ Two_Factor_Email::INPUT_NAME_RESEND_CODE ] = 1;
		$this->assertTrue( $this->provider->pre_process_authentication( $user ), 'Failed to recognize a code resend request.' );
		unset( $_REQUEST[ Two_Factor_Email::INPUT_NAME_RESEND_CODE ] );

		// Verify that a new token has been generated.
		$token_new = $this->provider->get_user_token( $user->ID );
		$this->assertNotEquals( $token_original, $token_new, 'Failed to generate a new code as requested.' );
	}

}
