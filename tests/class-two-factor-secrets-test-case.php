<?php
/**
 * Shared test case for tests touching the Secrets API adapter.
 *
 * @package Two_Factor
 */

/**
 * Base class for Secrets API related tests.
 */
abstract class Two_Factor_Secrets_UnitTestCase extends WP_UnitTestCase {

	/**
	 * Skip the test unless the Secrets API feature plugin is loaded.
	 *
	 * @return void
	 */
	protected function require_secrets_api() {
		if ( ! function_exists( 'wp_get_network_secret' ) ) {
			$this->markTestSkipped( 'Secrets API feature plugin is not loaded.' );
		}
	}

	/**
	 * Make the adapter behave as though the Secrets API were absent.
	 *
	 * @return void
	 */
	protected function simulate_api_absent() {
		add_filter( 'two_factor_secrets_api_present', '__return_false' );
	}

	/**
	 * Reset shared state.
	 *
	 * @return void
	 */
	public function tear_down() {
		Two_Factor_Secrets::$test_overrides = array();
		delete_site_transient( 'two_factor_totp_affected_users' );
		parent::tear_down();
	}
}
