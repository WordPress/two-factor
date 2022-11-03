<?php
/**
 * Test Two Factor FIDO U2F.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_WebAuthn
 *
 * @package Two_Factor
 * @group providers
 */
class Tests_Two_Factor_WebAuthn extends WP_UnitTestCase {

	private $registration_payload = '{
		"rawId":[87,80,104,99,114,105,242,93,228,236,13,229,38,46,106,28,137,148,253,93,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0],
		"id":"V1BoY3Jp8l3k7A3lJi5qHImU_V0AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
		"type":"public-key",
		"response":{
			"attestationObject":[163,99,102,109,116,100,110,111,110,101,103,97,116,116,83,116,109,116,160,104,97,117,116,104,68,97,116,97,88,202,8,99,119,173,50,147,45,217,178,80,98,28,172,142,22,49,31,86,54,121,220,23,215,66,25,115,254,227,13,103,50,169,65,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,70,87,80,104,99,114,105,242,93,228,236,13,229,38,46,106,28,137,148,253,93,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,165,1,2,3,38,32,1,33,88,32,206,171,161,194,131,117,59,237,98,52,69,222,8,194,112,14,210,10,32,118,244,130,91,255,194,88,250,26,133,232,251,7,34,88,32,174,88,49,78,229,69,143,60,4,230,24,208,109,254,244,17,52,112,247,80,35,164,106,111,124,233,54,235,59,88,186,130],
			"clientDataJSON":{
				"challenge":"blJGueerd0LwM-L0SL7F_w",
				"origin":"https://mu.wordpress.local",
				"type":"webauthn.create"
			}
		}
	}';

	private $authentication_payload = '{
		"type":"public-key",
		"originalChallenge":[217,231,76,191,26,145,184,220,67,97,132,111,2,171,201,47],
		"rawId":[67,54,58,166,42,6,98,53,38,200,37,88,228,35,173,43,86,61,67,158,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0],
		"response":{
			"authenticatorData":[8,99,119,173,50,147,45,217,178,80,98,28,172,142,22,49,31,86,54,121,220,23,215,66,25,115,254,227,13,103,50,169,1,0,0,3,23],
			"clientData":{
				"challenge":"2edMvxqRuNxDYYRvAqvJLw",
				"origin":"https://mu.wordpress.local",
				"type":"webauthn.get"
			},
			"clientDataJSONarray":[123,34,99,104,97,108,108,101,110,103,101,34,58,34,50,101,100,77,118,120,113,82,117,78,120,68,89,89,82,118,65,113,118,74,76,119,34,44,34,111,114,105,103,105,110,34,58,34,104,116,116,112,115,58,47,47,109,117,46,119,111,114,100,112,114,101,115,115,46,108,111,99,97,108,34,44,34,116,121,112,101,34,58,34,119,101,98,97,117,116,104,110,46,103,101,116,34,125],
			"signature":[48,70,2,33,0,192,248,68,123,255,29,98,208,114,247,171,154,176,238,228,181,57,101,151,216,236,13,185,55,135,38,86,145,250,147,48,194,2,33,0,193,141,246,5,72,138,5,201,224,30,37,184,43,172,29,84,244,85,35,107,183,27,203,246,96,214,149,180,229,145,77,30]
		}
	}';

	private $serialized_key = 'O:8:"stdClass":7:{s:3:"key";s:178:"-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAENWyfR/Dv24yoxFxHQAWulCHn6OO7
