<?php
/**
 * Test Two Factor Dummy.
 */

class Tests_Two_Factor_Dummy extends WP_UnitTestCase {

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
	 * Verify the label value.
	 */
	function test_get_label() {

		$this->assertContains( 'Dummy Method', $this->provider->get_label() );

	}

	/**
	 * Verify the contents of the authentication page.
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
	 */
	function test_validate_authentication() {

		$this->assertTrue( $this->provider->validate_authentication( false ) );

	}
}