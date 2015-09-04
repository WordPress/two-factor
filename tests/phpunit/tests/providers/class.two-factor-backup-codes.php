<?php
/**
 * Test Two Factor Dummy.
 */

class Tests_Two_Factor_Backup_Codes extends WP_UnitTestCase {

	protected $provider;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase::setup()
	 */
	function setUp() {
		parent::setUp();
		$this->provider = Two_Factor_Backup_Codes::get_instance();
	}

	/**
	 * Verify an instance exists.
	 * @covers Two_Factor_Backup_Codes::get_instance
	 */
	function test_get_instance() {
		$this->assertNotNull( $this->provider->get_instance() );
	}

	/**
	 * Verify the label value.
	 * @covers Two_Factor_Backup_Codes::get_label
	 */
	function test_get_label() {
		$this->assertContains( 'Backup Verification Codes (Single Use)', $this->provider->get_label() );
	}

	/**
	 * Verify the contents of the authentication page.
	 * @covers Two_Factor_Backup_Codes::authentication_page
	 */
	function test_authentication_page() {
		ob_start();
		$this->provider->authentication_page( false );
		$contents = ob_get_clean();

		$this->assertContains( 'Enter a backup verification code.', $contents );
	}

	/**
	 * Verify that validation returns true.
	 * @covers Two_Factor_Backup_Codes::validate_authentication
	 */
	function test_validate_authentication() {
		$user = new WP_User( $this->factory->user->create() );
		$code = $this->provider->generate_codes( $user, array( 'number' => 1 ) );
		$_POST['two-factor-backup-code'] = $code[0];
		$this->assertTrue( $this->provider->validate_authentication( $user ) );
		unset( $_POST['two-factor-backup-code'] );
	}

	/**
	 * Verify that codes are available for the user.
	 * @covers Two_Factor_Backup_Codes::is_available_for_user
	 */
	function test_is_available_for_user() {
		$user = new WP_User( $this->factory->user->create() );
		$code = $this->provider->generate_codes( $user );
		$this->assertTrue( $this->provider->is_available_for_user( $user ) );
	}

}