<?php
/**
 * Test Two Factor TOTP.
 *
 * @package Two_Factor
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
	 * @see WP_UnitTestCase_Base::set_up()
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = Two_Factor_Totp::get_instance();
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
	 * Verify an instance exists.
	 *
	 * @covers Two_Factor_Totp::get_instance
	 */
	public function test_get_instance() {
		$this->assertNotNull( $this->provider->get_instance() );
	}

	/**
	 * Verify the label value.
	 *
	 * @covers Two_Factor_Totp::get_label
	 */
	public function test_get_label() {
		$this->assertStringContainsString( 'Time Based One-Time Password (TOTP)', $this->provider->get_label() );
	}

	/**
	 * Verify the options list is empty.
	 *
	 * @covers Two_Factor_Totp::user_two_factor_options
	 */
	public function test_user_two_factor_options_empty() {
		$this->assertFalse( $this->provider->user_two_factor_options( get_current_user() ) );
	}

	/**
	 * Verify getting user options creates a key.
	 *
	 * @covers Two_Factor_Totp::user_two_factor_options
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_options_generates_key() {
		$user = new WP_User( self::factory()->user->create() );

		ob_start();
		$this->provider->user_two_factor_options( $user );
		$content = ob_get_clean();

		$this->assertStringContainsString( __( 'Authentication Code:', 'two-factor' ), $content );
	}

	/**
	 * Verify updating user options without an authcode.
	 *
	 * @covers Two_Factor_Totp::user_two_factor_options_update
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_options_update_set_key_no_authcode() {
		$user = new WP_User( self::factory()->user->create() );

		$request_key              = '_nonce_user_two_factor_totp_options';
		$nonce                    = wp_create_nonce( 'user_two_factor_totp_options' );
		$_POST[ $request_key ]    = $nonce;
		$_REQUEST[ $request_key ] = $nonce;

		$_POST['two-factor-totp-key'] = $this->provider->generate_key();

		ob_start();
		$this->provider->user_two_factor_options_update( $user->ID );
		$content = ob_get_clean();

		unset( $_POST['two-factor-totp-key'] );

		unset( $_REQUEST[ $request_key ] );
		unset( $_POST[ $request_key ] );

		$this->assertFalse( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * Verify updating user options with a bad authcode.
	 *
	 * @covers Two_Factor_Totp::user_two_factor_options_update
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_options_update_set_key_bad_auth_code() {
		$user = new WP_User( self::factory()->user->create() );

		$request_key              = '_nonce_user_two_factor_totp_options';
		$nonce                    = wp_create_nonce( 'user_two_factor_totp_options' );
		$_POST[ $request_key ]    = $nonce;
		$_REQUEST[ $request_key ] = $nonce;

		$_POST['two-factor-totp-key']      = $this->provider->generate_key();
		$_POST['two-factor-totp-authcode'] = 'bad_test_authcode';

		ob_start();
		$this->provider->user_two_factor_options_update( $user->ID );
		$content = ob_get_clean();

		unset( $_POST['two-factor-totp-authcode'] );
		unset( $_POST['two-factor-totp-key'] );

		unset( $_REQUEST[ $request_key ] );
		unset( $_POST[ $request_key ] );

		$this->assertFalse( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * Verify updating user options with an authcode.
	 *
	 * @covers Two_Factor_Totp::user_two_factor_options_update
	 * @covers Two_Factor_Totp::is_available_for_user
	 */
	public function test_user_two_factor_options_update_set_key() {
		$user = new WP_User( self::factory()->user->create() );

		$request_key              = '_nonce_user_two_factor_totp_options';
		$nonce                    = wp_create_nonce( 'user_two_factor_totp_options' );
		$_POST[ $request_key ]    = $nonce;
		$_REQUEST[ $request_key ] = $nonce;

		$key                               = $this->provider->generate_key();
		$_POST['two-factor-totp-key']      = $key;
		$_POST['two-factor-totp-authcode'] = $this->provider->calc_totp( $key );

		ob_start();
		$this->provider->user_two_factor_options_update( $user->ID );
		$content = ob_get_clean();

		unset( $_POST['two-factor-totp-authcode'] );
		unset( $_POST['two-factor-totp-key'] );

		unset( $_REQUEST[ $request_key ] );
		unset( $_POST[ $request_key ] );

		$this->assertTrue( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * Verify base32 encoding.
	 *
	 * @covers Two_Factor_Totp::base32_encode
	 */
	public function test_base32_encode() {
		$string        = 'EV5XW7TOL4QHIKBIGVEU23KAFRND66LY';
		$string_base32 = 'IVLDKWCXG5KE6TBUKFEESS2CJFDVMRKVGIZUWQKGKJHEINRWJRMQ';

		$this->assertEquals( $string_base32, $this->provider->base32_encode( $string ) );
		$this->assertEquals( '', $this->provider->base32_encode( '' ) );
	}

	/**
	 * Verify base32 decoding.
	 *
	 * @covers Two_Factor_Totp::base32_decode
	 */
	public function test_base32_decode() {
		$string        = 'EV5XW7TOL4QHIKBIGVEU23KAFRND66LY';
		$string_base32 = 'IVLDKWCXG5KE6TBUKFEESS2CJFDVMRKVGIZUWQKGKJHEINRWJRMQ';

		$this->assertEquals( $string, $this->provider->base32_decode( $string_base32 ) );

	}

	/**
	 * Test base32 decoding an invalid string.
	 *
	 * @covers Two_Factor_Totp::base32_decode
	 */
	public function test_base32_decode_exception() {
		$string_base32 = 'IVLDKWCXG5KE6TBUKFEESS2CJFDVMRKVGIZUWQKGKJHEINRWJRMQ';

		$this->expectExceptionMessage( 'Invalid characters in the base32 string.' );
		$this->provider->base32_decode( $string_base32 . '@' );
	}

	/**
	 * Verify authcode validation.
	 *
	 * @covers Two_Factor_Totp::is_valid_authcode
	 * @covers Two_Factor_Totp::generate_key
	 * @covers Two_Factor_Totp::calc_totp
	 * @covers Two_Factor_Totp::pack64
	 * @covers Two_Factor_Totp::base32_decode
	 * @covers Two_Factor_Totp::abssort
	 */
	public function test_is_valid_authcode() {
		$key      = $this->provider->generate_key();
		$authcode = $this->provider->calc_totp( $key );

		$this->assertTrue( $this->provider->is_valid_authcode( $key, $authcode ) );
	}

	/**
	 * Verify authcode rejection.
	 *
	 * @covers Two_Factor_Totp::is_valid_authcode
	 */
	public function test_invalid_authcode_rejected() {
		$key = $this->provider->generate_key();

		$this->assertFalse( $this->provider->is_valid_authcode( $key, '012345' ) );
	}

	/**
	 * Check secret key CRUD operations.
	 *
	 * @covers Two_Factor_Totp::get_user_totp_key
	 * @covers Two_Factor_Totp::set_user_totp_key
	 * @covers Two_Factor_Totp::delete_user_totp_key
	 */
	public function test_user_totp_key() {
		$user = new WP_User( self::factory()->user->create() );

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
	 * Verify key validation.
	 *
	 * @covers Two_Factor_Totp::is_valid_key
	 */
	public function test_is_valid_key() {
		$this->assertTrue( $this->provider->is_valid_key( 'ABC234' ), 'Base32 chars are valid' );
		$this->assertFalse( $this->provider->is_valid_key( '' ), 'Empty string is invalid' );
		$this->assertFalse( $this->provider->is_valid_key( 'abc233' ), 'Lowercase chars are invalid' );
		$this->assertFalse( $this->provider->is_valid_key( 'has a space' ), 'Spaces not allowed' );
	}

	/**
	 * Verify secret deletion.
	 *
	 * @covers Two_Factor_Totp::user_two_factor_options_update
	 */
	public function test_user_can_delete_secret() {
		$user = new WP_User( self::factory()->user->create() );
		$key  = $this->provider->generate_key();

		// Configure secret for the user.
		$this->provider->set_user_totp_key( $user->ID, $key );

		$this->assertEquals(
			$key,
			$this->provider->get_user_totp_key( $user->ID ),
			'Secret was stored and can be fetched'
		);

		$this->provider->delete_user_totp_key( $user->ID );

		$this->assertEquals(
			'',
			$this->provider->get_user_totp_key( $user->ID ),
			'Secret has been deleted'
		);
	}

}
