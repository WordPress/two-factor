<?php
/**
 * Two Factor privacy exporter and eraser tests.
 *
 * @package Two_Factor
 */

/**
 * Class Test_Two_Factor_Privacy
 *
 * @package Two_Factor
 * @group privacy
 */
class Test_Two_Factor_Privacy extends WP_UnitTestCase {

	/**
	 * A user with two-factor configured.
	 *
	 * @var WP_User
	 */
	private $user;

	/**
	 * Create a user with every provider's data populated.
	 */
	public function set_up() {
		parent::set_up();

		$this->user = self::factory()->user->create_and_get( array( 'user_email' => 'privacy-subject@example.com' ) );
	}

	/**
	 * Flatten an exporter response into a name => value map.
	 *
	 * @param array $response Exporter response.
	 * @return array
	 */
	private function flatten_export( $response ) {
		$flat = array();

		foreach ( $response['data'] as $group ) {
			foreach ( $group['data'] as $field ) {
				$flat[ $field['name'] ] = $field['value'];
			}
		}

		return $flat;
	}

	/**
	 * Run the registered exporter for the test user.
	 *
	 * @return array
	 */
	private function export() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		return call_user_func( $exporters['two-factor']['callback'], $this->user->user_email, 1 );
	}

	/**
	 * Run the registered eraser for the test user.
	 *
	 * @return array
	 */
	private function erase() {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		return call_user_func( $erasers['two-factor']['callback'], $this->user->user_email, 1 );
	}

	/**
	 * The plugin registers an exporter with WordPress.
	 */
	public function test_registers_a_personal_data_exporter() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( 'two-factor', $exporters );
		$this->assertTrue( is_callable( $exporters['two-factor']['callback'] ) );
	}

	/**
	 * The plugin registers an eraser with WordPress.
	 */
	public function test_registers_a_personal_data_eraser() {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'two-factor', $erasers );
		$this->assertTrue( is_callable( $erasers['two-factor']['callback'] ) );
	}

	/**
	 * An address with no matching user exports nothing.
	 */
	public function test_export_returns_no_data_for_an_unknown_email_address() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$response  = call_user_func( $exporters['two-factor']['callback'], 'nobody@example.com', 1 );

		$this->assertSame( array(), $response['data'] );
		$this->assertTrue( $response['done'] );
	}

	/**
	 * A user with no two-factor data exports nothing.
	 */
	public function test_export_returns_no_data_for_a_user_without_two_factor_data() {
		$response = $this->export();

		$this->assertSame( array(), $response['data'] );
		$this->assertTrue( $response['done'] );
	}

	/**
	 * Enabled providers and the primary provider are exported.
	 */
	public function test_export_includes_enabled_and_primary_providers() {
		Two_Factor_Core::enable_provider_for_user( $this->user->ID, 'Two_Factor_Email' );
		update_user_meta( $this->user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$flat = $this->flatten_export( $this->export() );

		$this->assertStringContainsString( 'Two_Factor_Email', $flat['Enabled two-factor methods'] );
		$this->assertStringContainsString( 'Two_Factor_Email', $flat['Primary two-factor method'] );
	}

	/**
	 * The TOTP secret is reported as configured, never disclosed.
	 */
	public function test_export_reports_totp_as_configured_without_disclosing_the_secret() {
		$totp   = Two_Factor_Totp::get_instance();
		$secret = Two_Factor_Totp::generate_key();
		$totp->set_user_totp_key( $this->user->ID, $secret );

		$response = $this->export();
		$flat     = $this->flatten_export( $response );

		$this->assertArrayHasKey( 'Authenticator app (TOTP)', $flat );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $response ) );
	}

	/**
	 * Backup codes are reported as a remaining count, never as codes or hashes.
	 */
	public function test_export_reports_backup_codes_as_a_count_only() {
		$backup_codes = Two_Factor_Backup_Codes::get_instance();
		$codes        = $backup_codes->generate_codes( $this->user );

		$response = $this->export();
		$flat     = $this->flatten_export( $response );

		$this->assertSame( (string) count( $codes ), (string) $flat['Backup codes remaining'] );

		foreach ( $codes as $code ) {
			$this->assertStringNotContainsString( $code, wp_json_encode( $response ) );
		}
	}

	/**
	 * Failed login records are personal data and are exported.
	 */
	public function test_export_includes_failed_login_records() {
		$failure_time = time() - 60;
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 3 );
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, $failure_time );

		$flat = $this->flatten_export( $this->export() );

		$this->assertSame( '3', (string) $flat['Failed two-factor attempts'] );
		$this->assertArrayHasKey( 'Last failed two-factor attempt', $flat );
	}

	/**
	 * A pending email token is reported by time, never by value.
	 */
	public function test_export_reports_a_pending_email_token_without_its_value() {
		$email = Two_Factor_Email::get_instance();
		$email->generate_token( $this->user->ID );

		$response = $this->export();
		$flat     = $this->flatten_export( $response );
		$stored   = get_user_meta( $this->user->ID, Two_Factor_Email::TOKEN_META_KEY, true );

		$this->assertArrayHasKey( 'Email login code', $flat );
		$this->assertStringNotContainsString( $stored, wp_json_encode( $response ) );
	}

	/**
	 * An address with no matching user erases nothing.
	 */
	public function test_erase_removes_nothing_for_an_unknown_email_address() {
		$erasers  = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$response = call_user_func( $erasers['two-factor']['callback'], 'nobody@example.com', 1 );

		$this->assertFalse( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertTrue( $response['done'] );
	}

	/**
	 * Incidental login records are erased.
	 */
	public function test_erase_removes_incidental_login_records() {
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_META_NONCE_KEY, array( 'key' => 'value' ) );
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 4 );
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true );

		$response = $this->erase();

		$this->assertTrue( $response['items_removed'] );
		$this->assertSame( '', get_user_meta( $this->user->ID, Two_Factor_Core::USER_META_NONCE_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true ) );
	}

	/**
	 * A pending email login token is erased.
	 */
	public function test_erase_removes_a_pending_email_login_token() {
		$email = Two_Factor_Email::get_instance();
		$email->generate_token( $this->user->ID );

		$this->erase();

		$this->assertFalse( $email->user_has_token( $this->user->ID ) );
	}

	/**
	 * Nothing is retained when the user has no second factor configured.
	 */
	public function test_erase_retains_nothing_when_no_provider_is_enabled() {
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 2 );

		$response = $this->erase();

		$this->assertTrue( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertSame( array(), $response['messages'] );
	}

	/**
	 * Erasure must not silently disable a live account's second factor.
	 */
	public function test_erase_retains_credentials_so_two_factor_stays_enabled() {
		$totp   = Two_Factor_Totp::get_instance();
		$secret = Two_Factor_Totp::generate_key();
		$totp->set_user_totp_key( $this->user->ID, $secret );
		Two_Factor_Core::enable_provider_for_user( $this->user->ID, 'Two_Factor_Totp' );

		$response = $this->erase();

		$this->assertTrue( $response['items_retained'] );
		$this->assertNotEmpty( $response['messages'] );
		$this->assertSame( $secret, $totp->get_user_totp_key( $this->user->ID ) );
		$this->assertContains( 'Two_Factor_Totp', Two_Factor_Core::get_enabled_providers_for_user( $this->user ) );
	}
}
