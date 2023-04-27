<?php
/**
 * Two Factor Core Class Tests.
 *
 * @package Two_Factor
 */

/**
 * Class Test_ClassTwoFactorCore
 *
 * @package Two_Factor
 * @group core
 */
class Test_ClassTwoFactorCore extends WP_UnitTestCase {

	/**
	 * Original User ID set in set_up().
	 *
	 * @var int
	 */
	private $old_user_id;

	/**
	 * Set up error handling before test suite.
	 *
	 * @see WP_UnitTestCase_Base::set_up_before_class()
	 */
	public static function wpSetUpBeforeClass() {
		set_error_handler( array( 'Test_ClassTwoFactorCore', 'error_handler' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		add_action( 'set_auth_cookie', [ __CLASS__, 'set_auth_cookie' ] );
		add_action( 'set_logged_in_cookie', [ __CLASS__, 'set_logged_in_cookie' ] );
	}

	/**
	 * Clean up error settings after test suite.
	 *
	 * @see WP_UnitTestCase_Base::tear_down_after_class()
	 */
	public static function wpTearDownAfterClass() {
		restore_error_handler();
		remove_action( 'set_auth_cookie', [ __CLASS__, 'set_auth_cookie' ] );
		remove_action( 'set_logged_in_cookie', [ __CLASS__, 'set_logged_in_cookie' ] );
	}

	/**
	 * Cleanup after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		unset( $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ LOGGED_IN_COOKIE ] );
	}

	/**
	 * Print error messages and return true if error is a notice
	 *
	 * @param integer $errno error number.
	 * @param string  $errstr error message text.
	 *
	 * @return boolean
	 */
	public static function error_handler( $errno, $errstr ) {
		if ( E_USER_NOTICE !== $errno ) {
			echo 'Received a non-notice error: ' . esc_html( $errstr );

			return false;
		}

		return true;
	}

	/**
	 * Simulate the auth cookie having being sent.
	 */
	public static function set_auth_cookie( $auth_cookie ) {
		$_COOKIE[ AUTH_COOKIE ] = $auth_cookie;
	}

	/**
	 * Simulate the logged_in cookie having being sent.
	 */
	public static function set_logged_in_cookie( $logged_in_cookie ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
	}

	/**
	 * Get a dummy user object.
	 *
	 * @param array $meta_key authentication method.
	 *
	 * @return WP_User
	 */
	public function get_dummy_user( $meta_key = array( 'Two_Factor_Dummy' => 'Two_Factor_Dummy' ) ) {
		$user              = new WP_User( self::factory()->user->create() );
		$this->old_user_id = get_current_user_id();
		wp_set_current_user( $user->ID );

		$key              = '_nonce_user_two_factor_options';
		$nonce            = wp_create_nonce( 'user_two_factor_options' );
		$_POST[ $key ]    = $nonce;
		$_REQUEST[ $key ] = $nonce;

		$_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] = $meta_key;

		Two_Factor_Core::user_two_factor_options_update( $user->ID );

		return $user;
	}

	/**
	 * Clean up the dummy user object data.
	 */
	public function clean_dummy_user() {
		unset( $_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] );

		$key = '_nonce_user_two_factor_options';
		unset( $_REQUEST[ $key ] );
		unset( $_POST[ $key ] );
	}

	/**
	 * Verify adding hooks.
	 *
	 * @covers Two_Factor_Core::add_hooks
	 */
	public function test_add_hooks() {
		Two_Factor_Core::add_hooks( new Two_Factor_Compat() );

		$this->assertGreaterThan(
			0,
			has_action(
				'init',
				array( 'Two_Factor_Core', 'get_providers' )
			)
		);
		$this->assertGreaterThan(
			0,
			has_action(
				'login_form_validate_2fa',
				array( 'Two_Factor_Core', 'login_form_validate_2fa' )
			)
		);
	}

	/**
	 * Verify provider list is not empty.
	 *
	 * @covers Two_Factor_Core::get_providers
	 */
	public function test_get_providers_not_empty() {
		$this->assertNotEmpty( Two_Factor_Core::get_providers() );
	}

	/**
	 * Verify provider class exists.
	 *
	 * @covers Two_Factor_Core::get_providers
	 */
	public function test_get_providers_class_exists() {
		$result = Two_Factor_Core::get_providers();

		foreach ( array_keys( $result ) as $class ) {
			$this->assertNotNull( class_exists( $class ) );
		}
	}

