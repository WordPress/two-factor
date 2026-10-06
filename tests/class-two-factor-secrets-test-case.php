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
	 * The in-memory store the plugin writes secrets to during a test.
	 *
	 * @var Two_Factor_Secrets_Memory_Store
	 */
	protected $secrets_store;

	/**
	 * Store secrets in memory and opt in, as an administrator would on the settings screen.
	 *
	 * Storage is off until an administrator turns it on. Most tests here exercise the
	 * storage itself, so they start opted in; tests of the default call opt_out().
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->secrets_store = new Two_Factor_Secrets_Memory_Store();
		Two_Factor_Secrets::set_instance( new Two_Factor_Secrets_Manager( $this->secrets_store ) );
		Two_Factor_Secrets::set_opted_in( true );
	}

	/**
	 * Return to the default state, in which no administrator has opted in.
	 *
	 * @return void
	 */
	protected function opt_out() {
		Two_Factor_Secrets::set_opted_in( false );
	}

	/**
	 * Skip the test unless the Secrets API feature plugin is loaded. Only tests of the real store need it.
	 *
	 * @return void
	 */
	protected function require_secrets_api() {
		if ( ! function_exists( 'wp_get_network_secret' ) ) {
			$this->markTestSkipped( 'Secrets API feature plugin is not loaded.' );
		}
	}

	/**
	 * Make the store report itself as unavailable, as when the Secrets API is absent.
	 *
	 * @return void
	 */
	protected function simulate_api_absent() {
		$this->secrets_store->available = false;
	}

	/**
	 * Reset shared state.
	 *
	 * @return void
	 */
	public function tear_down() {
		Two_Factor_Secrets::reset();
		delete_site_transient( 'two_factor_totp_affected_users' );
		parent::tear_down();
	}
}
