<?php
/**
 * Test Two Factor.
 */

class Tests_Two_factor extends WP_UnitTestCase {

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase::setup()
	 */
	function setUp() {
		parent::setUp();
		do_action( 'plugins_loaded' );
	}

	/**
	 * Check that the plugin is active.
	 */
	function test_is_plugin_active() {

		$this->assertTrue( is_plugin_active( 'two-factor/two-factor.php' ) );

	}

	/**
	 * Check that the TWO_FACTOR_DIR constant is defined.
	 */
	function test_constant_defined() {
		
		$this->assertTrue( defined( 'TWO_FACTOR_DIR' ) );

	}

	/**
	 * Check that the files were included.
	 */
	function test_classes_exist() {

		$this->assertTrue( class_exists( 'Two_Factor_Provider' ) );
		$this->assertTrue( class_exists( 'Two_Factor_Core' ) );
		$this->assertTrue( class_exists( 'Application_Passwords' ) );

	}
}