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
 * @group email
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
	 * Verify that verification setup emails have correct content.
	 *
	 * @covers Two_Factor_Email::generate_and_email_token
	 */
	public function test_generate_and_email_token_verification_context() {
		$user = new WP_User( self::factory()->user->create() );

		$this->provider->generate_and_email_token( $user, 'verification_setup' );

		$subject = $GLOBALS['phpmailer']->Subject;
		$content = $GLOBALS['phpmailer']->Body;

		$this->assertStringContainsString( 'Verify your email for Two-Factor Authentication', $subject );
		$this->assertStringContainsString( 'verify your email address', $content );
		$this->assertStringNotContainsString( 'successfully authenticated', $content );
	}

	/**
	 * Verify that login emails have correct content arguments.
	 *
	 * @covers Two_Factor_Email::generate_and_email_token
	 */
	public function test_generate_and_email_token_login_context_correct_args() {
		$user = new WP_User( self::factory()->user->create() );
		// Mock REMOTE_ADDR for IP check
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		$this->provider->generate_and_email_token( $user, 'login' );

		$content = $GLOBALS['phpmailer']->Body;

		$this->assertStringContainsString( 'Enter', $content );
		$this->assertStringContainsString( 'log in', $content );
		// Check that IP is effectively in the message (and not the token key or something else)
		$this->assertStringContainsString( '127.0.0.1', $content );
		// Check that username is in the message
		$this->assertStringContainsString( $user->user_login, $content );
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
	 * Verify that email validation fails if user or token are missing.
	 *
	 * @covers Two_Factor_Email::validate_authentication
	 */
	public function test_validate_authentication_fails_with_missing_input() {
		$logged_out_user = new WP_User();
		$valid_user      = new WP_User( self::factory()->user->create() );

		// User but no code.
		$this->assertFalse( $this->provider->validate_authentication( $valid_user ) );

		// Code but no user.
		$_REQUEST['two-factor-email-code'] = $this->provider->generate_token( $valid_user->ID );
		$this->assertFalse( $this->provider->validate_authentication( $logged_out_user ) );
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
	 * Verify that availability returns false for unverified users.
	 *
	 * @covers Two_Factor_Email::is_available_for_user
	 */
	public function test_is_available_for_user() {
		$user = new WP_User( self::factory()->user->create() );
		$this->assertFalse( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * Verify that availability returns true for verified users.
	 *
	 * @covers Two_Factor_Email::is_available_for_user
	 */
	public function test_is_available_for_user_verified() {
		$user = new WP_User( self::factory()->user->create() );
		update_user_meta( $user->ID, Two_Factor_Email::VERIFIED_META_KEY, true );
		$this->assertTrue( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * Verify that availability returns true for users who already have it enabled (backwards compatibility).
	 *
	 * @covers Two_Factor_Email::is_available_for_user
	 */
	public function test_is_available_for_user_backwards_compat() {
		$user = new WP_User( self::factory()->user->create() );
		update_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		$this->assertTrue( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * Verify that the verified meta key is cleaned up on uninstall.
	 *
	 * @covers Two_Factor_Email::uninstall_user_meta_keys
	 */
	public function test_verified_meta_cleanup() {
		$keys = Two_Factor_Email::uninstall_user_meta_keys();
		$this->assertContains( Two_Factor_Email::VERIFIED_META_KEY, $keys );
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
	 * Verify that pre_user_options_update blocks enabling if not verified.
	 *
	 * @covers Two_Factor_Email::pre_user_options_update
	 */
	public function test_pre_user_options_update_blocks_unverified() {
		$user_id = self::factory()->user->create();
		
		// Simulate POST request trying to enable Email provider.
		$_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] = array( 'Two_Factor_Email', 'Two_Factor_Dummy' );
		
		$this->provider->pre_user_options_update( $user_id );
		
		$this->assertNotContains( 'Two_Factor_Email', $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] );
		$this->assertContains( 'Two_Factor_Dummy', $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] );
		
		unset( $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] );
	}

	/**
	 * Verify that pre_user_options_update allows keeping legacy enabled provider.
	 *
	 * @covers Two_Factor_Email::pre_user_options_update
	 */
	public function test_pre_user_options_update_allows_legacy() {
		$user_id = self::factory()->user->create();
		
		// Set up legacy state: enabled but not verified.
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		
		// Simulate POST request keeping it enabled.
		$_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] = array( 'Two_Factor_Email' );
		
		$this->provider->pre_user_options_update( $user_id );
		
		$this->assertContains( 'Two_Factor_Email', $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] );
		
		unset( $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] );
	}

	/**
	 * Verify that verified users can enable the provider.
	 *
	 * @covers Two_Factor_Email::pre_user_options_update
	 */
	public function test_pre_user_options_update_allows_verified() {
		$user_id = self::factory()->user->create();
		
		// Set up verified state.
		update_user_meta( $user_id, Two_Factor_Email::VERIFIED_META_KEY, true );
		
		// Simulate POST request enabling it.
		$_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] = array( 'Two_Factor_Email' );
		
		$this->provider->pre_user_options_update( $user_id );
		
		$this->assertContains( 'Two_Factor_Email', $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] );
		
		unset( $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] );
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

	/**
	 * Test custom token length filter.
	 */
	public function test_custom_token_length() {
		$user_id = self::factory()->user->create();

		$default_token = $this->provider->generate_token( $user_id );

		add_filter(
			'two_factor_email_token_length',
			function () {
				return 15;
			}
		);

		$custom_token = $this->provider->generate_token( $user_id );

		$this->assertNotEquals( strlen( $default_token ), strlen( $custom_token ), 'Token length is different due to filter' );
		$this->assertEquals( 15, strlen( $custom_token ), 'Token length matches the filter value' );

		remove_all_filters( 'two_factor_email_token_length' );
	}

	/**
	 * Test the email token TTL.
	 *
	 * @expectedDeprecated two_factor_token_ttl
	 */
	public function test_email_token_ttl() {
		$this->assertEquals(
			15 * MINUTE_IN_SECONDS,
			$this->provider->user_token_ttl( 123 ),
			'The email token matches the default TTL'
		);

		add_filter(
			'two_factor_email_token_ttl',
			function () {
				return 42;
			}
		);

		$this->assertEquals(
			42,
			$this->provider->user_token_ttl( 123 ),
			'The email token ttl can be filtered'
		);

		remove_all_filters( 'two_factor_email_token_ttl' );

		add_filter(
			'two_factor_token_ttl',
			function () {
				return 66;
			}
		);

		$this->assertEquals(
			66,
			$this->provider->user_token_ttl( 123 ),
			'The email token matches can be filtered with the deprecated filter'
		);

		remove_all_filters( 'two_factor_token_ttl' );
	}
}
