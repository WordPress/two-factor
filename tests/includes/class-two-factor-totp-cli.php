<?php
/**
 * Test Two Factor TOTP CLI command logic.
 *
 * @package Two_Factor
 */

// Load the testable secret subclass and mock WP-CLI classes before the CLI command class.
require_once dirname( __DIR__ ) . '/providers/class-two-factor-totp-secret.php';
require_once __DIR__ . '/wp-cli-utils-mock.php';
require_once __DIR__ . '/class-two-factor-totp-cli-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-two-factor-totp-cli.php';

/**
 * Testable subclass that captures output instead of calling WP_CLI methods.
 */
class Two_Factor_Totp_Cli_Testable extends Two_Factor_Totp_Cli {

	/**
	 * Use the testable secret class that allows key injection.
	 *
	 * @var string
	 */
	protected $secret_class = 'Two_Factor_Totp_Secret_Testable';

	/**
	 * Collected log messages.
	 *
	 * @var array
	 */
	public $logs = array();

	/**
	 * Final result: 'success', 'error', or null.
	 *
	 * @var string|null
	 */
	public $result_type = null;

	/**
	 * Final result message.
	 *
	 * @var string
	 */
	public $result_message = '';

	/**
	 * Warning messages.
	 *
	 * @var array
	 */
	public $warnings = array();
}

/**
 * Class Tests_Two_Factor_Totp_Cli
 *
 * @package Two_Factor
 * @group providers
 * @group totp
 * @group cli
 */
class Tests_Two_Factor_Totp_Cli extends WP_UnitTestCase {

	/**
	 * @var string
	 */
	private $test_key_hex;

	/**
	 * @var Two_Factor_Totp_Cli_Testable
	 */
	private $cli;

	public function set_up() {
		parent::set_up();

		if ( ! sodium_crypto_aead_aes256gcm_is_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available on this system.' );
		}

		$this->test_key_hex = bin2hex( random_bytes( 32 ) );

		Two_Factor_Totp_Secret_Testable::$test_current_key  = false;
		Two_Factor_Totp_Secret_Testable::$test_previous_key = false;

		$this->cli             = new Two_Factor_Totp_Cli_Testable();
		WP_CLI::$test_instance = $this->cli;
	}

	public function tear_down() {
		Two_Factor_Totp_Secret_Testable::$test_current_key  = false;
		Two_Factor_Totp_Secret_Testable::$test_previous_key = false;
		WP_CLI::$test_instance = null;

		parent::tear_down();
	}

	/**
	 * @covers Two_Factor_Totp_Cli::encrypt_secrets
	 */
	public function test_encrypt_secrets_without_key_errors() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'WP_CLI::error' );

		$this->cli->encrypt_secrets( array(), array() );
	}

	/**
	 * @covers Two_Factor_Totp_Cli::encrypt_secrets
	 */
	public function test_encrypt_secrets_no_secrets_in_db() {
		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$this->cli->encrypt_secrets( array(), array() );

		$this->assertSame( 'success', $this->cli->result_type );
		$this->assertStringContainsString( 'No TOTP secrets found', $this->cli->result_message );
	}

	/**
	 * @covers Two_Factor_Totp_Cli::encrypt_secrets
	 */
	public function test_encrypt_secrets_encrypts_plaintext() {
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';
		update_user_meta( $user_id, '_two_factor_totp_key', $plaintext );

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$this->cli->encrypt_secrets( array(), array() );

		$this->assertSame( 'success', $this->cli->result_type );
		$this->assertStringContainsString( '1 secret(s) encrypted', $this->cli->result_message );

		$db_value = get_user_meta( $user_id, '_two_factor_totp_key', true );
		$this->assertTrue( Two_Factor_Totp_Secret_Testable::is_encrypted( $db_value ) );
	}

	/**
	 * @covers Two_Factor_Totp_Cli::encrypt_secrets
	 */
	public function test_encrypt_secrets_skips_already_encrypted() {
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$encrypted = Two_Factor_Totp_Secret_Testable::encrypt( $plaintext, $user_id );
		update_user_meta( $user_id, '_two_factor_totp_key', $encrypted );

		$this->cli->encrypt_secrets( array(), array() );

		$this->assertSame( 'success', $this->cli->result_type );
		$this->assertStringContainsString( '0 secret(s) encrypted', $this->cli->result_message );
		$this->assertStringContainsString( '1 already encrypted', $this->cli->result_message );
	}

	/**
	 * @covers Two_Factor_Totp_Cli::encrypt_secrets
	 */
	public function test_encrypt_secrets_dry_run() {
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';
		update_user_meta( $user_id, '_two_factor_totp_key', $plaintext );

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$this->cli->encrypt_secrets( array(), array( 'dry-run' => true ) );

		$this->assertSame( 'success', $this->cli->result_type );
		$this->assertStringContainsString( '1 secret(s) would be encrypted', $this->cli->result_message );

		// Verify DB was NOT changed.
		$db_value = get_user_meta( $user_id, '_two_factor_totp_key', true );
		$this->assertSame( $plaintext, $db_value );
	}

	/**
	 * @covers Two_Factor_Totp_Cli::encrypt_secrets
	 */
	public function test_encrypt_secrets_multiple_users() {
		$user1 = self::factory()->user->create();
		$user2 = self::factory()->user->create();
		$user3 = self::factory()->user->create();

		update_user_meta( $user1, '_two_factor_totp_key', 'JBSWY3DPEHPK3PXP' );
		update_user_meta( $user2, '_two_factor_totp_key', 'KRSXG5CTMVRXEZLU' );

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		// Pre-encrypt user3's key.
		$encrypted = Two_Factor_Totp_Secret_Testable::encrypt( 'MFZWIZLTOQ6Q', $user3 );
		update_user_meta( $user3, '_two_factor_totp_key', $encrypted );

		$this->cli->encrypt_secrets( array(), array() );

		$this->assertSame( 'success', $this->cli->result_type );
		$this->assertStringContainsString( '2 secret(s) encrypted', $this->cli->result_message );
		$this->assertStringContainsString( '1 already encrypted', $this->cli->result_message );

		// All three should now be encrypted.
		$this->assertTrue( Two_Factor_Totp_Secret_Testable::is_encrypted( get_user_meta( $user1, '_two_factor_totp_key', true ) ) );
		$this->assertTrue( Two_Factor_Totp_Secret_Testable::is_encrypted( get_user_meta( $user2, '_two_factor_totp_key', true ) ) );
		$this->assertTrue( Two_Factor_Totp_Secret_Testable::is_encrypted( get_user_meta( $user3, '_two_factor_totp_key', true ) ) );
	}

	/**
	 * @covers Two_Factor_Totp_Cli::encrypt_secrets
	 */
	public function test_encrypted_secrets_remain_decryptable() {
		$user_id   = self::factory()->user->create();
		$plaintext = 'JBSWY3DPEHPK3PXP';
		update_user_meta( $user_id, '_two_factor_totp_key', $plaintext );

		Two_Factor_Totp_Secret_Testable::$test_current_key = hex2bin( $this->test_key_hex );

		$this->cli->encrypt_secrets( array(), array() );

		$db_value = get_user_meta( $user_id, '_two_factor_totp_key', true );
		$result   = Two_Factor_Totp_Secret_Testable::decrypt( $db_value, $user_id );

		$this->assertNotFalse( $result );
		$this->assertSame( $plaintext, $result['plaintext'] );
	}
}
