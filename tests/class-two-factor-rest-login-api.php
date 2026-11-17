<?php
/**
 * Tests for the REST login challenge and revalidation endpoints.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_REST_Login_API
 *
 * @package Two_Factor
 * @group core
 * @group rest-login
 */
class Tests_Two_Factor_REST_Login_API extends WP_Test_REST_TestCase {

	/**
	 * Cookies set through wp_set_auth_cookie() during the test, keyed by type.
	 *
	 * @var array
	 */
	private $cookies = array();

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->cookies = array();

		add_action( 'set_auth_cookie', array( $this, 'capture_auth_cookie' ), 10, 2 );
		add_action( 'set_logged_in_cookie', array( $this, 'capture_logged_in_cookie' ) );

		// Failed nonce verifications are expected here; keep them out of the error log.
		add_filter( 'two_factor_log_login_nonce_failures', '__return_false' );

		wp_set_current_user( 0 );
		reset_phpmailer_instance();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_action( 'set_auth_cookie', array( $this, 'capture_auth_cookie' ), 10 );
		remove_action( 'set_logged_in_cookie', array( $this, 'capture_logged_in_cookie' ) );
		remove_filter( 'two_factor_log_login_nonce_failures', '__return_false' );
		remove_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_REQUEST['authcode'] );
		reset_phpmailer_instance();

		parent::tear_down();
	}

	/**
	 * Record the auth cookie and its persistence.
	 *
	 * @param string $cookie The auth cookie.
	 * @param int    $expire The time the cookie expires in the browser, 0 for a session cookie.
	 */
	public function capture_auth_cookie( $cookie, $expire ) {
		$this->cookies['auth']        = $cookie;
		$this->cookies['auth_expire'] = $expire;
	}

	/**
	 * Record the logged-in cookie.
	 *
	 * @param string $cookie The logged-in cookie.
	 */
	public function capture_logged_in_cookie( $cookie ) {
		$this->cookies['logged_in'] = $cookie;
	}

	/**
	 * Create a user with TOTP configured as their only provider.
	 *
	 * @return array The user and the TOTP secret.
	 */
	private function create_totp_user() {
		$user = self::factory()->user->create_and_get();
		$key  = Two_Factor_Totp::generate_key();

		Two_Factor_Totp::get_instance()->set_user_totp_key( $user->ID, $key );
		$this->assertTrue( Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Totp' ) );

		return array( $user, $key );
	}

	/**
	 * Create a user with the given provider enabled.
	 *
	 * @param string $provider_key Provider key.
	 * @return WP_User
	 */
	private function create_user_with_provider( $provider_key ) {
		$user = self::factory()->user->create_and_get();
		$this->assertTrue( Two_Factor_Core::enable_provider_for_user( $user->ID, $provider_key ) );

		return $user;
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string $route  Route below the plugin namespace.
	 * @param array  $params Body parameters.
	 * @return WP_REST_Response
	 */
	private function dispatch( $route, array $params ) {
		$request = new WP_REST_Request( 'POST', '/' . Two_Factor_Core::REST_NAMESPACE . $route );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * Log a user in with a real session token, flagged as two-factor authenticated at the given time.
	 *
	 * @param int      $user_id          User ID.
	 * @param int|null $two_factor_login Time of the last two-factor validation, null for none.
	 * @return string The session token.
	 */
	private function set_up_session_for_user( $user_id, $two_factor_login = null ) {
		wp_set_current_user( $user_id );

		$expiration = time() + HOUR_IN_SECONDS;
		$manager    = WP_Session_Tokens::get_instance( $user_id );
		$token      = $manager->create( $expiration );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );

		if ( null !== $two_factor_login ) {
			Two_Factor_Core::update_current_user_session(
				array(
					'two-factor-provider' => 'Two_Factor_Totp',
					'two-factor-login'    => $two_factor_login,
				)
			);
		}

		return $token;
	}

	/**
	 * A valid code completes the login exactly like the login form.
	 *
	 * @covers Two_Factor_Core::rest_login
	 * @covers Two_Factor_Core::complete_two_factor_login
	 * @covers Two_Factor_Totp::validate_authentication_code
	 */
	public function test_login_success_sets_auth_cookie_session_and_fires_action() {
		list( $user, $key ) = $this->create_totp_user();
		$login_nonce        = Two_Factor_Core::create_login_nonce( $user->ID );

		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 2 );

		$authenticated = did_action( 'two_factor_user_authenticated' );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'provider'    => 'Two_Factor_Totp',
				'code'        => Two_Factor_Totp::calc_totp( $key ),
				'redirect_to' => home_url( '/account/' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'authenticated', $data['result'] );
		$this->assertSame( $user->ID, $data['user_id'] );
		$this->assertSame( 'Two_Factor_Totp', $data['provider'] );
		$this->assertSame( home_url( '/account/' ), $data['redirect_to'] );

		$this->assertSame( $authenticated + 1, did_action( 'two_factor_user_authenticated' ), 'The authenticated action fires once.' );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_META_NONCE_KEY, true ), 'The login nonce is consumed.' );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ), 'Failed attempts are cleared.' );

		$this->assertArrayHasKey( 'auth', $this->cookies, 'An auth cookie is issued.' );
		$this->assertSame( 0, $this->cookies['auth_expire'], 'Without remember, the auth cookie is a session cookie.' );

		$cookie = wp_parse_auth_cookie( $this->cookies['logged_in'], 'logged_in' );
		$this->assertSame( $user->user_login, $cookie['username'] );

		$session = WP_Session_Tokens::get_instance( $user->ID )->get( $cookie['token'] );
		$this->assertNotEmpty( $session['two-factor-login'], 'The session is flagged as two-factor authenticated.' );
		$this->assertSame( 'Two_Factor_Totp', $session['two-factor-provider'] );
	}

	/**
	 * The remember flag issues a persistent auth cookie.
	 *
	 * @covers Two_Factor_Core::rest_login
	 */
	public function test_login_remember_issues_persistent_cookie() {
		$user        = $this->create_user_with_provider( 'Two_Factor_Dummy' );
		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'code'        => 'anything',
				'remember'    => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertGreaterThan( time(), $this->cookies['auth_expire'] );
	}

	/**
	 * Unsafe redirect destinations are replaced with the default.
	 *
	 * @covers Two_Factor_Core::rest_login
	 */
	public function test_login_rejects_offsite_redirect() {
		$user        = $this->create_user_with_provider( 'Two_Factor_Dummy' );
		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'code'        => 'anything',
				'redirect_to' => 'https://attacker.example/',
			)
		);

		$this->assertSame( admin_url(), $response->get_data()['redirect_to'] );
	}

	/**
	 * Invalid nonces and unknown users get the same response, and nothing is recorded.
	 *
	 * @covers Two_Factor_Core::rest_login
	 */
	public function test_login_invalid_nonce_does_not_enumerate() {
		list( $user, $key ) = $this->create_totp_user();
		$login_nonce        = Two_Factor_Core::create_login_nonce( $user->ID );
		$stored             = get_user_meta( $user->ID, Two_Factor_Core::USER_META_NONCE_KEY, true );

		$wrong_nonce = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => 'not-the-nonce',
				'code'        => Two_Factor_Totp::calc_totp( $key ),
			)
		);
		$this->assertErrorResponse( 'two_factor_invalid_login_nonce', $wrong_nonce, 401 );

		$unknown_user = $this->dispatch(
			'/login',
			array(
				'user_id'     => PHP_INT_MAX,
				'login_nonce' => $login_nonce['key'],
				'code'        => '123456',
			)
		);
		$this->assertErrorResponse( 'two_factor_invalid_login_nonce', $unknown_user, 401 );
		$this->assertSame( $wrong_nonce->get_data()['message'], $unknown_user->get_data()['message'] );

		$this->assertSame( $stored, get_user_meta( $user->ID, Two_Factor_Core::USER_META_NONCE_KEY, true ), 'A wrong nonce does not end the pending login.' );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
		$this->assertArrayNotHasKey( 'auth', $this->cookies );
		$this->assertArrayNotHasKey( 'login_nonce', (array) $wrong_nonce->get_data()['data'] );
	}

	/**
	 * An expired nonce is rejected.
	 *
	 * @covers Two_Factor_Core::rest_login
	 */
	public function test_login_expired_nonce() {
		$user        = $this->create_user_with_provider( 'Two_Factor_Dummy' );
		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );

		$stored               = get_user_meta( $user->ID, Two_Factor_Core::USER_META_NONCE_KEY, true );
		$stored['expiration'] = time() - 1;
		update_user_meta( $user->ID, Two_Factor_Core::USER_META_NONCE_KEY, $stored );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'code'        => 'anything',
			)
		);

		$this->assertErrorResponse( 'two_factor_invalid_login_nonce', $response, 401 );
		$this->assertArrayNotHasKey( 'auth', $this->cookies );
	}

	/**
	 * A wrong code is recorded as a failure and the client gets a new nonce to retry with.
	 *
	 * @covers Two_Factor_Core::rest_login
	 * @covers Two_Factor_Core::process_provider_attempt
	 */
	public function test_login_invalid_code_increments_failures_and_rotates_nonce() {
		list( $user, $key ) = $this->create_totp_user();
		$login_nonce        = Two_Factor_Core::create_login_nonce( $user->ID );
		$failed             = did_action( 'wp_login_failed' );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'code'        => '000000' === Two_Factor_Totp::calc_totp( $key ) ? '111111' : '000000',
			)
		);

		$this->assertErrorResponse( 'two_factor_invalid', $response, 401 );
		$this->assertSame( 1, (int) get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
		$this->assertNotEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, true ) );
		$this->assertSame( $failed + 1, did_action( 'wp_login_failed' ) );
		$this->assertArrayNotHasKey( 'auth', $this->cookies );

		$data = $response->get_data()['data'];
		$this->assertNotSame( $login_nonce['key'], $data['login_nonce'] );
		$this->assertTrue( Two_Factor_Core::verify_login_nonce( $user->ID, $data['login_nonce'] ), 'The returned nonce is the pending login nonce.' );
		$this->assertFalse( Two_Factor_Core::verify_login_nonce( $user->ID, $login_nonce['key'] ), 'The previous nonce no longer works.' );
	}

	/**
	 * The code comes from the REST request, never from $_REQUEST.
	 *
	 * @covers Two_Factor_Core::rest_login
	 * @covers Two_Factor_Totp::validate_authentication_code
	 */
	public function test_login_ignores_code_in_global_request() {
		list( $user, $key ) = $this->create_totp_user();
		$login_nonce        = Two_Factor_Core::create_login_nonce( $user->ID );
		$valid_code         = Two_Factor_Totp::calc_totp( $key );

		$_REQUEST['authcode'] = $valid_code;

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'code'        => '000000' === $valid_code ? '111111' : '000000',
			)
		);

		$this->assertErrorResponse( 'two_factor_invalid', $response, 401 );
		$this->assertArrayNotHasKey( 'auth', $this->cookies );
	}

	/**
	 * Rate limited attempts are rejected with 429 before the code is checked.
	 *
	 * @covers Two_Factor_Core::rest_login
	 * @covers Two_Factor_Core::process_provider_attempt
	 */
	public function test_login_rate_limited_returns_429_with_retry_after() {
		list( $user, $key ) = $this->create_totp_user();
		$login_nonce        = Two_Factor_Core::create_login_nonce( $user->ID );

		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 3 );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'code'        => Two_Factor_Totp::calc_totp( $key ),
			)
		);

		$this->assertErrorResponse( 'two_factor_too_fast', $response, 429 );

		$data = $response->get_data()['data'];
		$this->assertGreaterThan( 0, $data['retry_after'] );
		$this->assertLessThanOrEqual( 8, $data['retry_after'] );
		$this->assertSame( (string) $data['retry_after'], $response->get_headers()['Retry-After'] );
		$this->assertTrue( Two_Factor_Core::verify_login_nonce( $user->ID, $data['login_nonce'] ) );

		$this->assertSame( 3, (int) get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ), 'Rate-limited attempts are not counted.' );
		$this->assertArrayNotHasKey( 'auth', $this->cookies, 'Even a valid code is rejected while rate limited.' );
	}

	/**
	 * Reaching the failed attempt limit resets the password and returns structured data instead of a page.
	 *
	 * @covers Two_Factor_Core::rest_login
	 * @covers Two_Factor_Core::process_provider_attempt
	 */
	public function test_login_password_reset_required_is_structured() {
		$user        = $this->create_user_with_provider( 'Two_Factor_Email' );
		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );
		$old_hash    = $user->user_pass;

		add_filter( 'two_factor_failed_attempt_limit', '__return_zero' );

		$this->expectOutputString( '' );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'code'        => '12345678',
			)
		);

		remove_filter( 'two_factor_failed_attempt_limit', '__return_zero' );

		$this->assertErrorResponse( 'two_factor_password_reset_required', $response, 403 );
		$this->assertArrayNotHasKey( 'login_nonce', $response->get_data()['data'] );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_META_NONCE_KEY, true ), 'The pending login can no longer be completed.' );
		$this->assertNotEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true ) );
		$this->assertNotSame( $old_hash, get_userdata( $user->ID )->user_pass, 'The password was reset.' );
		$this->assertArrayNotHasKey( 'auth', $this->cookies );
	}

	/**
	 * Without a code, the endpoint describes the challenge and rotates the nonce.
	 *
	 * @covers Two_Factor_Core::rest_login
	 * @covers Two_Factor_Core::get_rest_challenge_data
	 */
	public function test_login_without_code_returns_challenge() {
		list( $user ) = $this->create_totp_user();
		Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );
		Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Backup_Codes' );
		update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Totp' );
		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'challenge', $data['result'] );
		$this->assertSame( 'Two_Factor_Totp', $data['provider'] );
		$this->assertEqualsCanonicalizing( array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ), wp_list_pluck( $data['providers'], 'key' ) );
		$this->assertTrue( Two_Factor_Core::verify_login_nonce( $user->ID, $data['login_nonce'] ) );
		$this->assertGreaterThan( time(), $data['expires'] );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ), 'Asking for the challenge is not a failed attempt.' );
	}

	/**
	 * A provider the user has not enabled is rejected instead of silently swapped.
	 *
	 * @covers Two_Factor_Core::rest_login
	 * @covers Two_Factor_Core::get_attempt_provider
	 */
	public function test_login_provider_not_enabled() {
		list( $user ) = $this->create_totp_user();
		$login_nonce  = Two_Factor_Core::create_login_nonce( $user->ID );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'provider'    => 'Two_Factor_Email',
				'code'        => '12345678',
			)
		);

		$this->assertErrorResponse( 'two_factor_provider_not_enabled', $response, 400 );
		$this->assertTrue( Two_Factor_Core::verify_login_nonce( $user->ID, $response->get_data()['data']['login_nonce'] ) );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
	}

	/**
	 * The email provider sends a code on request, and accepts it.
	 *
	 * @covers Two_Factor_Core::rest_login
	 * @covers Two_Factor_Email::pre_process_authentication_with_args
	 * @covers Two_Factor_Email::validate_authentication_code
	 */
	public function test_login_email_resend_and_validate() {
		$user        = $this->create_user_with_provider( 'Two_Factor_Email' );
		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );
		$email       = Two_Factor_Email::get_instance();

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'resend'      => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'code_sent', $data['result'] );
		$this->assertTrue( $email->user_has_token( $user->ID ) );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );

		// The emailed code isn't recoverable from storage, so issue a known one.
		$code = $email->generate_token( $user->ID );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $data['login_nonce'],
				'code'        => $code,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Two_Factor_Email', $response->get_data()['provider'] );
		$this->assertFalse( $email->user_has_token( $user->ID ), 'The code is single use.' );
	}

	/**
	 * Recovery codes work and are consumed.
	 *
	 * @covers Two_Factor_Core::rest_login
	 * @covers Two_Factor_Backup_Codes::validate_authentication_code
	 */
	public function test_login_with_backup_code() {
		$user  = self::factory()->user->create_and_get();
		$codes = Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );
		Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Backup_Codes' );
		$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );

		$response = $this->dispatch(
			'/login',
			array(
				'user_id'     => $user->ID,
				'login_nonce' => $login_nonce['key'],
				'provider'    => 'Two_Factor_Backup_Codes',
				'code'        => $codes[0],
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( count( $codes ) - 1, Two_Factor_Backup_Codes::codes_remaining_for_user( $user ) );
	}

	/**
	 * Providers that only implement the request-based validation keep their behavior.
	 *
	 * @covers Two_Factor_Provider::validate_authentication_code
	 */
	public function test_base_provider_falls_back_to_request_validation() {
		$user = self::factory()->user->create_and_get();

		if ( ! class_exists( 'Two_Factor_Dummy_Secure' ) ) {
			require_once __DIR__ . '/class-two-factor-dummy-secure.php';
		}

		$this->assertTrue( Two_Factor_Dummy::get_instance()->validate_authentication_code( $user, 'anything' ) );
		$this->assertFalse( Two_Factor_Dummy_Secure::get_instance()->validate_authentication_code( $user, 'anything' ) );
	}

	/**
	 * Revalidation requires a logged-in user.
	 *
	 * @covers Two_Factor_Core::rest_revalidate_permissions_check
	 */
	public function test_revalidate_requires_login() {
		$response = $this->dispatch( '/revalidate', array( 'code' => '123456' ) );

		$this->assertErrorResponse( 'rest_not_logged_in', $response, 401 );
	}

	/**
	 * Without a cookie session, and without an integration reporting one, revalidation is refused.
	 *
	 * @covers Two_Factor_Core::rest_revalidate
	 */
	public function test_revalidate_requires_session() {
		list( $user, $key ) = $this->create_totp_user();
		wp_set_current_user( $user->ID );

		$revalidated = did_action( 'two_factor_user_revalidated' );

		$response = $this->dispatch( '/revalidate', array( 'code' => Two_Factor_Totp::calc_totp( $key ) ) );

		$this->assertErrorResponse( 'two_factor_session_required', $response, 400 );
		$this->assertSame( $revalidated, did_action( 'two_factor_user_revalidated' ) );
	}

	/**
	 * A non-cookie session (e.g. a bearer token) reported through the filter can revalidate.
	 *
	 * @covers Two_Factor_Core::rest_revalidate
	 * @covers Two_Factor_Core::get_current_session_two_factor_time
	 * @covers Two_Factor_Core::complete_two_factor_revalidation
	 */
	public function test_revalidate_token_session_via_filter() {
		list( $user, $key ) = $this->create_totp_user();
		wp_set_current_user( $user->ID );

		// A token integration storing its own "two-factor verified at" time.
		$verified_at = time() - DAY_IN_SECONDS;
		$filter      = static function ( $time, $filter_user ) use ( &$verified_at, $user ) {
			return $filter_user->ID === $user->ID ? $verified_at : $time;
		};
		$recorded    = array();
		$action      = static function ( $revalidated_user, $provider ) use ( &$verified_at, &$recorded ) {
			$recorded[]  = array( $revalidated_user->ID, $provider->get_key() );
			$verified_at = time();
		};
		add_filter( 'two_factor_current_session_two_factor_time', $filter, 10, 2 );
		add_action( 'two_factor_user_revalidated', $action, 10, 2 );

		$this->assertFalse( Two_Factor_Core::get_current_user_session(), 'There is no cookie session.' );
		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ), 'Revalidation is required before.' );

		$denied = Two_Factor_Core::rest_api_can_edit_user_and_update_two_factor_options( $user->ID );
		$this->assertWPError( $denied );
		$this->assertSame( '/two-factor/1.0/revalidate', $denied->get_error_data()['revalidation']['rest_route'] );

		$response = $this->dispatch( '/revalidate', array( 'code' => Two_Factor_Totp::calc_totp( $key ) ) );

		remove_filter( 'two_factor_current_session_two_factor_time', $filter, 10 );
		remove_action( 'two_factor_user_revalidated', $action, 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'revalidated', $response->get_data()['result'] );
		$this->assertGreaterThanOrEqual( time() - 5, $response->get_data()['two_factor_login'] );
		$this->assertSame( array( array( $user->ID, 'Two_Factor_Totp' ) ), $recorded );
		$this->assertGreaterThanOrEqual( time() - 5, $verified_at );
	}

	/**
	 * A filtered value of 0 marks a known session that has not passed two-factor yet: it may revalidate.
	 *
	 * @covers Two_Factor_Core::rest_revalidate
	 * @covers Two_Factor_Core::is_current_user_session_two_factor
	 */
	public function test_revalidate_token_session_not_yet_two_factor() {
		list( $user, $key ) = $this->create_totp_user();
		wp_set_current_user( $user->ID );

		add_filter( 'two_factor_current_session_two_factor_time', '__return_zero' );

		$this->assertFalse( Two_Factor_Core::is_current_user_session_two_factor() );

		$response = $this->dispatch( '/revalidate', array( 'code' => Two_Factor_Totp::calc_totp( $key ) ) );

		remove_filter( 'two_factor_current_session_two_factor_time', '__return_zero' );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * A failed revalidation for a token session fires wp_login_failed and not the revalidated action.
	 *
	 * @covers Two_Factor_Core::rest_revalidate
	 */
	public function test_revalidate_token_session_invalid_code() {
		list( $user, $key ) = $this->create_totp_user();
		wp_set_current_user( $user->ID );

		$filter = static function () {
			return time() - DAY_IN_SECONDS;
		};
		add_filter( 'two_factor_current_session_two_factor_time', $filter );

		$failed      = did_action( 'wp_login_failed' );
		$revalidated = did_action( 'two_factor_user_revalidated' );

		$response = $this->dispatch(
			'/revalidate',
			array( 'code' => '000000' === Two_Factor_Totp::calc_totp( $key ) ? '111111' : '000000' )
		);

		remove_filter( 'two_factor_current_session_two_factor_time', $filter );

		$this->assertErrorResponse( 'two_factor_invalid', $response, 401 );
		$this->assertSame( $failed + 1, did_action( 'wp_login_failed' ) );
		$this->assertSame( $revalidated, did_action( 'two_factor_user_revalidated' ) );
	}

	/**
	 * A valid code updates the session revalidation timestamp like the revalidation form.
	 *
	 * @covers Two_Factor_Core::rest_revalidate
	 * @covers Two_Factor_Core::complete_two_factor_revalidation
	 */
	public function test_revalidate_success_updates_session() {
		list( $user, $key ) = $this->create_totp_user();
		$token              = $this->set_up_session_for_user( $user->ID, time() - DAY_IN_SECONDS );
		$revalidated        = did_action( 'two_factor_user_revalidated' );

		$this->assertFalse( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ), 'Revalidation is required before.' );

		$challenge = $this->dispatch( '/revalidate', array() );
		$this->assertSame( 'challenge', $challenge->get_data()['result'] );
		$this->assertSame( 'Two_Factor_Totp', $challenge->get_data()['provider'] );
		$this->assertArrayNotHasKey( 'login_nonce', $challenge->get_data() );

		$response = $this->dispatch( '/revalidate', array( 'code' => Two_Factor_Totp::calc_totp( $key ) ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'revalidated', $data['result'] );
		$this->assertSame( 'Two_Factor_Totp', $data['provider'] );
		$this->assertGreaterThanOrEqual( time() - 5, $data['two_factor_login'] );

		$session = WP_Session_Tokens::get_instance( $user->ID )->get( $token );
		$this->assertGreaterThanOrEqual( time() - 5, $session['two-factor-login'] );
		$this->assertTrue( Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ), 'Settings can be changed after.' );
		$this->assertSame( $revalidated + 1, did_action( 'two_factor_user_revalidated' ) );
	}

	/**
	 * A wrong revalidation code counts as a failure but never resets the password of a logged-in user.
	 *
	 * @covers Two_Factor_Core::rest_revalidate
	 * @covers Two_Factor_Core::process_provider_attempt
	 */
	public function test_revalidate_invalid_code() {
		list( $user, $key ) = $this->create_totp_user();
		$old_login          = time() - DAY_IN_SECONDS;
		$token              = $this->set_up_session_for_user( $user->ID, $old_login );

		add_filter( 'two_factor_failed_attempt_limit', '__return_zero' );

		$response = $this->dispatch(
			'/revalidate',
			array( 'code' => '000000' === Two_Factor_Totp::calc_totp( $key ) ? '111111' : '000000' )
		);

		remove_filter( 'two_factor_failed_attempt_limit', '__return_zero' );

		$this->assertErrorResponse( 'two_factor_invalid', $response, 401 );
		$this->assertSame( 1, (int) get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
		$this->assertEmpty( get_user_meta( $user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true ) );

		$session = WP_Session_Tokens::get_instance( $user->ID )->get( $token );
		$this->assertSame( $old_login, $session['two-factor-login'], 'The session is not revalidated.' );
	}

	/**
	 * Revalidation attempts are rate limited.
	 *
	 * @covers Two_Factor_Core::rest_revalidate
	 */
	public function test_revalidate_rate_limited() {
		list( $user, $key ) = $this->create_totp_user();
		$this->set_up_session_for_user( $user->ID, time() - DAY_IN_SECONDS );

		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 3 );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );

		$response = $this->dispatch( '/revalidate', array( 'code' => Two_Factor_Totp::calc_totp( $key ) ) );

		$this->assertErrorResponse( 'two_factor_too_fast', $response, 429 );
		$this->assertArrayHasKey( 'Retry-After', $response->get_headers() );
	}
}
