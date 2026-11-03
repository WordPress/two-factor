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

	/**
	 * Check that the Secrets API feature plugin is loaded with a test key.
	 */
	public function test_secrets_api_feature_plugin_loaded_for_tests() {
		if ( ! function_exists( 'wp_get_network_secret' ) ) {
			$this->markTestSkipped( 'Secrets API feature plugin is not loaded.' );
		}

		$this->assertTrue( function_exists( 'wp_get_network_secret' ) );
		$this->assertTrue( class_exists( 'WP_Secret' ) );
		$this->assertTrue( defined( 'WP_SECRETS_KEY' ) );
		$this->assertSame( 44, strlen( WP_SECRETS_KEY ) );
	}

	/**
	 * Check that a network secret can be stored, read and deleted.
	 */
	public function test_secrets_api_network_round_trip() {
		if ( ! function_exists( 'wp_get_network_secret' ) ) {
			$this->markTestSkipped( 'Secrets API feature plugin is not loaded.' );
		}

		$this->assertTrue( wp_set_network_secret( 'two-factor-test/probe', 'v' ) );
		$this->assertSame( 'v', wp_get_network_secret( 'two-factor-test/probe' )->reveal() );
		wp_delete_network_secret( 'two-factor-test/probe' );
		$this->assertNull( wp_get_network_secret( 'two-factor-test/probe' ) );
	}
}