kTim0qBEAjJ1pJODE9/HH/1sPjDQqC3urY2rnzvxwdkDxeSEWHMmMyYjdA==
-----END PUBLIC KEY-----
";s:2:"id";a:70:{i:0;i:67;i:1;i:54;i:2;i:58;i:3;i:166;i:4;i:42;i:5;i:6;i:6;i:98;i:7;i:53;i:8;i:38;i:9;i:200;i:10;i:37;i:11;i:88;i:12;i:228;i:13;i:35;i:14;i:173;i:15;i:43;i:16;i:86;i:17;i:61;i:18;i:67;i:19;i:158;i:20;i:0;i:21;i:0;i:22;i:0;i:23;i:0;i:24;i:0;i:25;i:0;i:26;i:0;i:27;i:0;i:28;i:0;i:29;i:0;i:30;i:0;i:31;i:0;i:32;i:0;i:33;i:0;i:34;i:0;i:35;i:0;i:36;i:0;i:37;i:0;i:38;i:0;i:39;i:0;i:40;i:0;i:41;i:0;i:42;i:0;i:43;i:0;i:44;i:0;i:45;i:0;i:46;i:0;i:47;i:0;i:48;i:0;i:49;i:0;i:50;i:0;i:51;i:0;i:52;i:0;i:53;i:0;i:54;i:0;i:55;i:0;i:56;i:0;i:57;i:0;i:58;i:0;i:59;i:0;i:60;i:0;i:61;i:0;i:62;i:0;i:63;i:0;i:64;i:0;i:65;i:0;i:66;i:0;i:67;i:0;i:68;i:0;i:69;i:0;}s:5:"label";s:31:"New Device - mu.wordpress.local";s:5:"md5id";s:32:"8b8c94e8772035976355b604571c29a6";s:7:"created";i:1667479980;s:9:"last_used";b:0;s:6:"tested";b:1;}';

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_WebAuthn
	 */
	protected $provider;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase::setup()
	 */
	public function setUp(): void {
		parent::setUp();

		$this->provider = Two_Factor_WebAuthn::get_instance();
	}

	/**
	 * Clean up after tests.
	 *
	 * @see WP_UnitTestCase::tearDown()
	 */
	public function tearDown(): void {
		unset( $this->provider );

		parent::tearDown();
	}

	/**
	 * Verify an instance exists.
	 *
	 * @covers Two_Factor_Totp::get_instance
	 */
	public function test_get_instance() {
		$this->assertNotNull( $this->provider->get_instance() );
	}

	/**
	 * Verify the label value.
	 *
	 * @covers Two_Factor_WebAuthn::test_get_label
	 */
	public function test_get_label() {
		$this->assertStringContainsString( 'Web Authentication (FIDO2)', $this->provider->get_label() );
	}

	/**
	 * Verify appi id is a valid hostname
	 *
	 * @covers Two_Factor_WebAuthn::get_app_id
	 */
	public function test_get_app_id() {

		$app_id = $this->provider->get_app_id();

		// whether this is a valid hostname
		$this->assertIsString( filter_var( $app_id, FILTER_VALIDATE_DOMAIN, FILTER_NULL_ON_FAILURE ) );

		// the key is part of the current wp hostname
		$this->assertStringContainsString( $app_id, get_option('home') );

	}

	/**
	 * @covers Two_Factor_WebAuthn::validate_authentication
	 */
	public function test_validate_authentication() {

		$user_id = $this->factory->user->create();
		$user = new WP_User( $user_id );

		$key = unserialize( $this->serialized_key );

		$key_store = WebAuthnKeyStore::instance();

		add_user_meta( $user_id, '_two_factor_enabled_providers', array( 'Two_Factor_WebAuthn' ) );
		add_user_meta( $user_id, '_two_factor_provider', 'Two_Factor_WebAuthn' );

		$key_store->save_key( $user_id, $key, $key->md5id );

		// test non-json response
		$_POST['webauthn_response'] = '-- garbage --';

		$result = $this->provider->validate_authentication( $user );
		$this->assertFalse( $result );


		// test successful authentication
		// keys are domain specific. We are testing actual keys, so we can't simply use a dummy host here
		$webauthn = new WebAuthnHandler( 'mu.wordpress.local' );
		$result = $webauthn->authenticate( json_decode( $this->authentication_payload ), $key_store->get_keys( $user_id ) );
		// craft a request, try to verify
		$this->assertIsObject( $result ); // dummy


		// test key deletion
		$key_store->delete_key( $user_id, $key->md5id );
		$result = $key_store->find_key( $user_id, $key->md5id );
		$this->assertFalse( $result );

		$result = $webauthn->authenticate( json_decode( $this->authentication_payload ), $key_store->get_keys( $user_id ) );
		// craft a request, try to verify
		$this->assertFalse( $result ); // dummy

	}

	/**
	 * @covers Two_Factor_WebAuthn::ajax_register
	 */
	public function test_register() {
		add_filter( 'wp_die_ajax_handler', function( $handler ) { return '__return_false'; } );
		add_filter( 'wp_ajax_handler', function() { return '__return_false'; } );

		$user_id = $this->factory->user->create();
		$user = new WP_User( $user_id );

		$webauthn = new WebAuthnHandler( 'mu.wordpress.local' );
		$key_store = WebAuthnKeyStore::instance();

		$credential = json_decode( $this->registration_payload );

		$key = $webauthn->register( $credential, '' );

		$this->assertIsObject( $key );

		/* translators: %s webauthn app id (domain) */
		$key->label     = sprintf( esc_html__( 'New Device - %s', 'two-factor' ), $this->provider->get_app_id() );
		$key->md5id     = md5( implode( '', array_map( 'chr', $key->id ) ) );
		$key->created   = time();
		$key->last_used = false;
		$key->tested    = false;

		$meta_id = $key_store->save_key( $user_id, $key, $key->md5id );

		$this->assertIsInt( $meta_id );

		// save the same key again
		$key->label = 'name was changed';
		$alternative_meta_id = $key_store->save_key( $user_id, $key, $key->md5id );

		$this->assertEquals( $meta_id, $alternative_meta_id );


		// try to save the same key again
		$new_meta_id = $key_store->create_key( $user_id, $key );

		$this->assertFalse( $new_meta_id );

		$keys = $key_store->get_keys( $user_id );
		$this->assertEquals( count( $keys ), 1 );

	}

}
