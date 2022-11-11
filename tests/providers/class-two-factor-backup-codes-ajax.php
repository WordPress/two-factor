<?php
/**
 * Test Two Factor Backup Codes.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Backup_Codes_AJAX
 *
 * @package Two_Factor
 * @group providers
 */
class Tests_Two_Factor_Backup_Codes_AJAX extends WP_Ajax_UnitTestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_Backup_Codes
	 */
	protected $provider;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase_Base::set_up()
	 */
	public function set_up() {
		parent::set_up();
		$this->provider = Two_Factor_Backup_Codes::get_instance();
	}

	/**
	 * Verify that the downloaded file contains the codes.
	 *
	 * @covers Two_Factor_Backup_Codes::generate_codes
	 * @covers Two_Factor_Backup_Codes::validate_code
	 */
	public function test_generate_code_and_validate_in_download_file() {
		$user = new WP_User( self::factory()->user->create() );

		// Become that user.
		wp_set_current_user( $user->ID );

		$_POST['user_id'] = $user->ID;
		$_POST['nonce']   = wp_create_nonce( 'two-factor-backup-codes-generate-json-' . $user->ID );

		try {
			$this->_handleAjax( 'two_factor_backup_codes_generate' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$this->assertStringContainsString( 'download_link', $this->_last_response );

		$response = json_decode( $this->_last_response );

		$this->assertTrue( $response->success );
		$this->assertNotEmpty( $response->data->codes );
		$this->assertTrue( $this->provider->validate_code( $user, $response->data->codes[0] ) );
		$this->assertStringContainsString( $response->data->codes[0], $response->data->download_link );
	}
}
