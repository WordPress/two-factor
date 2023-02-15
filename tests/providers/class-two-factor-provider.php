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
	 * Validate that sanitize_code_from_request() works as intended.
	 *
	 * @covers Two_Factor_Provider::sanitize_code_from_request
	 * @dataProvider provider_sanitize_code_from_request
	 */
	function test_sanitize_code_from_request( $expected, $field, $value, $length = 0) {
		$_REQUEST[ $field ] = '';
		if ( $value ) {
			$_REQUEST[ $field ] = $value;
		}

		$this->assertEquals( $expected, Two_Factor_Provider::sanitize_code_from_request( $field, $length ) );

		unset( $_REQUEST[ $field ] );
	}

	function provider_sanitize_code_from_request() {
		return [
			[ '123123', 'authcode', '123123', 6 ],
			[ false, 'authcode', '123123123', 6 ],
			[ '123123', 'code', '123 123' ],
			[ '123123', 'code', "\n123123\n" ],
			[ '123123', 'code', "123\t123", 6 ],
			[ false, 'code', '' ],
			[ 'helloworld', 'code', 'helloworld' ],
			[ false, false, false ],
		];
	}

}
