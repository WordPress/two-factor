<?php
/**
 * Test Two Factor Passkey Provider
 *
 * @package Two_Factor
 */

class Tests_Two_Factor_Passkey extends WP_UnitTestCase {

	/**
	 * @var Two_Factor_Passkey
	 */
	protected $provider;

	public function set_up() {
		parent::set_up();
		$this->provider = Two_Factor_Passkey::get_instance();
	}

	public function test_get_label() {
		$this->assertSame( 'Passkeys', $this->provider->get_label() );
	}

	public function test_is_available_for_user() {
		$user_id = $this->factory->user->create();
		$user = get_user_by( 'id', $user_id );

		$this->assertFalse( $this->provider->is_available_for_user( $user ) );

		update_user_meta( $user_id, Two_Factor_Passkey::PASSKEYS_META_KEY, array( 'dummy_credential_id' => 'data' ) );

		$this->assertTrue( $this->provider->is_available_for_user( $user ) );
	}
}
