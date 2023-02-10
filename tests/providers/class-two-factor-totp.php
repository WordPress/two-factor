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
 * @group totp
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
	 * Verify QR code URL generation.
	 *
	 * @covers Two_Factor_Totp::generate_qr_code_url
	 */
	public function test_generate_qr_code_url() {
		$user     = new WP_User( self::factory()->user->create() );
		$expected = 'otpauth://totp/Test%20Blog%3A'. rawurlencode( $user->user_login ) .'?secret=my%20secret%20key&#038;issuer=Test%20Blog';
		$actual   = $this->provider->generate_qr_code_url( $user, 'my secret key' );

		$this->assertSame( $expected, $actual );
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
}
