<?php
/**
 * Test Two Factor Dummy.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_Dummy
 *
 * @package Two_Factor
 * @group providers
 * @group dummy
 */
class Tests_Two_Factor_Dummy_Secure extends WP_UnitTestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_Dummy_Secure
	 */
	protected $provider;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase_Base::set_up()
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = Two_Factor_Dummy_Secure::get_instance();
	}

	public function test_get_key() {
		$this->assertEquals( 'Two_Factor_Dummy', $this->provider->get_key() );
	}

	/**
	 * Verify the contents of the authentication page.
	 *
	 * @covers Two_Factor_Dummy::authentication_page
	 */
	public function test_authentication_page() {

		ob_start();
		$this->provider->authentication_page( false );
		$contents = ob_get_clean();

		$this->assertStringContainsString( 'Are you really you?', $contents );
		$this->assertStringContainsString( '<p class="submit">', $contents );
		$this->assertStringContainsString( 'Yup', $contents );

	}

	/**
	 * Verify that dummy validation returns false.
	 *
	 * @covers Two_Factor_Dummy::validate_authentication
	 */
	public function test_validate_authentication() {

		$this->assertFalse( $this->provider->validate_authentication( false ) );

	}

	/**
	 * Replace the Dummy provider with our custom provider.
	 */
	public function test_provider_classname_filter() {
		$providers = Two_Factor_Core::get_providers();

		// Add filter, fetch, and then validate.
		add_filter( 'two_factor_provider_classname_Two_Factor_Dummy', array( $this, 'filter_change_provider' ) );
		$filtered = Two_Factor_Core::get_providers();
		remove_filter( 'two_factor_provider_classname_Two_Factor_Dummy', array( $this, 'filter_change_provider' ) );

		$this->assertEquals( 'Two_Factor_Dummy',        get_class( $providers['Two_Factor_Dummy'] ) );
		$this->assertNotEquals( 'Two_Factor_Dummy',     get_class( $filtered['Two_Factor_Dummy'] ) );
		$this->assertEquals( 'Two_Factor_Dummy_Secure', get_class( $filtered['Two_Factor_Dummy'] ) );

		$this->assertEquals( 'Two_Factor_Dummy', $providers['Two_Factor_Dummy']->get_key() );
		$this->assertEquals( 'Two_Factor_Dummy', $filtered['Two_Factor_Dummy']->get_key() );
	}

	public function filter_change_provider( $provider_key ) {
		return 'Two_Factor_Dummy_Secure';
	}

}
