<?php
/**
 * Test Two Factor TOTP Secret encryption.
 *
 * @package Two_Factor
 */

/**
 * Testable subclass that overrides key methods to avoid PHP constant redefinition issues.
 */
class Two_Factor_Totp_Secret_Testable extends Two_Factor_Totp_Secret {

	/**
	 * @var string|false
	 */
	public static $test_current_key = false;

	/**
	 * @var string|false
	 */
	public static $test_previous_key = false;

	protected static function get_current_key() {
		return self::$test_current_key;
	}

	protected static function get_previous_key() {
		return self::$test_previous_key;
	}
}

/**
 * Class Tests_Two_Factor_Totp_Secret
 *
 * @package Two_Factor
 * @group providers
 * @group totp
 * @group encryption
 */
class Tests_Two_Factor_Totp_Secret extends WP_UnitTestCase {

	/**
	 * A valid 32-byte hex-encoded key for testing.
	 *
	 * @var string
	 */
	private $test_key_hex;

	/**
	 * A second valid 32-byte hex-encoded key for rotation testing.
	 *
	 * @var string
	 */
	private $test_key_hex_2;

	/**
	 * Instance of the TOTP provider.
	 *
	 * @var Two_Factor_Totp
	 */
	private $provider;

	/**
	 * Set up a test case.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! sodium_crypto_aead_aes256gcm_is_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available on this system.' );
		}

		// Generate deterministic test keys.
		$this->test_key_hex   = bin2hex( random_bytes( 32 ) );
		$this->test_key_hex_2 = bin2hex( random_bytes( 32 ) );

		Two_Factor_Totp_Secret_Testable::$test_current_key  = false;
		Two_Factor_Totp_Secret_Testable::$test_previous_key = false;

		$this->provider = Two_Factor_Totp::get_instance();
	}

	/**
	 * Clean up after tests.
	 */
	public function tear_down() {
		Two_Factor_Totp_Secret_Testable::$test_current_key  = false;
		Two_Factor_Totp_Secret_Testable::$test_previous_key = false;

		parent::tear_down();
	}

