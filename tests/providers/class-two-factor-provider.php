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
	 * Test get_code method.
	 *
	 * @covers Two_Factor_Provider::get_code
	 */
	public function test_get_code() {
		$code = Two_Factor_Provider::get_code( 3, '1' );
		$this->assertEquals( '111', $code );

		$code = Two_Factor_Provider::get_code( 8, '1' );
		$this->assertEquals( '11111111', $code );

		$code = Two_Factor_Provider::get_code( 8, 'A' );
		$this->assertEquals( 'AAAAAAAA', $code );

		$code = Two_Factor_Provider::get_code( 30, array( 'A', 'B', 'C' ) );
		$this->assertSame( 1, preg_match( '/^[ABC]{30}$/', $code ) );

		$code = Two_Factor_Provider::get_code( 30, 'DEF' );
		$this->assertSame( 1, preg_match( '/^[DEF]{30}$/', $code ) );

		$code = Two_Factor_Provider::get_code( 8 );
		$this->assertEquals( 8, strlen( $code ) );
	}

	/**
	 * Validate that sanitize_code_from_request() works as intended.
	 *
	 * @covers Two_Factor_Provider::sanitize_code_from_request
	 * @dataProvider provider_sanitize_code_from_request
	 * @param mixed  $expected Expected result.
	 * @param string $field Field name.
	 * @param mixed  $value Field value.
	 * @param int    $length Expected length.
	 */
	public function test_sanitize_code_from_request( $expected, $field, $value, $length = 0 ) {
		$_REQUEST[ $field ] = '';
		if ( $value ) {
			$_REQUEST[ $field ] = $value;
		}

		$this->assertEquals( $expected, Two_Factor_Provider::sanitize_code_from_request( $field, $length ) );

		unset( $_REQUEST[ $field ] );
	}

	/**
	 * Data provider for test_sanitize_code_from_request.
	 *
	 * @return array
	 */
	public function provider_sanitize_code_from_request() {
		return array(
			array( '123123', 'authcode', '123123', 6 ),
			array( false, 'authcode', '123123123', 6 ),
			array( '123123', 'code', '123 123' ),
			array( '123123', 'code', "\n123123\n" ),
			array( '123123', 'code', "123\t123", 6 ),
			array( false, 'code', '' ),
			array( 'helloworld', 'code', 'helloworld' ),
			array( false, false, false ),
		);
	}

	/**
	 * Validate that Two_Factor_Provider::get_instance() always returns the same instance.
	 *
	 * @covers Two_Factor_Provider::get_instance
	 */
	public function test_get_instance() {
		$instance_one = Two_Factor_Dummy::get_instance();
		$instance_two = Two_Factor_Dummy::get_instance();

		$this->assertSame( $instance_one, $instance_two );
	}
}
