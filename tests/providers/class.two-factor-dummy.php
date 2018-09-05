<?php
/**
 * Test Two Factor Dummy.
 */

/**
 * Class Tests_Two_Factor_Dummy
 *
 * @package Two_Factor
 * @group providers
 */
class Tests_Two_Factor_Dummy extends WP_UnitTestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_Dummy
	 */
	protected $provider;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase::setup()
	 */
	function setUp() {
		parent::setUp();

		$this->provider = Two_Factor_Dummy::get_instance();
	}

	/**
	 * Verify an instance exists.
	 * @covers Two_Factor_Dummy::get_instance
	 */
	function test_get_instance() {

		$this->assertNotNull( $this->provider->get_instance() );

	}

	/**
	 * Verify the label value.
	 * @covers Two_Factor_Dummy::get_label
	 */
	function test_get_label() {

		$this->assertContains( 'Dummy Method', $this->provider->get_label() );

	}

	/**
	 * Verify the contents of the authentication page.
	 * @covers Two_Factor_Dummy::authentication_page
	 */
	function test_authentication_page() {

		ob_start();
		$this->provider->authentication_page( false );
		$contents = ob_get_clean();

		$this->assertContains( 'Are you really you?', $contents );
		$this->assertContains( '<p class="submit">', $contents );
		$this->assertContains( 'Yup', $contents );

	}

	/**
	 * Verify that dummy validation returns true.
	 * @covers Two_Factor_Dummy::validate_authentication
	 */
	function test_validate_authentication() {

		$this->assertTrue( $this->provider->validate_authentication( false ) );

	}

	/**
	 * Verify that dummy availability returns true.
	 * @covers Two_Factor_Dummy::is_available_for_user
	 */
	function test_is_available_for_user() {

		$this->assertTrue( $this->provider->is_available_for_user( false ) );

	}

}
