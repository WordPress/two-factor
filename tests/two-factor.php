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

	}
}