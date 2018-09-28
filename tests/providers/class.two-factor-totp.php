<?php
/**
 * Test Two Factor Email.
 */

/**
 * Class Tests_Two_Factor_Totp
 *
 * @package Two_Factor
 * @group providers
 */
class Tests_Two_Factor_Totp extends WP_UnitTestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_Totp
	 */
	protected $provider;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase::setup()
	 */
	public function setUp() {
		parent::setUp();

		$this->provider = Two_Factor_Totp::get_instance();
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

	/**
	 * @covers Two_Factor_Totp::get_instance
	 */
	public function test_get_instance() {
		$this->assertNotNull( $this->provider->get_instance() );
	}

	/**
	 * @covers Two_Factor_Totp::get_label
	 */
	public function test_get_label() {
		$this->assertContains( 'Google Authenticator', $this->provider->get_label() );
	}

	/**
	 * @covers Two_Factor_Totp::user_two_factor_options
	 */
	public function test_user_two_factor_options_empty() {
		$this->assertFalse( $this->provider->user_two_factor_options( get_current_user() ) );
	}

	/**
	 * @covers Two_Factor_Totp::user_two_factor_options
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_options_generates_key() {
		$user = new WP_User( $this->factory->user->create() );

		ob_start();
		$this->provider->user_two_factor_options( $user );
		$content = ob_get_clean();

		$this->assertContains( __( 'Authentication Code:', 'two-factor' ), $content );
	}

	/**
	 * @covers Two_Factor_Totp::user_two_factor_options_update
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_options_update_set_key_no_authcode() {
		$user = new WP_User( $this->factory->user->create() );

		$request_key = '_nonce_user_two_factor_totp_options';
		$_POST[$request_key] = wp_create_nonce( 'user_two_factor_totp_options' );
		$_REQUEST[$request_key] = $_POST[$request_key];

		$_POST['two-factor-totp-key'] = $this->provider->generate_key();

		ob_start();
		$this->provider->user_two_factor_options_update( $user->ID );
		$content = ob_get_clean();

		unset( $_POST['two-factor-totp-key'] );

		unset( $_REQUEST[$request_key] );
		unset( $_POST[$request_key] );

		$this->assertFalse( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * @covers Two_Factor_Totp::user_two_factor_options_update
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_options_update_set_key_bad_auth_code() {
		$user = new WP_User( $this->factory->user->create() );

		$request_key = '_nonce_user_two_factor_totp_options';
		$_POST[$request_key] = wp_create_nonce( 'user_two_factor_totp_options' );
		$_REQUEST[$request_key] = $_POST[$request_key];

		$_POST['two-factor-totp-key'] = $this->provider->generate_key();
		$_POST['two-factor-totp-authcode'] = 'bad_test_authcode';

		ob_start();
		$this->provider->user_two_factor_options_update( $user->ID );
		$content = ob_get_clean();

		unset( $_POST['two-factor-totp-authcode'] );
		unset( $_POST['two-factor-totp-key'] );

		unset( $_REQUEST[$request_key] );
		unset( $_POST[$request_key] );

		$this->assertFalse( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * @covers Two_Factor_Totp::user_two_factor_options_update
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_options_update_set_key() {
		$user = new WP_User( $this->factory->user->create() );

		$request_key = '_nonce_user_two_factor_totp_options';
		$_POST[$request_key] = wp_create_nonce( 'user_two_factor_totp_options' );
		$_REQUEST[$request_key] = $_POST[$request_key];

		$key = $this->provider->generate_key();
		$_POST['two-factor-totp-key'] = $key;
		$_POST['two-factor-totp-authcode'] = $this->provider->calc_totp( $key );

		ob_start();
		$this->provider->user_two_factor_options_update( $user->ID );
		$content = ob_get_clean();

		unset( $_POST['two-factor-totp-authcode'] );
		unset( $_POST['two-factor-totp-key'] );

		unset( $_REQUEST[$request_key] );
		unset( $_POST[$request_key] );

		$this->assertTrue( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * @covers Two_Factor_Totp::base32_encode
	 */
	public function test_base32_encode() {
		$string = 'EV5XW7TOL4QHIKBIGVEU23KAFRND66LY';
		$string_base32 = 'IVLDKWCXG5KE6TBUKFEESS2CJFDVMRKVGIZUWQKGKJHEINRWJRMQ';

		$this->assertEquals( $string_base32, $this->provider->base32_encode( $string ) );
	}

	/**
	 * @covers Two_Factor_Totp::base32_encode
	 */
	public function test_base32_decode() {
		$string = 'EV5XW7TOL4QHIKBIGVEU23KAFRND66LY';
		$string_base32 = 'IVLDKWCXG5KE6TBUKFEESS2CJFDVMRKVGIZUWQKGKJHEINRWJRMQ';

		$this->assertEquals( $string, $this->provider->base32_decode( $string_base32 ) );
	}

	/**
	 * @covers Two_Factor_Totp::is_valid_authcode
	 * @covers Two_Factor_Totp::generate_key
	 * @covers Two_Factor_Totp::calc_totp
	 */
	public function test_is_valid_authcode() {
		$key = $this->provider->generate_key();
		$authcode = $this->provider->calc_totp( $key );

		$this->assertTrue( $this->provider->is_valid_authcode( $key, $authcode ) );
	}

	/**
	 * Check secret key CRUD operations.
	 *
	 * @covers Two_Factor_Totp::get_user_totp_key
	 * @covers Two_Factor_Totp::set_user_totp_key
	 * @covers Two_Factor_Totp::delete_user_totp_key
	 */
	public function test_user_totp_key() {
		$user = new WP_User( $this->factory->user->create() );

		$this->assertEquals(
			'',
			$this->provider->get_user_totp_key( $user->ID ),
			'User does not have TOTP secret configured by default'
		);

		$this->provider->set_user_totp_key( $user->ID, '1234' );

		$this->assertEquals(
			'1234',
			$this->provider->get_user_totp_key( $user->ID ),
			'User has a secret key'
		);

		$this->provider->delete_user_totp_key( $user->ID );

		$this->assertEquals(
			'',
			$this->provider->get_user_totp_key( $user->ID ),
			'User no longer has a secret key stored'
		);
	}

	/**
	 * @covers Two_Factor_Totp::is_valid_key
	 */
	public function test_is_valid_key() {
		$this->assertTrue( $this->provider->is_valid_key( 'ABC234' ), 'Base32 chars are valid' );
		$this->assertFalse( $this->provider->is_valid_key( '' ), 'Empty string is invalid' );
		$this->assertFalse( $this->provider->is_valid_key( 'abc233' ), 'Lowercase chars are invalid' );
		$this->assertFalse( $this->provider->is_valid_key( 'has a space' ), 'Spaces not allowed' );
	}

	/**
	 * @covers Two_Factor_Totp::user_two_factor_options_update
	 */
	public function test_user_can_delete_secret() {
		$user = new WP_User( $this->factory->user->create() );
		$key = $this->provider->generate_key();

		// Configure secret for the user.
		$this->provider->set_user_totp_key( $user->ID, $key );

		$this->assertEquals(
			$key,
			$this->provider->get_user_totp_key( $user->ID ),
			'Secret was stored and can be fetched'
		);

		// Configure the request and the nonce.
		$nonce = wp_create_nonce( 'user_two_factor_totp_options' );
		$_POST['_nonce_user_two_factor_totp_options'] = $nonce;
		$_REQUEST['_nonce_user_two_factor_totp_options'] = $nonce; // Required for check_admin_referer().

		// Set the request to delete things.
		$_POST['two-factor-totp-delete'] = 1;

		// Process the request.
		$this->provider->user_two_factor_options_update( $user->ID );

		$this->assertEquals(
			'',
			$this->provider->get_user_totp_key( $user->ID ),
			'Secret has been deleted'
		);
	}

}
