<?php
/**
 * Tests for the non-rendering verification API used by non-cookie authentication integrations.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Verification_API
 *
 * @package Two_Factor
 * @group core
 * @group verification-api
 */
class Tests_Two_Factor_Verification_API extends WP_Test_REST_TestCase {

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 );
		reset_phpmailer_instance();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_REQUEST['authcode'], $_REQUEST['two-factor-email-code-resend'] );
		reset_phpmailer_instance();

		parent::tear_down();
	}

	/**
	 * Create a user with TOTP configured as their only provider.
	 *
	 * @param string $role The user role.
	 * @return array The user and the TOTP secret.
	 */
	private function create_totp_user( $role = 'subscriber' ) {
		$user = self::factory()->user->create_and_get( array( 'role' => $role ) );
		$key  = Two_Factor_Totp::generate_key();

		Two_Factor_Totp::get_instance()->set_user_totp_key( $user->ID, $key );
		$this->assertTrue( Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Totp' ) );

		return array( $user, $key );
	}

	/**
	 * A TOTP code that is guaranteed to be wrong.
	 *
	 * @param string $key The TOTP secret.
	 * @return string
	 */
	private function wrong_code( $key ) {
		return '000000' === Two_Factor_Totp::calc_totp( $key ) ? '111111' : '000000';
	}

	/**
	 * A valid code returns true, without output, and clears nothing it shouldn't.
	 *
	 * @covers Two_Factor_Core::verify_authentication_code
	 * @covers Two_Factor_Core::process_provider_attempt
	 */
	public function test_verify_success() {
		list( $user, $key ) = $this->create_totp_user();
		$failed             = did_action( 'wp_login_failed' );

		$this->expectOutputString( '' );

		$result = Two_Factor_Core::verify_authentication_code( $user, 'Two_Factor_Totp', array( 'code' => Two_Factor_Totp::calc_totp( $key ) ) );

		$this->assertTrue( $result );
		$this->assertSame( $failed, did_action( 'wp_login_failed' ) );
		$this->assertFalse( is_user_logged_in(), 'Verification never logs the user in.' );
	}

	/**
	 * An empty provider key uses the primary provider.
	 *
	 * @covers Two_Factor_Core::verify_authentication_code
	 * @covers Two_Factor_Core::get_attempt_provider
	 */
	public function test_verify_defaults_to_primary_provider() {
		list( $user, $key ) = $this->create_totp_user();

		$this->assertTrue( Two_Factor_Core::verify_authentication_code( $user, '', array( 'code' => Two_Factor_Totp::calc_totp( $key ) ) ) );
	}

	/**
	 * The code is only read from the arguments, never from the request.
	 *
	 * @covers Two_Factor_Core::verify_authentication_code
	 */
	public function test_verify_ignores_request() {
		list( $user, $key )   = $this->create_totp_user();
		$_REQUEST['authcode'] = Two_Factor_Totp::calc_totp( $key );

		$result = Two_Factor_Core::verify_authentication_code( $user, 'Two_Factor_Totp', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_invalid', $result->get_error_code() );
	}

	/**
	 * A wrong code is a structured 401 with the same accounting as the form.
	 *
	 * @covers Two_Factor_Core::verify_authentication_code
	 * @covers Two_Factor_Core::process_provider_attempt
	 */
	public function test_verify_invalid() {
		list( $user, $key ) = $this->create_totp_user();
		$failed             = did_action( 'wp_login_failed' );

		$result = Two_Factor_Core::verify_authentication_code( $user, 'Two_Factor_Totp', array( 'code' => $this->wrong_code( $key ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_invalid', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertSame( $failed + 1, did_action( 'wp_login_failed' ) );
		$this->assertSame( 1, (int) get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
		$this->assertNotEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, true ) );
	}

	/**
	 * Rate limited attempts are a 429 with retry_after, even for a valid code.
	 *
	 * @covers Two_Factor_Core::verify_authentication_code
	 */
	public function test_verify_rate_limited() {
		list( $user, $key ) = $this->create_totp_user();

		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 3 );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );

		$failed = did_action( 'wp_login_failed' );
		$result = Two_Factor_Core::verify_authentication_code( $user, 'Two_Factor_Totp', array( 'code' => Two_Factor_Totp::calc_totp( $key ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_too_fast', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertGreaterThan( 0, $data['retry_after'] );
		$this->assertSame( $failed + 1, did_action( 'wp_login_failed' ) );
		$this->assertSame( 3, (int) get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ), 'Rate-limited attempts are not counted.' );
	}

	/**
	 * A provider the user hasn't enabled is a 400, and not counted as a failure.
	 *
	 * @covers Two_Factor_Core::verify_authentication_code
	 * @covers Two_Factor_Core::get_attempt_provider
	 */
	public function test_verify_provider_not_enabled() {
		list( $user ) = $this->create_totp_user();

		$result = Two_Factor_Core::verify_authentication_code( $user, 'Two_Factor_Email', array( 'code' => '12345678' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_provider_not_enabled', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
	}

	/**
	 * Reaching the failed attempt limit resets the password and returns a 403 instead of rendering a page.
	 *
	 * @covers Two_Factor_Core::verify_authentication_code
	 * @covers Two_Factor_Core::process_provider_attempt
	 */
	public function test_verify_password_reset_required() {
		list( $user, $key ) = $this->create_totp_user();
		$old_hash           = $user->user_pass;

		add_filter( 'two_factor_failed_attempt_limit', '__return_zero' );

		$this->expectOutputString( '' );

		$result = Two_Factor_Core::verify_authentication_code( $user, 'Two_Factor_Totp', array( 'code' => $this->wrong_code( $key ) ) );

		remove_filter( 'two_factor_failed_attempt_limit', '__return_zero' );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_password_reset_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertNotEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true ) );
		$this->assertNotSame( $old_hash, get_userdata( $user->ID )->user_pass );
	}

	/**
	 * Email resend sends a code and reports it, then the code validates.
	 *
	 * @covers Two_Factor_Core::verify_authentication_code
	 * @covers Two_Factor_Email::pre_process_authentication_with_args
	 */
	public function test_verify_email_resend() {
		$user = self::factory()->user->create_and_get();
		$this->assertTrue( Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Email' ) );

		$mailer = tests_retrieve_phpmailer_instance();
		$result = Two_Factor_Core::verify_authentication_code( $user, 'Two_Factor_Email', array( 'resend' => true ) );

		$this->assertSame( array( 'status' => 'code_sent' ), $result );
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ), 'A resend is not a failure.' );

		$token = Two_Factor_Email::get_instance()->generate_token( $user->ID );
		$this->assertTrue( Two_Factor_Core::verify_authentication_code( $user, 'Two_Factor_Email', array( 'code' => $token ) ) );
	}

	/**
	 * The challenge lists the enabled providers and the primary one.
	 *
	 * @covers Two_Factor_Core::get_login_challenge
	 */
	public function test_get_login_challenge() {
		list( $user ) = $this->create_totp_user();
		Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );
		Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Backup_Codes' );
		update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Totp' );

		$challenge = Two_Factor_Core::get_login_challenge( $user );

		$this->assertSame( 'Two_Factor_Totp', $challenge['provider'] );
		$this->assertEqualsCanonicalizing( array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ), wp_list_pluck( $challenge['providers'], 'key' ) );
		foreach ( $challenge['providers'] as $provider ) {
			$this->assertSame( array( 'key', 'label' ), array_keys( $provider ) );
			$this->assertNotEmpty( $provider['label'] );
		}
	}

	/**
	 * A user without two-factor gets an empty challenge.
	 *
	 * @covers Two_Factor_Core::get_login_challenge
	 */
	public function test_get_login_challenge_without_two_factor() {
		$user = self::factory()->user->create_and_get();

		$this->assertSame(
			array(
				'provider'  => '',
				'providers' => array(),
			),
			Two_Factor_Core::get_login_challenge( $user )
		);
	}

	/**
	 * Unfiltered, the session time comes from the cookie session, and a request without one has none.
	 *
	 * @covers Two_Factor_Core::get_current_session_two_factor_time
	 * @covers Two_Factor_Core::is_current_user_session_two_factor
	 */
	public function test_session_time_defaults_to_cookie_session() {
		list( $user ) = $this->create_totp_user();

		$this->assertFalse( Two_Factor_Core::get_current_session_two_factor_time(), 'Logged out.' );

		wp_set_current_user( $user->ID );
		$this->assertFalse( Two_Factor_Core::get_current_session_two_factor_time(), 'No cookie session.' );

		$received = null;
		$filter   = static function ( $time, $filter_user ) use ( &$received ) {
			$received = array( $time, $filter_user );
			return $time;
		};
		add_filter( 'two_factor_current_session_two_factor_time', $filter, 10, 2 );

		$expiration                  = time() + HOUR_IN_SECONDS;
		$token                       = WP_Session_Tokens::get_instance( $user->ID )->create( $expiration );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token );
		$login_time                  = time() - 30;
		Two_Factor_Core::update_current_user_session( array( 'two-factor-login' => $login_time ) );

		$this->assertSame( $login_time, Two_Factor_Core::is_current_user_session_two_factor() );

		remove_filter( 'two_factor_current_session_two_factor_time', $filter, 10 );

		$this->assertSame( $login_time, $received[0] );
		$this->assertSame( $user->ID, $received[1]->ID );
	}

	/**
	 * A token session (no cookie session) reported through the filter can manage two-factor settings over REST.
	 *
	 * @covers Two_Factor_Core::current_user_can_update_two_factor_options
	 * @covers Two_Factor_Core::rest_api_can_edit_user_and_update_two_factor_options
	 */
	public function test_filter_enables_settings_updates_for_token_session() {
		list( $user ) = $this->create_totp_user( 'administrator' );
		Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );
		Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Backup_Codes' );
		wp_set_current_user( $user->ID );
		if ( is_multisite() ) {
			grant_super_admin( $user->ID );
		}

		$this->assertFalse( Two_Factor_Core::get_current_user_session(), 'There is no cookie session.' );
		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ) );

		$request = new WP_REST_Request( 'DELETE', '/' . Two_Factor_Core::REST_NAMESPACE . '/totp' );
		$request->set_query_params( array( 'user_id' => $user->ID ) );

		$denied = rest_do_request( $request );
		$this->assertErrorResponse( 'revalidation_required', $denied, 403 );
		$this->assertSame( '/two-factor/1.0/revalidate', $denied->get_data()['data']['revalidation']['rest_route'] );
		$this->assertContains( 'Two_Factor_Totp', Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) );

		$filter = static function () {
			return time();
		};
		add_filter( 'two_factor_current_session_two_factor_time', $filter );

		$this->assertGreaterThanOrEqual( time() - 1, Two_Factor_Core::is_current_user_session_two_factor() );
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ) );

		$response = rest_do_request( $request );

		remove_filter( 'two_factor_current_session_two_factor_time', $filter );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( 'Two_Factor_Totp', Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) );
	}

	/**
	 * The grace window applies to the filtered time too.
	 *
	 * @covers Two_Factor_Core::current_user_can_update_two_factor_options
	 */
	public function test_filter_respects_revalidation_grace_window() {
		list( $user ) = $this->create_totp_user();
		wp_set_current_user( $user->ID );

		$filter = static function () {
			return time() - HOUR_IN_SECONDS;
		};
		add_filter( 'two_factor_current_session_two_factor_time', $filter );

		$this->assertNotFalse( Two_Factor_Core::is_current_user_session_two_factor() );
		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ) );

		remove_filter( 'two_factor_current_session_two_factor_time', $filter );
	}
}
