<?php
/**
 * Test Two Factor Dummy.
 */

/**
 * Class Tests_Two_Factor_Backup_Codes
 *
 * @package Two_Factor
 * @group providers
 */
class Tests_Two_Factor_Backup_Codes extends WP_UnitTestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_Backup_Codes
	 */
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
		$this->assertContains( 'Backup Verification Codes', $this->provider->get_label() );
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
	 * Verify that codes are not available for the user.
	 * @covers Two_Factor_Backup_Codes::is_available_for_user
	 */
	function test_is_available_for_user_false() {
		$user = new WP_User( $this->factory->user->create() );

		$this->assertFalse( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * Verify that codes are available for the user.
	 * @covers Two_Factor_Backup_Codes::is_available_for_user
	 */
	function test_is_available_for_user() {
		$user = new WP_User( $this->factory->user->create() );
		$codes = $this->provider->generate_codes( $user );

		$this->assertTrue( $this->provider->is_available_for_user( $user ) );
	}

	/**
	 * Verify that codes generate and validate.
	 * @covers Two_Factor_Backup_Codes::generate_codes
	 * @covers Two_Factor_Backup_Codes::validate_code
	 * @covers Two_Factor_Backup_Codes::codes_remaining_for_user
	 */
	function test_generate_codes_and_validate_codes() {
		$user = new WP_User( $this->factory->user->create() );
		$codes = $this->provider->generate_codes( $user );
		foreach( $codes as $code ) {
			$this->assertTrue( $this->provider->validate_code( $user, $code ) );
		}
		$this->assertEquals( $this->provider->codes_remaining_for_user( $user ), 0 );
	}

	/**
	 * Verify that a validated code can't reused by a user.
	 * @covers Two_Factor_Backup_Codes::generate_codes
	 * @covers Two_Factor_Backup_Codes::validate_code
	 */
	function test_generate_code_and_validate_code_false_revalidate() {
		$user = new WP_User( $this->factory->user->create() );
		$codes = $this->provider->generate_codes( $user, array( 'number' => 1 ) );
		$validate = $this->provider->validate_code( $user, $codes[0] );

		$this->assertFalse( $this->provider->validate_code( $user, $codes[0] ) );
	}

	/**
	 * Show that validate_code fails for a different user's code.
	 * @covers Two_Factor_Backup_Codes::generate_codes
	 * @covers Two_Factor_Backup_Codes::validate_code
	 */
	function test_generate_code_and_validate_code_false_different_users() {
		$user = new WP_User( $this->factory->user->create() );
		$codes = $this->provider->generate_codes( $user, array( 'number' => 1 ) );

		$user2 = new WP_User( $this->factory->user->create() );
		$codes2 = $this->provider->generate_codes( $user2, array( 'number' => 1 ) );


		$this->assertFalse( $this->provider->validate_code( $user2, $codes[0] ) );
	}

	/**
	 * Verify some of the markup for the user_options method.
	 * @covers Two_Factor_Backup_Codes::user_options
	 */
	function test_user_options() {
		$user = new WP_User( $this->factory->user->create() );
		$nonce = wp_create_nonce( 'two-factor-backup-codes-generate-json-' . $user->ID );

		ob_start();
		$this->provider->user_options( $user );
		$buffer = ob_get_clean();

		$this->assertContains( '<p id="two-factor-backup-codes">', $buffer );
		$this->assertContains( '<div class="two-factor-backup-codes-wrapper" style="display:none;">', $buffer );
		$this->assertContains( "user_id: '{$user->ID}'", $buffer );
		$this->assertContains( "nonce: '{$nonce}'", $buffer );
	}

	/**
	 * Verify that a code is generated & deleted.
	 * @covers Two_Factor_Backup_Codes::generate_codes
	 * @covers Two_Factor_Backup_Codes::delete_code
	 * @covers Two_Factor_Backup_Codes::codes_remaining_for_user
	 */
	function test_delete_code() {
		$user = new WP_User( $this->factory->user->create() );

		$this->provider->generate_codes( $user, array( 'number' => 1 ) );
		$this->assertEquals( 1, $this->provider->codes_remaining_for_user( $user ) );

		$this->provider->generate_codes( $user, array( 'number' => 1, 'method' => 'append' ) );
		$this->assertEquals( 2, $this->provider->codes_remaining_for_user( $user ) );

		$backup_codes = get_user_meta( $user->ID, Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY, true );
		$this->provider->delete_code( $user, $backup_codes[0] );
		$this->assertEquals( 1, $this->provider->codes_remaining_for_user( $user ) );
	}

}