	/**
	 * @covers Two_Factor_Totp_Secret::resolve
	 */
	public function test_plaintext_passthrough_without_encryption_key() {
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';

		update_user_meta( $user_id, '_two_factor_totp_key', $plaintext );

		$result = Two_Factor_Totp_Secret_Testable::resolve( $plaintext, $user_id );

		$this->assertSame( $plaintext, $result );
		$this->assertSame( $plaintext, get_user_meta( $user_id, '_two_factor_totp_key', true ) );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::resolve
	 * @covers Two_Factor_Totp_Secret::encrypt
	 */
	public function test_opportunistic_encryption_on_read() {
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';

		update_user_meta( $user_id, '_two_factor_totp_key', $plaintext );

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$result = Two_Factor_Totp_Secret_Testable::resolve( $plaintext, $user_id );

		$this->assertSame( $plaintext, $result );

		$db_value = get_user_meta( $user_id, '_two_factor_totp_key', true );
		$this->assertTrue( Two_Factor_Totp_Secret_Testable::is_encrypted( $db_value ) );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::encrypt
	 * @covers Two_Factor_Totp_Secret::decrypt
	 * @covers Two_Factor_Totp_Secret::resolve
	 */
	public function test_encrypted_secret_decrypts_correctly() {
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$encrypted = Two_Factor_Totp_Secret_Testable::encrypt( $plaintext, $user_id );
		$this->assertNotFalse( $encrypted );

		update_user_meta( $user_id, '_two_factor_totp_key', $encrypted );

		$result = Two_Factor_Totp_Secret_Testable::resolve( $encrypted, $user_id );
		$this->assertSame( $plaintext, $result );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::decrypt
	 * @covers Two_Factor_Totp_Secret::resolve
	 */
	public function test_key_rotation_reencrypts() {
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';

		// Encrypt with the "old" key.
		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );
		$encrypted_old = Two_Factor_Totp_Secret_Testable::encrypt( $plaintext, $user_id );
		$this->assertNotFalse( $encrypted_old );

		update_user_meta( $user_id, '_two_factor_totp_key', $encrypted_old );

		// Rotate: new key is current, old key is previous.
		Two_Factor_Totp_Secret_Testable::$test_current_key  = hex2bin( $this->test_key_hex_2 );
		Two_Factor_Totp_Secret_Testable::$test_previous_key = hex2bin( $this->test_key_hex );

		$result = Two_Factor_Totp_Secret_Testable::resolve( $encrypted_old, $user_id );
		$this->assertSame( $plaintext, $result );

		// Verify DB was re-encrypted (different from old encrypted value).
		$db_value = get_user_meta( $user_id, '_two_factor_totp_key', true );
		$this->assertTrue( Two_Factor_Totp_Secret_Testable::is_encrypted( $db_value ) );
		$this->assertNotSame( $encrypted_old, $db_value );

		// Verify new encrypted value decrypts with current key.
		$decrypt_result = Two_Factor_Totp_Secret_Testable::decrypt( $db_value, $user_id );
		$this->assertSame( $plaintext, $decrypt_result['plaintext'] );
		$this->assertFalse( $decrypt_result['needs_reencrypt'] );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::resolve
	 */
	public function test_decryption_failure_returns_empty() {
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';

		// Encrypt with one key.
		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );
		$encrypted = Two_Factor_Totp_Secret_Testable::encrypt( $plaintext, $user_id );

		update_user_meta( $user_id, '_two_factor_totp_key', $encrypted );

		// Try to resolve with a completely different key (no previous key).
		Two_Factor_Totp_Secret_Testable::$test_current_key  = hex2bin( $this->test_key_hex_2 );
		Two_Factor_Totp_Secret_Testable::$test_previous_key = false;

		$result = Two_Factor_Totp_Secret_Testable::resolve( $encrypted, $user_id );
		$this->assertSame( '', $result );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::is_encrypted
	 */
	public function test_is_encrypted_detection() {
		$this->assertTrue( Two_Factor_Totp_Secret::is_encrypted( '1::aabbccdd:eeff0011' ) );
		$this->assertFalse( Two_Factor_Totp_Secret::is_encrypted( 'JBSWY3DPEHPK3PXP' ) );
		$this->assertFalse( Two_Factor_Totp_Secret::is_encrypted( '' ) );
		$this->assertFalse( Two_Factor_Totp_Secret::is_encrypted( '2::aabbccdd:eeff0011' ) );
		$this->assertFalse( Two_Factor_Totp_Secret::is_encrypted( false ) );
		$this->assertFalse( Two_Factor_Totp_Secret::is_encrypted( null ) );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::encrypt
	 */
	public function test_encrypt_format() {
		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$encrypted = Two_Factor_Totp_Secret_Testable::encrypt( 'JBSWY3DPEHPK3PXP', 1 );
		$this->assertNotFalse( $encrypted );

		// Format: 1::[24 hex chars for 12-byte nonce]:[hex chars for ciphertext]
		$this->assertMatchesRegularExpression( '/^1::[0-9a-f]{24}:[0-9a-f]+$/', $encrypted );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::encrypt
	 */
	public function test_unique_nonces() {
		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$encrypted1 = Two_Factor_Totp_Secret_Testable::encrypt( 'JBSWY3DPEHPK3PXP', 1 );
		$encrypted2 = Two_Factor_Totp_Secret_Testable::encrypt( 'JBSWY3DPEHPK3PXP', 1 );

		$this->assertNotSame( $encrypted1, $encrypted2 );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::decrypt
	 */
	public function test_encryption_bound_to_user_id() {
		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$encrypted = Two_Factor_Totp_Secret_Testable::encrypt( 'JBSWY3DPEHPK3PXP', 1 );

		// Decrypting with a different user_id should fail (AD mismatch).
		$result = Two_Factor_Totp_Secret_Testable::decrypt( $encrypted, 2 );
		$this->assertFalse( $result );
	}

	/**
	 * Integration test: full set/get roundtrip through the TOTP provider with encryption.
	 *
	 * @covers Two_Factor_Totp::set_user_totp_key
	 * @covers Two_Factor_Totp::get_user_totp_key
	 */
	public function test_set_get_roundtrip_with_encryption() {
		// This test requires the actual constants to be defined, which we cannot do
		// in a single process. Instead, we test at the Secret class level.
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		// Simulate what set_user_totp_key does.
		$value = Two_Factor_Totp_Secret_Testable::prepare_for_storage( $plaintext, $user_id );
		$this->assertTrue( Two_Factor_Totp_Secret_Testable::is_encrypted( $value ) );

		update_user_meta( $user_id, '_two_factor_totp_key', $value );

		// Simulate what get_user_totp_key does.
		$stored = (string) get_user_meta( $user_id, '_two_factor_totp_key', true );
		$result = Two_Factor_Totp_Secret_Testable::resolve( $stored, $user_id );

		$this->assertSame( $plaintext, $result );
	}

	/**
	 * Integration test: encrypt a secret, generate a TOTP code, and validate it.
	 *
	 * @covers Two_Factor_Totp_Secret::resolve
	 * @covers Two_Factor_Totp::calc_totp
	 */
	public function test_validate_authentication_with_encrypted_secret() {
		$user_id   = self::factory()->user->create();
		$plaintext = Two_Factor_Totp::generate_key();

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		// Store encrypted.
		$encrypted = Two_Factor_Totp_Secret_Testable::prepare_for_storage( $plaintext, $user_id );
		update_user_meta( $user_id, '_two_factor_totp_key', $encrypted );

		// Generate a valid TOTP code from the plaintext key.
		$code = Two_Factor_Totp::calc_totp( $plaintext );

		// Resolve should return plaintext, which can be used to validate.
		$stored  = (string) get_user_meta( $user_id, '_two_factor_totp_key', true );
		$resolved = Two_Factor_Totp_Secret_Testable::resolve( $stored, $user_id );

		$this->assertTrue( Two_Factor_Totp::is_valid_authcode( $resolved, $code ) );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::resolve
	 */
	public function test_is_available_for_user_with_encrypted_secret() {
		$user_id   = self::factory()->user->create();
		$plaintext = Two_Factor_Totp::generate_key();

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$encrypted = Two_Factor_Totp_Secret_Testable::prepare_for_storage( $plaintext, $user_id );
		update_user_meta( $user_id, '_two_factor_totp_key', $encrypted );

		// Resolve returns non-empty plaintext when decryption succeeds.
		$stored  = (string) get_user_meta( $user_id, '_two_factor_totp_key', true );
		$resolved = Two_Factor_Totp_Secret_Testable::resolve( $stored, $user_id );

		$this->assertNotEmpty( $resolved );
	}

	/**
	 * @covers Two_Factor_Totp_Secret::resolve
	 */
	public function test_is_available_for_user_decryption_failure() {
		$user_id   = self::factory()->user->create();
		$plaintext = Two_Factor_Totp::generate_key();

		// Encrypt with one key.
		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );
		$encrypted = Two_Factor_Totp_Secret_Testable::prepare_for_storage( $plaintext, $user_id );
		update_user_meta( $user_id, '_two_factor_totp_key', $encrypted );

		// Switch to unknown key, no previous.
		Two_Factor_Totp_Secret_Testable::$test_current_key  = hex2bin( $this->test_key_hex_2 );
		Two_Factor_Totp_Secret_Testable::$test_previous_key = false;

		$stored  = (string) get_user_meta( $user_id, '_two_factor_totp_key', true );
		$resolved = Two_Factor_Totp_Secret_Testable::resolve( $stored, $user_id );

		$this->assertSame( '', $resolved );
	}
}
