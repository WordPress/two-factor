<?php
/**
 * Test Two Factor Provider
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Provider
 *
 * @package Two_Factor
 * @group providers
 */
class Tests_Two_Factor_Provider extends WP_UnitTestCase {
	/**
	 * @covers Two_Factor_Provider::get_code
	 */
	function test_get_code() {
		$code = Two_Factor_Provider::get_code( 3, '1' );
		$this->assertEquals( '111', $code );

		$code = Two_Factor_Provider::get_code( 8, '1' );
		$this->assertEquals( '11111111', $code );

		$code = Two_Factor_Provider::get_code( 8, 'A' );
		$this->assertEquals( 'AAAAAAAA', $code );

		$code = Two_Factor_Provider::get_code( 8 );
		$this->assertEquals( 8, strlen( $code ) );
	}
}
