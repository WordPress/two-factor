<?php
/**
 * Test Two Factor.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor
 *
 * @package Two_Factor
 * @group core
 */
class Tests_Two_Factor extends WP_UnitTestCase {

	/**
	 * Check that the TWO_FACTOR_DIR constant is defined.
	 */
	public function test_constant_defined() {

		$this->assertTrue( defined( 'TWO_FACTOR_DIR' ) );

	}

	/**
	 * Check that the files were included.
	 */
	public function test_classes_exist() {

		$this->assertTrue( class_exists( 'Two_Factor_Provider' ) );
		$this->assertTrue( class_exists( 'Two_Factor_Core' ) );

	}
}