	/**
	 * @covers Two_Factor_Core::fetch_user
	 */
	public function test_fetch_user() {
		$this->assertFalse( Two_Factor_Core::fetch_user( null ) );

		$logged_in = self::factory()->user->create_and_get();
		wp_set_current_user( $logged_in->ID );

		$fetched = Two_Factor_Core::fetch_user( null );
		$this->assertSame( $logged_in->ID, $fetched->ID );

		$logged_out = self::factory()->user->create_and_get();

		$fetched = Two_Factor_Core::fetch_user( $logged_out->ID );
		$this->assertSame( $logged_out->ID, $fetched->ID );

		$fetched = Two_Factor_Core::fetch_user( $logged_out );
		$this->assertSame( $logged_out->ID, $fetched->ID );
	}

	/**
	 * Verify enabled providers for non-logged-in user.
	 *
	 * @covers Two_Factor_Core::get_enabled_providers_for_user
	 */
	public function test_get_enabled_providers_for_user_not_logged_in() {
		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user() );
	}

	/**
	 * Verify enabled providers for logged-in user.
	 *
	 * @covers Two_Factor_Core::get_enabled_providers_for_user
	 */
	public function test_get_enabled_providers_for_user_logged_in() {
		$user        = new WP_User( self::factory()->user->create() );
		$old_user_id = get_current_user_id();
		wp_set_current_user( $user->ID );

		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user() );

		wp_set_current_user( $old_user_id );
	}

	/**
	 * Verify enabled providers for logged-in user and set provider.
	 *
	 * @covers Two_Factor_Core::get_enabled_providers_for_user
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 * @covers Two_Factor_Core::user_two_factor_options_update
	 * @covers Two_Factor_Core::fetch_user
	 */
	public function test_get_enabled_providers_for_user_logged_in_and_set_provider() {
		$user = $this->get_dummy_user();

		$this->assertCount( 1, Two_Factor_Core::get_available_providers_for_user( $user->ID ) );
		$this->assertCount( 1, Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) );

		// Revert back to the previous user
		wp_set_current_user( $this->old_user_id );

		// Verify the counts are still correct for that user ID.
		$this->assertCount( 1, Two_Factor_Core::get_available_providers_for_user( $user->ID ) );
		$this->assertCount( 1, Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) );

		$this->clean_dummy_user();
	}

	/**
	 * Verify enabled providers for invalid input is empty.
	 *
	 * @covers Two_Factor_Core::get_enabled_providers_for_user
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 * @covers Two_Factor_Core::user_two_factor_options_update
	 * @covers Two_Factor_Core::fetch_user
	 */
	public function test_get_enabled_providers_for_user_bad_input() {
		$user = $this->get_dummy_user();

		$this->assertCount( 1, Two_Factor_Core::get_available_providers_for_user( $user->ID ) );
		$this->assertCount( 1, Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) );

		// Check that checking for an invalid input returns 0.
		$this->assertCount( 0, Two_Factor_Core::get_available_providers_for_user( 'bad-input' ) );
		$this->assertCount( 0, Two_Factor_Core::get_enabled_providers_for_user( 'bad-input' ) );

		$this->clean_dummy_user();
	}

	/**
	 * Verify enabled providers for logged-in user and set incorrect provider.
	 *
	 * @covers Two_Factor_Core::get_enabled_providers_for_user
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 * @covers Two_Factor_Core::user_two_factor_options_update
	 */
	public function test_get_enabled_providers_for_user_logged_in_and_set_provider_bad_enabled() {
		$user = $this->get_dummy_user( 'test_badness' );

		$this->assertEmpty( Two_Factor_Core::get_available_providers_for_user( $user->ID ) );
		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) );

		wp_set_current_user( $this->old_user_id );
		$this->clean_dummy_user();
	}

	/**
	 * Verify available providers for not-logged-in user.
	 *
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 */
	public function test_get_available_providers_for_user_not_logged_in() {
		$this->assertEmpty( Two_Factor_Core::get_available_providers_for_user() );
	}

	/**
	 * Verify available providers for logged-in user.
	 *
	 * @covers Two_Factor_Core::get_available_providers_for_user
	 */
	public function test_get_available_providers_for_user_logged_in() {
		$user        = new WP_User( self::factory()->user->create() );
		$old_user_id = get_current_user_id();
		wp_set_current_user( $user->ID );

		$this->assertEmpty( Two_Factor_Core::get_available_providers_for_user() );

		wp_set_current_user( $old_user_id );
	}

	/**
	 * Verify primary provider for not-logged-in user.
	 *
	 * @covers Two_Factor_Core::get_primary_provider_for_user
	 */
	public function test_get_primary_provider_for_user_not_logged_in() {
		$this->assertEmpty( Two_Factor_Core::get_primary_provider_for_user() );
	}

	/**
	 * Verify not-logged-in-user is using two facator.
	 *
	 * @covers Two_Factor_Core::is_user_using_two_factor
	 */
	public function test_is_user_using_two_factor_not_logged_in() {
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor() );
	}

	/**
	 * Verify the login URL.
	 *
	 * @covers Two_Factor_Core::login_url
	 */
	public function test_login_url() {
		$this->assertStringContainsString( 'wp-login.php', Two_Factor_Core::login_url() );

		$this->assertStringContainsString(
			'paramencoded=%2F%3D1',
			Two_Factor_Core::login_url(
				array(
					'paramencoded' => '/=1',
				)
			)
		);
	}

	/**
	 * Verify user API log is enabled (when disabled by default).
	 *
	 * @covers Two_Factor_Core::is_user_api_login_enabled
	 */
	public function test_user_api_login_is_disabled_by_default() {
		$user_id = self::factory()->user->create();

		$this->assertFalse( Two_Factor_Core::is_user_api_login_enabled( $user_id ) );
	}

	/**
	 * Verify user API log is can be enabled by filter.
	 *
	 * @covers Two_Factor_Core::is_user_api_login_enabled
	 */
	public function test_user_api_login_can_be_enabled_via_filter() {
		$user_id_default = self::factory()->user->create();
		$user_id_enabled = self::factory()->user->create();

		add_filter(
			'two_factor_user_api_login_enable',
			function( $enabled, $user_id ) use ( $user_id_enabled ) {
				return ( $user_id === $user_id_enabled );
			},
			10,
			2
		);

		$this->assertTrue(
			Two_Factor_Core::is_user_api_login_enabled( $user_id_enabled ),
			'Filters allows specific users to enable API login'
		);

		$this->assertFalse(
			Two_Factor_Core::is_user_api_login_enabled( $user_id_default ),
			'Filter doesnot impact other users'
		);

		// Undo all filters.
		remove_all_filters( 'two_factor_user_api_login_enable', 10 );
	}

	/**
	 * Verify request is not an API request.
	 *
	 * @covers Two_Factor_Core::is_api_request
	 */
	public function test_is_api_request() {
		$this->assertFalse( Two_Factor_Core::is_api_request() );
	}

	/**
	 * Verify authentication filters.
	 *
	 * @covers Two_Factor_Core::filter_authenticate
	 */
	public function test_filter_authenticate() {
		$user_default     = new WP_User( self::factory()->user->create() );
		$user_2fa_enabled = $this->get_dummy_user(); // User with a dummy two-factor method enabled.

		// TODO: Get Two_Factor_Core away from static methods to allow mocking this.
		define( 'XMLRPC_REQUEST', true );

		$this->assertInstanceOf(
			'WP_User',
			Two_Factor_Core::filter_authenticate( $user_default )
		);

		$this->assertInstanceOf(
			'WP_Error',
			Two_Factor_Core::filter_authenticate( $user_2fa_enabled )
		);
	}

	/**
	 * Verify destruction of auth session.
	 *
	 * @covers Two_Factor_Core::destroy_current_session_for_user
	 * @covers Two_Factor_Core::collect_auth_cookie_tokens
	 */
	public function test_can_distroy_auth_sessions() {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'username',
				'user_pass'  => 'password',
			)
		);

		$user = new WP_User( $user_id );

		$session_manager = WP_Session_Tokens::get_instance( $user_id );

		$this->assertCount( 0, $session_manager->get_all(), 'No user sessions are present first' );

		$user_authenticated = wp_signon(
			array(
				'user_login'    => 'username',
				'user_password' => 'password',
			)
		);

		$this->assertEquals( $user_authenticated, $user, 'User can authenticate' );
		$this->assertCount( 1, $session_manager->get_all(), 'Can fetch the authenticated session' );

		// Create one extra session which shouldn't be destroyed.
		$session_manager->create( time() + 60 * 60 );
		$this->assertCount( 2, $session_manager->get_all(), 'Can fetch active sessions' );

		// Now clear all active password-based sessions.
		Two_Factor_Core::destroy_current_session_for_user( $user );
		$this->assertCount( 1, $session_manager->get_all(), 'All known authentication sessions have been destroyed' );

		// Cleanup for the rest.
		$session_manager->destroy_all();
	}

	/**
	 * @covers Two_Factor_Core::create_login_nonce()
	 * @covers Two_Factor_Core::hash_login_nonce()
	 */
	public function test_invalid_hash_input_fails() {
		$nonce = Two_Factor_Core::create_login_nonce( NAN );

		$this->assertFalse( $nonce );
		$this->assertNotEmpty( json_last_error() );
	}

	/**
	 * @covers Two_Factor_Core::create_login_nonce()
	 * @covers Two_Factor_Core::hash_login_nonce()
	 */
	public function test_create_login_nonce() {
		$user              = self::factory()->user->create_and_get();
		$plain_nonce       = Two_Factor_Core::create_login_nonce( $user->ID );
		$hashed_nonce      = get_user_meta( $user->ID, Two_Factor_Core::USER_META_NONCE_KEY, true );
		$plain_key_length  = strlen( $plain_nonce['key'] );
		$hashed_key_length = strlen( $hashed_nonce['key'] );

		$this->assertSame( $user->ID, $plain_nonce['user_id'] );
		$this->assertGreaterThan( time() + ( 9 * MINUTE_IN_SECONDS ), $plain_nonce['expiration'] );
		$this->assertIsString( $plain_nonce['key'] );
		$this->assertTrue( 64 === $plain_key_length || 32 === $plain_key_length );

		$this->assertSame( $plain_nonce['expiration'], $hashed_nonce['expiration'] );
		$this->assertIsString( $hashed_nonce['key'] );
		$this->assertTrue( 32 === $hashed_key_length );
		$this->assertNotEquals( $plain_nonce['key'], $hashed_nonce['key'] );
	}

	/**
	 * Check if nonce can be verified.
	 *
	 * @covers Two_Factor_Core::create_login_nonce()
	 * @covers Two_Factor_Core::verify_login_nonce()
	 */
	public function test_can_verify_login_nonce() {
		$user_id = 123456;
		$nonce   = Two_Factor_Core::create_login_nonce( $user_id );

		$this->assertNotEmpty( $nonce['key'], 'Nonce key is present' );
		$this->assertNotEmpty( $nonce['expiration'], 'Nonce expiration is set' );

		$this->assertGreaterThan( time(), $nonce['expiration'], 'Nonce expiration is in the future' );
		$this->assertLessThan( time() + ( 10 * MINUTE_IN_SECONDS ) + 1, $nonce['expiration'], 'Nonce expiration is not more than 10 minutes' );

		$this->assertTrue(
			Two_Factor_Core::verify_login_nonce( $user_id, $nonce['key'] ),
			'Can verify login nonce'
		);

		$this->assertFalse(
			Two_Factor_Core::verify_login_nonce( $user_id, '1234' ),
			'Invalid nonce is invalid'
		);

		// Must create a new one since incorrect nonces deletes them.
		$nonce = Two_Factor_Core::create_login_nonce( $user_id );

		// Mark the nonce as expired.
		$nonce_in_meta               = get_user_meta( $user_id, Two_Factor_Core::USER_META_NONCE_KEY, true );
		$nonce_in_meta['expiration'] = time() - 1;
		update_user_meta( $user_id, Two_Factor_Core::USER_META_NONCE_KEY, $nonce_in_meta );

		$this->assertFalse(
			Two_Factor_Core::verify_login_nonce( $user_id, $nonce['key'] ),
			'Expired nonce is invalid'
		);
	}

	/**
	 * Invalid nonce deletes the valid nonce.
	 *
	 * @covers Two_Factor_Core::verify_login_nonce()
	 */
	public function test_invalid_nonce_deletes_valid_nonce() {
		$user_id = 123456;
		$nonce   = Two_Factor_Core::create_login_nonce( $user_id );

		$this->assertFalse(
			Two_Factor_Core::verify_login_nonce( $user_id, '1234' ),
			'Invalid nonce is invalid'
		);

		$this->assertFalse(
			Two_Factor_Core::verify_login_nonce( $user_id, $nonce['key'] ),
			'The correct nonce is not accepted after an invalid has been attempted'
		);
	}

	/**
	 * Test that the lockout time delay for two factor attempts is respected.
	 *
	 * @covers Two_Factor_Core::get_user_time_delay()
	 */
	public function test_get_user_time_delay() {
		$user = $this->get_dummy_user();

		// Default values, sans filters.
		$rate_limit     = 1;
		$max_rate_limit = 15 * MINUTE_IN_SECONDS;

		// User has never logged in, validate the minimum time delay is in play.
		$this->assertEquals( $rate_limit, Two_Factor_Core::get_user_time_delay( $user ) );

		// Simulate 5 failed login attempts, and validate that the lockout is as expected.
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 5 );
		$this->assertEquals( pow( 2, 5 ) * $rate_limit, Two_Factor_Core::get_user_time_delay( $user ) );

		// Simulate 100 failed login attempts, validate that the lockout is not greater than $max_rate_limit
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 100 );
		$this->assertEquals( $max_rate_limit, Two_Factor_Core::get_user_time_delay( $user ) );
	}

	/**
	 * Test that the user rate limit functions return as expected.
	 *
	 * @covers Two_Factor_Core::is_user_rate_limited()
	 */
	public function test_is_user_rate_limited() {
		$user = $this->get_dummy_user();

		// User has never logged in, validate they're not rate limited.
		$this->assertFalse( Two_Factor_Core::is_user_rate_limited( $user ) );

		// Failed login attempt at time(), user should be rate limited.
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 1 );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );
		$this->assertTrue( Two_Factor_Core::is_user_rate_limited( $user ) );

		// 8 failed logins a minite ago, user should be rate limited.
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 8 );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() - MINUTE_IN_SECONDS );
		$this->assertTrue( Two_Factor_Core::is_user_rate_limited( $user ) );

		// 8 failed logins an hour ago, user should not be rate limited.
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 8 );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() - HOUR_IN_SECONDS );
		$this->assertFalse( Two_Factor_Core::is_user_rate_limited( $user ) );
	}

	/**
	 * Test that the "invalid login attempts have occurred" login notice works as expected.
	 *
	 * @covers Two_Factor_Core::maybe_show_last_login_failure_notice()
	 */
	public function test_maybe_show_last_login_failure_notice() {
		$user = $this->get_dummy_user();

		// User has never logged in, validate they're not rate limited.
		ob_start();
		Two_Factor_Core::maybe_show_last_login_failure_notice( $user );
		$contents = ob_get_clean();

		$this->assertEmpty( $contents );

		// A failed login attempts 5 seconds ago.
		// Should throw a notice, even though it's the current user, it will only be displayed if there's no other 2FA errors.
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 1 );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() - 5 );
		ob_start();
		Two_Factor_Core::maybe_show_last_login_failure_notice( $user );
		$contents = ob_get_clean();

		$this->assertNotEmpty( $contents );
		$this->assertStringNotContainsString( '1 times', $contents );
		$this->assertStringContainsString( 'login without providing a valid two factor token', $contents );

		// 5 failed login attempts 5 hours ago - User should be informed.
		$five_hours_ago = time() - 5 * HOUR_IN_SECONDS;
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 5 );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, $five_hours_ago );
		ob_start();
		Two_Factor_Core::maybe_show_last_login_failure_notice( $user );
		$contents = ob_get_clean();

		$this->assertNotEmpty( $contents );
		$this->assertStringContainsString( '5 times', $contents );
		$this->assertStringContainsString( human_time_diff( $five_hours_ago ), $contents );
	}

	/**
	 * @covers Two_Factor_Core::maybe_show_reset_password_notice()
	 */
	public function test_no_reset_notice_when_no_errors() {
		$errors = new WP_Error();
		Two_Factor_Core::maybe_show_reset_password_notice( $errors );
		$this->assertCount( 0, $errors->get_error_codes() );
	}

	/**
	 * @covers Two_Factor_Core::maybe_show_reset_password_notice()
	 */
	public function test_no_reset_notice_when_different_error() {
		$errors = new WP_Error( 'foo_bar', 'Foo Bar' );
		Two_Factor_Core::maybe_show_reset_password_notice( $errors );
		$this->assertCount( 1, $errors->get_error_codes() );
		$this->assertSame( 'foo_bar', $errors->get_error_code() );
	}

	/**
	 * @covers Two_Factor_Core::maybe_show_reset_password_notice()
	 */
	public function test_no_reset_notice_when_password_not_reset() {
		$user         = self::factory()->user->create_and_get();
		$errors       = new WP_Error( 'incorrect_password', 'Incorrect password' );
		$_POST['log'] = $user->user_login;

		Two_Factor_Core::maybe_show_reset_password_notice( $errors );
		$this->assertCount( 1, $errors->get_error_codes() );
		$this->assertSame( 'incorrect_password', $errors->get_error_code() );
	}

	/**
	 * @covers Two_Factor_Core::maybe_show_reset_password_notice()
	 */
	public function test_reset_notice_when_password_was_reset() {
		$user         = self::factory()->user->create_and_get();
		$errors       = new WP_Error( 'incorrect_password', 'Incorrect password' );
		$_POST['log'] = $user->user_login;

	    update_user_meta( $user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true );
		Two_Factor_Core::maybe_show_reset_password_notice( $errors );
		$this->assertCount( 1, $errors->get_error_codes() );
		$this->assertSame( 'two_factor_password_reset', $errors->get_error_code() );
	}

	/**
	 * @covers Two_Factor_Core::clear_password_reset_notice()
	 */
	public function test_clear_password_reset_notice() {
		$user = self::factory()->user->create_and_get();
		update_user_meta( $user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true );

		Two_Factor_Core::clear_password_reset_notice( $user );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true ) );
	}

	/**
	 * @covers Two_Factor_Core::should_reset_password()
	 */
	public function test_should_reset_password() {
		$user = self::factory()->user->create_and_get();

		// Test default limit.
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 29 );
		$this->assertFalse( Two_Factor_Core::should_reset_password( $user->ID ) );
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 30 );
		$this->assertTrue( Two_Factor_Core::should_reset_password( $user->ID ) );
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 31 );
		$this->assertTrue( Two_Factor_Core::should_reset_password( $user->ID ) );

		// Test filtered limit.
		$strict_limit = function() {
			return 7;
		};

		add_filter( 'two_factor_failed_attempt_limit', $strict_limit );
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 6 );
		$this->assertFalse( Two_Factor_Core::should_reset_password( $user->ID ) );
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 7 );
		$this->assertTrue( Two_Factor_Core::should_reset_password( $user->ID ) );
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 8 );
		$this->assertTrue( Two_Factor_Core::should_reset_password( $user->ID ) );
		remove_filter( 'two_factor_failed_attempt_limit', $strict_limit );
	}

	/**
	 * Resetting a password should change the password and notify the user and admin.
	 *
	 * @covers Two_Factor_Core::reset_compromised_password()
	 */
	public function test_reset_compromised_password() {
		$user     = self::factory()->user->create_and_get();
		$old_hash = $user->user_pass;

		// Simulate entered password but failed 2FA too many times.
		Two_Factor_Core::create_login_nonce( $user->ID );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 30 );

		Two_Factor_Core::reset_compromised_password( $user );
		$user = get_user_by( 'id', $user->ID );
		$this->assertNotSame( $old_hash, $user->user_pass );
		$this->assertSame( '1', get_user_meta( $user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true ) );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_META_NONCE_KEY, true ) );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY ) );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY ) );
	}

	/**
	 * @covers Two_Factor_Core::send_password_reset_emails()
	 * @covers Two_Factor_Core::notify_user_password_reset()
	 * @covers Two_Factor_Core::notify_admin_user_password_reset()
	 */
	public function test_both_password_reset_notifications_sent() {
		$user        = self::factory()->user->create_and_get();
		$mailer      = tests_retrieve_phpmailer_instance();
		$admin_email = get_option( 'admin_email' );

		Two_Factor_Core::send_password_reset_emails( $user );

		$this->assertCount( 2, $mailer->mock_sent );
		$this->assertContains( $user->user_email, $mailer->mock_sent[0]['to'][0] );
		$this->assertContains( $admin_email, $mailer->mock_sent[1]['to'][0] );

		reset_phpmailer_instance();
	}

	/**
	 * @covers Two_Factor_Core::send_password_reset_emails()
	 * @covers Two_Factor_Core::notify_user_password_reset()
	 */
	public function test_single_email_sent_when_admin_password_reset() {
		$admin       = get_user_by( 'id', 1 );
		$mailer      = tests_retrieve_phpmailer_instance();
		$admin_email = get_option( 'admin_email' );

		Two_Factor_Core::send_password_reset_emails( $admin );

		$this->assertSame( $admin->user_email, $admin_email );
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertContains( $admin_email, $mailer->mock_sent[0]['to'][0] );
		$this->assertStringStartsWith( 'Your password was compromised', $mailer->mock_sent[0]['subject'] );

		reset_phpmailer_instance();
	}

	/**
	 * @covers Two_Factor_Core::send_password_reset_emails()
	 * @covers Two_Factor_Core::notify_user_password_reset()
	 */
	public function test_dont_notify_admin_when_filter_disabled() {
		$user        = self::factory()->user->create_and_get();
		$mailer      = tests_retrieve_phpmailer_instance();
		$admin_email = get_option( 'admin_email' );

		add_filter( 'two_factor_notify_admin_user_password_reset', '__return_false' );
		Two_Factor_Core::send_password_reset_emails( $user );
		remove_filter( 'two_factor_notify_admin_user_password_reset', '__return_false' );

		$this->assertNotSame( $user->user_email, $admin_email );
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertContains( $user->user_email, $mailer->mock_sent[0]['to'][0] );
		$this->assertNotContains( $admin_email, $mailer->mock_sent[0]['to'][0] );

		reset_phpmailer_instance();
	}

	/**
	 * @covers Two_Factor_Core::show_password_reset_error
	 */
	public function test_show_password_reset_error() {
		ob_start();
		Two_Factor_Core::show_password_reset_error();
		$contents = ob_get_clean();

		$this->assertStringContainsString( 'check your email for instructions on regaining access', $contents );
	}

	/**
	 * Ensure that when a user enables two factor, that they are able to continue to change settings.
	 *
	 * @covers Two_Factor_Core::current_user_can_update_two_factor_options()
	 * @covers Two_Factor_Core::user_two_factor_options_update()
	 */
	public function test_enabling_two_factor_is_factored_session() {
		$user              = self::factory()->user->create_and_get();

		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		// Set the cookie without going through two-factor, and fill in $_COOKIE.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );

		// Session is not two-factored.
		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		// Can view 2FA edit settings.
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options() );
		// Can save 2FA settings.
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ) );

		$key              = '_nonce_user_two_factor_options';
		$nonce            = wp_create_nonce( 'user_two_factor_options' );
		$_POST[ $key ]    = $nonce;
		$_REQUEST[ $key ] = $nonce;

		$_POST[ Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY ] = [ 'Two_Factor_Dummy' => 'Two_Factor_Dummy' ];

		Two_Factor_Core::user_two_factor_options_update( $user->ID );

		// Validate that the session is flagged as 2FA, the return value being int.
		$this->assertNotFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token   = wp_get_session_token();
		$session = $manager->get( $token );

		// Validate that the session provider is as expected.
		$this->assertArrayHasKey( 'two-factor-login', $session );
		$this->assertEquals( '', $session['two-factor-provider'] ); // No provider was used for login.
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, $session['two-factor-login'] );

		// Can view 2FA edit settings.
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options() );
		// Can save 2FA settings.
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ) );

	}

	/**
	 * Validate that a non-2fa login doesn't set the session two-factor data.
	 *
	 * @covers Two_Factor_Core::is_current_user_session_two_factor()
	 */
	public function test_is_current_user_session_two_factor_without_two_factor() {
		$user = $this->get_dummy_user();

		// Assert no cookies are set.
		$this->assertArrayNotHasKey( AUTH_COOKIE, $_COOKIE );
		$this->assertArrayNotHasKey( LOGGED_IN_COOKIE, $_COOKIE );

		// Assert user not logged in is false.
		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		// Set the cookie without going through two-factor, and fill in $_COOKIE.
		wp_set_auth_cookie( $user->ID );

		$this->assertNotEmpty( $_COOKIE[ AUTH_COOKIE ] );
		$this->assertNotEmpty( $_COOKIE[ LOGGED_IN_COOKIE ] );

		// Validate that the session is not flagged as 2FA
		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token   = wp_get_session_token();
		$session = $manager->get( $token );

		// Validate the Session data is not set.
		$this->assertArrayNotHasKey( 'two-factor-provider', $session );
	}

	/**
	 * Validate that a simulated 2fa login sets the session two-factor data.
	 *
	 * @covers Two_Factor_Core::is_current_user_session_two_factor()
	 * @covers Two_Factor_Core::current_user_can_update_two_factor_options()
	 * @covers Two_Factor_Core::_login_form_validate_2fa()
	 */
	public function test_is_current_user_session_two_factor_with_two_factor() {
		$user = $this->get_dummy_user( array( 'Two_Factor_Dummy' => 'Two_Factor_Dummy' ) );

		// Assert no cookies are set.
		$this->assertArrayNotHasKey( AUTH_COOKIE, $_COOKIE );
		$this->assertArrayNotHasKey( LOGGED_IN_COOKIE, $_COOKIE );

		// Assert user not logged in is false.
		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		// Display it.
		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );
		$this->assertNotFalse( $login_nonce );

		ob_start();
		Two_Factor_Core::_login_form_validate_2fa( $user, $login_nonce['key'], 'Two_Factor_Dummy', '', false );
		ob_end_clean();

		// Validate that the session is not set, as it wasn't a POST.
		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );
		$this->assertNotFalse( $login_nonce );

		// Process it.
		ob_start();
		Two_Factor_Core::_login_form_validate_2fa( $user, $login_nonce['key'], 'Two_Factor_Dummy', '', true );
		ob_end_clean();

		$this->assertNotEmpty( $_COOKIE[ AUTH_COOKIE ] );
		$this->assertNotEmpty( $_COOKIE[ LOGGED_IN_COOKIE ] );

		// Validate that the session is flagged as 2FA, the return value being int.
		$this->assertNotFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token   = wp_get_session_token();
		$session = $manager->get( $token );

		// Validate that the session provider is as expected.
		$this->assertArrayHasKey( 'two-factor-provider', $session );
		$this->assertEquals( 'Two_Factor_Dummy', $session['two-factor-provider'] );

	}

	/**
	 * Validate that a simulated 2fa revalidation updates the session two-factor data.
	 *
	 * @covers Two_Factor_Core::_login_form_revalidate_2fa()
	 * @covers Two_Factor_Core::current_user_can_update_two_factor_options()
	 */
	public function test_revalidation_sets_time() {
		$user = $this->get_dummy_user( array( 'Two_Factor_Dummy' => 'Two_Factor_Dummy' ) );

		// Assert no cookies are set.
		$this->assertArrayNotHasKey( AUTH_COOKIE, $_COOKIE );
		$this->assertArrayNotHasKey( LOGGED_IN_COOKIE, $_COOKIE );

		// Assert user not logged in is false.
		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );
		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options() );

		// Simulate a 2FA login.

		// Display it.
		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );
		$this->assertNotFalse( $login_nonce );

		ob_start();
		Two_Factor_Core::_login_form_validate_2fa( $user, $login_nonce['key'], 'Two_Factor_Dummy', '', false );
		ob_end_clean();

		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );
		$this->assertNotFalse( $login_nonce );

		// Process it.
		ob_start();
		Two_Factor_Core::_login_form_validate_2fa( $user, $login_nonce['key'], 'Two_Factor_Dummy', '', true );
		ob_end_clean();

		$this->assertNotEmpty( $_COOKIE[ AUTH_COOKIE ] );
		$this->assertNotEmpty( $_COOKIE[ LOGGED_IN_COOKIE ] );

		// Validate that the session is flagged as 2FA, and now-ish.
		$current_session_two_factor = Two_Factor_Core::is_current_user_session_two_factor();
		$this->assertNotFalse( $current_session_two_factor );
		// Verify that it was set to now.
		$this->assertLessThanOrEqual( time(), $current_session_two_factor );
		$this->assertGreaterThanOrEqual( time() - MINUTE_IN_SECONDS, $current_session_two_factor );

		// Validate that the user can update options.
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options() );

		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token   = wp_get_session_token();
		$session = $manager->get( $token );

		// Validate that the session provider is as expected.
		$this->assertArrayHasKey( 'two-factor-provider', $session );
		$this->assertEquals( 'Two_Factor_Dummy', $session['two-factor-provider'] );
		$this->assertEquals( $current_session_two_factor, $session['two-factor-login'] );

		// Set the Session to have started an hour ago.
		$session['two-factor-login'] = time() - HOUR_IN_SECONDS;
		$manager->update( $token, $session );

		// The session should now be "expired" for revalidation.
		$this->assertLessThan( time(), Two_Factor_Core::is_current_user_session_two_factor() );

		// Revalidate.
		// Simulate displaying it.
		ob_start();
		Two_Factor_Core::_login_form_revalidate_2fa( 'Two_Factor_Dummy', '', false );
		ob_end_clean();

		// Check it's still expired.
		$this->assertLessThan( time(), Two_Factor_Core::is_current_user_session_two_factor() );

		// Simulate clicking it.
		ob_start();
		Two_Factor_Core::_login_form_revalidate_2fa( 'Two_Factor_Dummy', '', true );
		ob_end_clean();

		// Validate that the session is flagged as 2FA, and set to now-ish.
		$current_session_two_factor = Two_Factor_Core::is_current_user_session_two_factor();
		$this->assertNotFalse( $current_session_two_factor );
		// Verify that it was set to now.
		$this->assertLessThanOrEqual( time(), $current_session_two_factor );
		$this->assertGreaterThanOrEqual( time() - MINUTE_IN_SECONDS, $current_session_two_factor );
	}

	/**
	 * @covers Two_Factor_Core::current_user_can_update_two_factor_options()
	 */
	public function test_current_user_can_update_two_factor_options() {
		// Logged out.
		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options() );

		// Create a user, set a session.
		$user = self::factory()->user->create_and_get();

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );

		// Logged in, no 2FA setup.
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options() );

		// Manually setup 2FA, but not through the User Options API, such that the above session is not-2fa.
		Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Dummy' );

		// Logged in, user has 2FA, session has no 2FA
		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options() );

		// Set the session as 2FA.
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token   = wp_get_session_token();
		$session = $manager->get( $token );

		$session['two-factor-provider'] = 'Two_Factor_Dummy';
		$session['two-factor-login']    = time();
		$manager->update( $token, $session );

		// Logged in, user has 2FA, session has 2FA "now".
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options() );

		// Set the two factor login time to a minute less than the grace time.
		$session['two-factor-login']    = time() - ( 11 * MINUTE_IN_SECONDS );
		$manager->update( $token, $session );

		// Logged in, user has 2FA, session has 2FA that's longer than the grace period. Can Save, can't Display.
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ) );
		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options() );

		// Set the two factor login time to a older than the saving grace time.
		$session['two-factor-login']    = time() - ( 30 * MINUTE_IN_SECONDS );
		$manager->update( $token, $session );

		// Logged in, user has 2FA, session has 2FA way past grace period. Can't Save, can't Display.
		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ) );
		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options() );
	}

}
