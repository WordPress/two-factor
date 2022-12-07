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
	 * @covers Two_Factor_Backup_Codes::ajax_generate_json
	 */
	public function test_generate_code_and_validate_in_download_file() {
		$this->_setRole( 'administrator' );

		$user             = wp_get_current_user();
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

	/**
	 * Verify that a different user cannot generate codes for another.
	 *
	 * @covers Two_Factor_Backup_Codes::ajax_generate_json
	 */
	public function test_cannot_generate_code_for_different_user() {
		$this->_setRole( 'administrator' );

		$user           = wp_get_current_user();
		$_POST['nonce'] = wp_create_nonce( 'two-factor-backup-codes-generate-json-' . $user->ID );

		// Create a new user
		$user             = new WP_User( self::factory()->user->create() );
		$_POST['user_id'] = $user->ID;

		$this->expectException( 'WPAjaxDieStopException' );
		$this->expectExceptionMessage( '-1' );
		$this->_handleAjax( 'two_factor_backup_codes_generate' );
	}

	/**
	 * Verify that an admin can create Backup codes for another user.
	 *
	 * @covers Two_Factor_Backup_Codes::ajax_generate_json
	 */
	public function test_generate_codes_for_other_users() {
		$this->_setRole( 'administrator' );

		$current_user     = wp_get_current_user();
		$user             =  new WP_User( self::factory()->user->create() );
		$_POST['user_id'] = $user->ID;
		$_POST['nonce']   = wp_create_nonce( 'two-factor-backup-codes-generate-json-' . $user->ID );

		try {
			$this->_handleAjax( 'two_factor_backup_codes_generate' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$this->assertStringContainsString( 'codes', $this->_last_response );

		$response = json_decode( $this->_last_response );

		$this->assertTrue( $response->success );
		$this->assertNotEmpty( $response->data->codes );

		$this->assertFalse( $this->provider->validate_code( $current_user, $response->data->codes[0] ) );
		$this->assertTrue( $this->provider->validate_code( $user, $response->data->codes[0] ) );
	}
}
