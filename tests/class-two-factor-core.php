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
	 * Original User ID set in setup.
	 *
	 * @var int
	 */
	private $old_user_id;

	/**
	 * Set up error handling before test suite.
	 *
	 * @see WP_UnitTestCase::setUpBeforeClass()
	 */
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();

		set_error_handler( array( 'Test_ClassTwoFactorCore', 'error_handler' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
	}

	/**
	 * Clean up error settings after test suite.
	 *
	 * @see WP_UnitTestCase::setUpBeforeClass()
	 */
	public static function tearDownAfterClass() {
		restore_error_handler();

		parent::tearDownAfterClass();
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
	 * Get a dummy user object.
	 *
	 * @param array $meta_key authentication method.
	 *
	 * @return WP_User
	 */
	public function get_dummy_user( $meta_key = array( 'Two_Factor_Dummy' => 'Two_Factor_Dummy' ) ) {
		$user              = new WP_User( $this->factory->user->create() );
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
		$this->assertGreaterThan(
			0,
			has_action(
				'login_form_backup_2fa',
				array( 'Two_Factor_Core', 'backup_2fa' )
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
		$user        = new WP_User( $this->factory->user->create() );
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
	 */
	public function test_get_enabled_providers_for_user_logged_in_and_set_provider() {
		$user = $this->get_dummy_user();

		$this->assertCount( 1, Two_Factor_Core::get_available_providers_for_user( $user->ID ) );
		$this->assertCount( 1, Two_Factor_Core::get_enabled_providers_for_user( $user->ID ) );

		wp_set_current_user( $this->old_user_id );
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
		$user        = new WP_User( $this->factory->user->create() );
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
		$this->assertContains( 'wp-login.php', Two_Factor_Core::login_url() );

		$this->assertContains(
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
		$user_id = $this->factory->user->create();

		$this->assertFalse( Two_Factor_Core::is_user_api_login_enabled( $user_id ) );
	}

	/**
	 * Verify user API log is can be enabled by filter.
	 *
	 * @covers Two_Factor_Core::is_user_api_login_enabled
	 */
	public function test_user_api_login_can_be_enabled_via_filter() {
		$user_id_default = $this->factory->user->create();
		$user_id_enabled = $this->factory->user->create();

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
		$user_default     = new WP_User( $this->factory->user->create() );
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
		$user_id = $this->factory->user->create(
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

}
