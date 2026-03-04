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

	/**
	 * Test that get_code_length() returns the default value when no filter is applied.
	 *
	 * @covers Two_Factor_Provider::get_code_length
	 */
	public function test_get_code_length_returns_default() {
		$this->assertSame( 8, Two_Factor_Provider::get_code_length( 8 ) );
		$this->assertSame( 6, Two_Factor_Provider::get_code_length( 6 ) );
	}

	/**
	 * Test that the two_factor_code_length filter can override the default code length.
	 *
	 * @covers Two_Factor_Provider::get_code_length
	 */
	public function test_get_code_length_filter_overrides_default() {
		$set_length_to_4 = function() {
			return 4;
		};
		add_filter( 'two_factor_code_length', $set_length_to_4 );
		$this->assertSame( 4, Two_Factor_Provider::get_code_length( 8 ) );
		remove_filter( 'two_factor_code_length', $set_length_to_4 );

		$set_length_to_12 = function() {
			return 12;
		};
		add_filter( 'two_factor_code_length', $set_length_to_12 );
		$this->assertSame( 12, Two_Factor_Provider::get_code_length( 8 ) );
		remove_filter( 'two_factor_code_length', $set_length_to_12 );
	}

	/**
	 * Test that get_code( null ) uses the filtered code length from two_factor_code_length.
	 *
	 * @covers Two_Factor_Provider::get_code
	 * @covers Two_Factor_Provider::get_code_length
	 */
	public function test_get_code_with_null_uses_filtered_length() {
		$set_length_to_5 = function() {
			return 5;
		};
		add_filter( 'two_factor_code_length', $set_length_to_5 );
		$code = Two_Factor_Provider::get_code( null );
		remove_filter( 'two_factor_code_length', $set_length_to_5 );

		$this->assertSame( 5, strlen( $code ) );
	}
}
