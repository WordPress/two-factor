<?php
/**
 * Test Two Factor FIDO U2F.
 */

/**
 * Class Tests_Two_Factor_FIDO_U2F
 *
 * @package Two_Factor
 * @group providers
 */
class Tests_Two_Factor_FIDO_U2F extends WP_UnitTestCase {

	/**
	 * Instance of our provider class.
	 *
	 * @var Two_Factor_FIDO_U2F
	 */
	protected $provider;

	/**
	 * Instance of the vendor U2F class.
	 *
	 * @var u2flib_server\U2F
	 */
	protected $u2f;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase::setup()
	 */
	function setUp() {
		parent::setUp();

		try {
			require_once('includes/Yubico/U2F.php');

			$this->u2f = new u2flib_server\U2F( "http://demo.example.com" );

			$this->provider = Two_Factor_FIDO_U2F::get_instance();
		} catch ( Exception $e ) {
			$this->markTestSkipped( 'Could not create U2F provider!' );
		}
	}

	/**
	 * Verify the label value.
	 */
	function test_get_label() {
		$this->assertContains( 'FIDO Universal 2nd Factor (U2F)', $this->provider->get_label() );
	}

	function test_add_security_key() {
		$req = json_decode('{"version":"U2F_V2","challenge":"yKA0x075tjJ-GE7fKTfnzTOSaNUOWQxRd9TWz5aFOg8","appId":"http://demo.example.com"}');
		$resp = json_decode('{ "registrationData": "BQQtEmhWVgvbh-8GpjsHbj_d5FB9iNoRL8mNEq34-ANufKWUpVdIj6BSB_m3eMoZ3GqnaDy3RA5eWP8mhTkT1Ht3QAk1GsmaPIQgXgvrBkCQoQtMFvmwYPfW5jpRgoMPFxquHS7MTt8lofZkWAK2caHD-YQQdaRBgd22yWIjPuWnHOcwggLiMIHLAgEBMA0GCSqGSIb3DQEBCwUAMB0xGzAZBgNVBAMTEll1YmljbyBVMkYgVGVzdCBDQTAeFw0xNDA1MTUxMjU4NTRaFw0xNDA2MTQxMjU4NTRaMB0xGzAZBgNVBAMTEll1YmljbyBVMkYgVGVzdCBFRTBZMBMGByqGSM49AgEGCCqGSM49AwEHA0IABNsK2_Uhx1zOY9ym4eglBg2U5idUGU-dJK8mGr6tmUQflaNxkQo6IOc-kV4T6L44BXrVeqN-dpCPr-KKlLYw650wDQYJKoZIhvcNAQELBQADggIBAJVAa1Bhfa2Eo7TriA_jMA8togoA2SUE7nL6Z99YUQ8LRwKcPkEpSpOsKYWJLaR6gTIoV3EB76hCiBaWN5HV3-CPyTyNsM2JcILsedPGeHMpMuWrbL1Wn9VFkc7B3Y1k3OmcH1480q9RpYIYr-A35zKedgV3AnvmJKAxVhv9GcVx0_CewHMFTryFuFOe78W8nFajutknarupekDXR4tVcmvj_ihJcST0j_Qggeo4_3wKT98CgjmBgjvKCd3Kqg8n9aSDVWyaOZsVOhZj3Fv5rFu895--D4qiPDETozJIyliH-HugoQpqYJaTX10mnmMdCa6aQeW9CEf-5QmbIP0S4uZAf7pKYTNmDQ5z27DVopqaFw00MIVqQkae_zSPX4dsNeeoTTXrwUGqitLaGap5ol81LKD9JdP3nSUYLfq0vLsHNDyNgb306TfbOenRRVsgQS8tJyLcknSKktWD_Qn7E5vjOXprXPrmdp7g5OPvrbz9QkWa1JTRfo2n2AXV02LPFc-UfR9bWCBEIJBxvmbpmqt0MnBTHWnth2b0CU_KJTDCY3kAPLGbOT8A4KiI73pRW-e9SWTaQXskw3Ei_dHRILM_l9OXsqoYHJ4Dd3tbfvmjoNYggSw4j50l3unI9d1qR5xlBFpW5sLr8gKX4bnY4SR2nyNiOQNLyPc0B0nW502aMEUCIQDTGOX-i_QrffJDY8XvKbPwMuBVrOSO-ayvTnWs_WSuDQIgZ7fMAvD_Ezyy5jg6fQeuOkoJi8V2naCtzV-HTly8Nww=", "clientData": "eyAiY2hhbGxlbmdlIjogInlLQTB4MDc1dGpKLUdFN2ZLVGZuelRPU2FOVU9XUXhSZDlUV3o1YUZPZzgiLCAib3JpZ2luIjogImh0dHA6XC9cL2RlbW8uZXhhbXBsZS5jb20iLCAidHlwIjogIm5hdmlnYXRvci5pZC5maW5pc2hFbnJvbGxtZW50IiB9" }');
		$reg = $this->u2f->doRegister($req, $resp);

		$add_method = new ReflectionMethod( $this->provider, 'add_security_key' );
		$add_method->setAccessible(true);
		$delete_method = new ReflectionMethod( $this->provider, 'delete_security_key' );
		$delete_method->setAccessible(true);

		$user_id = $this->factory->user->create();
		$this->assertInternalType( "int", $add_method->invoke($this->provider, $user_id, $reg ) );

		$delete_method->invoke($this->provider, $user_id );
	}

	function test_add_security_key2() {
		$reg = json_decode('{"keyHandle":"j_k-SThUkpALxdWPS8PjBzxaNUMj3WQIGz1rHIDMRnZCMr26C7xWItTRgiRqeqPVMPgSC6WvBJcKgqNNcReDAw","publicKey":"BBLL5S5hWjGRZzpw4kZnDMbAVTdWtlBFaCt5Fsn7sPWAXdInZpVdk\/bGNVfm43+uUmB2w6u90lf57PXQghhbF4I=","certificate":"MIICLjCCARigAwIBAgIECmML\/zALBgkqhkiG9w0BAQswLjEsMCoGA1UEAxMjWXViaWNvIFUyRiBSb290IENBIFNlcmlhbCA0NTcyMDA2MzEwIBcNMTQwODAxMDAwMDAwWhgPMjA1MDA5MDQwMDAwMDBaMCkxJzAlBgNVBAMMHll1YmljbyBVMkYgRUUgU2VyaWFsIDE3NDI2MzI5NTBZMBMGByqGSM49AgEGCCqGSM49AwEHA0IABKQjZF26iyPtbNnl5IuTKs\/fRWTHVzHxz1IHRRBrSbqWD60PCqUJPe4zkIRFqBa4NnzdhVcS80nlZuY3ANQm0J+jJjAkMCIGCSsGAQQBgsQKAgQVMS4zLjYuMS40LjEuNDE0ODIuMS4yMAsGCSqGSIb3DQEBCwOCAQEAZTmwMqHPxEjSB64Umwq2tGDKplAcEzrwmg6kgS8KPkJKXKSu9T1H6XBM9+LAE9cN48oUirFFmDIlTbZRXU2Vm2qO9OdrSVFY+qdbF9oti8CKAmPHuJZSW6ii7qNE59dHKUaP4lDYpnhRDqttWSUalh2LPDJQUpO9bsJPkgNZAhBUQMYZXL\/MQZLRYkX+ld7llTNOX5u7n\/4Y5EMr+lqOyVVC9lQ6JP6xoa9q6Zp9+Y9ZmLCecrrcuH6+pLDgAzPcc8qxhC2OR1B0ZSpI9RBgcT0KqnVE0tq1KEDeokPqF3MgmDRkJ++\/a2pV0wAYfPC3tC57BtBdH\/UXEB8xZVFhtA==","counter":-1}');

		$add_method = new ReflectionMethod( $this->provider, 'add_security_key' );
		$add_method->setAccessible(true);
		$delete_method = new ReflectionMethod( $this->provider, 'delete_security_key' );
		$delete_method->setAccessible(true);

		$user_id = $this->factory->user->create();
		$this->assertInternalType( "int", $add_method->invoke($this->provider, $user_id, $reg ) );

		$delete_method->invoke($this->provider, $user_id );
	}

	function test_get_security_keys() {
		$reg = json_decode('{"keyHandle":"j_k-SThUkpALxdWPS8PjBzxaNUMj3WQIGz1rHIDMRnZCMr26C7xWItTRgiRqeqPVMPgSC6WvBJcKgqNNcReDAw","publicKey":"BBLL5S5hWjGRZzpw4kZnDMbAVTdWtlBFaCt5Fsn7sPWAXdInZpVdk\/bGNVfm43+uUmB2w6u90lf57PXQghhbF4I=","certificate":"MIICLjCCARigAwIBAgIECmML\/zALBgkqhkiG9w0BAQswLjEsMCoGA1UEAxMjWXViaWNvIFUyRiBSb290IENBIFNlcmlhbCA0NTcyMDA2MzEwIBcNMTQwODAxMDAwMDAwWhgPMjA1MDA5MDQwMDAwMDBaMCkxJzAlBgNVBAMMHll1YmljbyBVMkYgRUUgU2VyaWFsIDE3NDI2MzI5NTBZMBMGByqGSM49AgEGCCqGSM49AwEHA0IABKQjZF26iyPtbNnl5IuTKs\/fRWTHVzHxz1IHRRBrSbqWD60PCqUJPe4zkIRFqBa4NnzdhVcS80nlZuY3ANQm0J+jJjAkMCIGCSsGAQQBgsQKAgQVMS4zLjYuMS40LjEuNDE0ODIuMS4yMAsGCSqGSIb3DQEBCwOCAQEAZTmwMqHPxEjSB64Umwq2tGDKplAcEzrwmg6kgS8KPkJKXKSu9T1H6XBM9+LAE9cN48oUirFFmDIlTbZRXU2Vm2qO9OdrSVFY+qdbF9oti8CKAmPHuJZSW6ii7qNE59dHKUaP4lDYpnhRDqttWSUalh2LPDJQUpO9bsJPkgNZAhBUQMYZXL\/MQZLRYkX+ld7llTNOX5u7n\/4Y5EMr+lqOyVVC9lQ6JP6xoa9q6Zp9+Y9ZmLCecrrcuH6+pLDgAzPcc8qxhC2OR1B0ZSpI9RBgcT0KqnVE0tq1KEDeokPqF3MgmDRkJ++\/a2pV0wAYfPC3tC57BtBdH\/UXEB8xZVFhtA==","counter":-1}');

		$add_method = new ReflectionMethod( $this->provider, 'add_security_key' );
		$add_method->setAccessible(true);
		$get_method = new ReflectionMethod( $this->provider, 'get_security_keys' );
		$get_method->setAccessible(true);
		$delete_method = new ReflectionMethod( $this->provider, 'delete_security_key' );
		$delete_method->setAccessible(true);

		$user_id = $this->factory->user->create();

		$add_method->invoke($this->provider, $user_id, $reg );
		$actual = $get_method->invoke($this->provider, $user_id );

		$this->assertEquals($reg->keyHandle, $actual[0]->keyHandle);
		$this->assertEquals($reg->publicKey, $actual[0]->publicKey);
		$this->assertEquals($reg->certificate, $actual[0]->certificate);
		$this->assertEquals($reg->counter, $actual[0]->counter);

		$delete_method->invoke($this->provider, $user_id );
	}

	function test_update_security_key() {
		$reqs = array(json_decode('{"version":"U2F_V2","challenge":"fEnc9oV79EaBgK5BoNERU5gPKM2XGYWrz4fUjgc0Q7g","keyHandle":"CTUayZo8hCBeC-sGQJChC0wW-bBg99bmOlGCgw8XGq4dLsxO3yWh9mRYArZxocP5hBB1pEGB3bbJYiM-5acc5w","appId":"http://demo.example.com"}'));
		$regs = array(json_decode('{"keyHandle":"CTUayZo8hCBeC-sGQJChC0wW-bBg99bmOlGCgw8XGq4dLsxO3yWh9mRYArZxocP5hBB1pEGB3bbJYiM-5acc5w","publicKey":"BC0SaFZWC9uH7wamOwduP93kUH2I2hEvyY0Srfj4A258pZSlV0iPoFIH+bd4yhncaqdoPLdEDl5Y\/yaFORPUe3c=","certificate":"MIIC4jCBywIBATANBgkqhkiG9w0BAQsFADAdMRswGQYDVQQDExJZdWJpY28gVTJGIFRlc3QgQ0EwHhcNMTQwNTE1MTI1ODU0WhcNMTQwNjE0MTI1ODU0WjAdMRswGQYDVQQDExJZdWJpY28gVTJGIFRlc3QgRUUwWTATBgcqhkjOPQIBBggqhkjOPQMBBwNCAATbCtv1IcdczmPcpuHoJQYNlOYnVBlPnSSvJhq+rZlEH5WjcZEKOiDnPpFeE+i+OAV61XqjfnaQj6\/iipS2MOudMA0GCSqGSIb3DQEBCwUAA4ICAQCVQGtQYX2thKO064gP4zAPLaIKANklBO5y+mffWFEPC0cCnD5BKUqTrCmFiS2keoEyKFdxAe+oQogWljeR1d\/gj8k8jbDNiXCC7HnTxnhzKTLlq2y9Vp\/VRZHOwd2NZNzpnB9ePNKvUaWCGK\/gN+cynnYFdwJ75iSgMVYb\/RnFcdPwnsBzBU68hbhTnu\/FvJxWo7rZJ2q7qXpA10eLVXJr4\/4oSXEk9I\/0IIHqOP98Ck\/fAoI5gYI7ygndyqoPJ\/Wkg1VsmjmbFToWY9xb+axbvPefvg+KojwxE6MySMpYh\/h7oKEKamCWk19dJp5jHQmumkHlvQhH\/uUJmyD9EuLmQH+6SmEzZg0Oc9uw1aKamhcNNDCFakJGnv80j1+HbDXnqE0168FBqorS2hmqeaJfNSyg\/SXT950lGC36tLy7BzQ8jYG99Ok32znp0UVbIEEvLSci3JJ0ipLVg\/0J+xOb4zl6a1z65nae4OTj7628\/UJFmtSU0X6Np9gF1dNizxXPlH0fW1ggRCCQcb5m6ZqrdDJwUx1p7Ydm9AlPyiUwwmN5ADyxmzk\/AOCoiO96UVvnvUlk2kF7JMNxIv3R0SCzP5fTl7KqGByeA3d7W375o6DWIIEsOI+dJd7pyPXdakecZQRaVubC6\/ICl+G52OEkdp8jYjkDS8j3NAdJ1udNmg==", "counter":3}'));
		$resp = json_decode('{ "signatureData": "AQAAAAQwRQIhAI6FSrMD3KUUtkpiP0jpIEakql-HNhwWFngyw553pS1CAiAKLjACPOhxzZXuZsVO8im-HStEcYGC50PKhsGp_SUAng==", "clientData": "eyAiY2hhbGxlbmdlIjogImZFbmM5b1Y3OUVhQmdLNUJvTkVSVTVnUEtNMlhHWVdyejRmVWpnYzBRN2ciLCAib3JpZ2luIjogImh0dHA6XC9cL2RlbW8uZXhhbXBsZS5jb20iLCAidHlwIjogIm5hdmlnYXRvci5pZC5nZXRBc3NlcnRpb24iIH0=", "keyHandle": "CTUayZo8hCBeC-sGQJChC0wW-bBg99bmOlGCgw8XGq4dLsxO3yWh9mRYArZxocP5hBB1pEGB3bbJYiM-5acc5w" }');

		$add_method = new ReflectionMethod( $this->provider, 'add_security_key' );
		$add_method->setAccessible(true);
		$get_method = new ReflectionMethod( $this->provider, 'get_security_keys' );
		$get_method->setAccessible(true);
		$update_method = new ReflectionMethod( $this->provider, 'update_security_key' );
		$update_method->setAccessible(true);
		$delete_method = new ReflectionMethod( $this->provider, 'delete_security_key' );
		$delete_method->setAccessible(true);

		$user_id = $this->factory->user->create();
		$add_method->invoke($this->provider, $user_id, $regs[0] );

		$data = $this->u2f->doAuthenticate($reqs, $regs, $resp);

		$this->assertEquals(true, $update_method->invoke($this->provider, $user_id, $data ) );

		$meta = $get_method->invoke($this->provider, $user_id );
		$this->assertEquals($data->keyHandle,   $meta[0]->keyHandle);
		$this->assertEquals($data->publicKey,   $meta[0]->publicKey);
		$this->assertEquals($data->certificate, $meta[0]->certificate);
		$this->assertEquals($data->counter,     $meta[0]->counter);
		$this->assertEquals(4, $meta[0]->counter);

		$delete_method->invoke($this->provider, $user_id );
	}

	function test_update_security_key2() {
		$reqs = array(json_decode('{"version":"U2F_V2","challenge":"fEnc9oV79EaBgK5BoNERU5gPKM2XGYWrz4fUjgc0Q7g","keyHandle":"CTUayZo8hCBeC-sGQJChC0wW-bBg99bmOlGCgw8XGq4dLsxO3yWh9mRYArZxocP5hBB1pEGB3bbJYiM-5acc5w","appId":"http://demo.example.com"}'));
		$regs = array(json_decode('{"keyHandle":"CTUayZo8hCBeC-sGQJChC0wW-bBg99bmOlGCgw8XGq4dLsxO3yWh9mRYArZxocP5hBB1pEGB3bbJYiM-5acc5w","publicKey":"BC0SaFZWC9uH7wamOwduP93kUH2I2hEvyY0Srfj4A258pZSlV0iPoFIH+bd4yhncaqdoPLdEDl5Y\/yaFORPUe3c=","certificate":"MIIC4jCBywIBATANBgkqhkiG9w0BAQsFADAdMRswGQYDVQQDExJZdWJpY28gVTJGIFRlc3QgQ0EwHhcNMTQwNTE1MTI1ODU0WhcNMTQwNjE0MTI1ODU0WjAdMRswGQYDVQQDExJZdWJpY28gVTJGIFRlc3QgRUUwWTATBgcqhkjOPQIBBggqhkjOPQMBBwNCAATbCtv1IcdczmPcpuHoJQYNlOYnVBlPnSSvJhq+rZlEH5WjcZEKOiDnPpFeE+i+OAV61XqjfnaQj6\/iipS2MOudMA0GCSqGSIb3DQEBCwUAA4ICAQCVQGtQYX2thKO064gP4zAPLaIKANklBO5y+mffWFEPC0cCnD5BKUqTrCmFiS2keoEyKFdxAe+oQogWljeR1d\/gj8k8jbDNiXCC7HnTxnhzKTLlq2y9Vp\/VRZHOwd2NZNzpnB9ePNKvUaWCGK\/gN+cynnYFdwJ75iSgMVYb\/RnFcdPwnsBzBU68hbhTnu\/FvJxWo7rZJ2q7qXpA10eLVXJr4\/4oSXEk9I\/0IIHqOP98Ck\/fAoI5gYI7ygndyqoPJ\/Wkg1VsmjmbFToWY9xb+axbvPefvg+KojwxE6MySMpYh\/h7oKEKamCWk19dJp5jHQmumkHlvQhH\/uUJmyD9EuLmQH+6SmEzZg0Oc9uw1aKamhcNNDCFakJGnv80j1+HbDXnqE0168FBqorS2hmqeaJfNSyg\/SXT950lGC36tLy7BzQ8jYG99Ok32znp0UVbIEEvLSci3JJ0ipLVg\/0J+xOb4zl6a1z65nae4OTj7628\/UJFmtSU0X6Np9gF1dNizxXPlH0fW1ggRCCQcb5m6ZqrdDJwUx1p7Ydm9AlPyiUwwmN5ADyxmzk\/AOCoiO96UVvnvUlk2kF7JMNxIv3R0SCzP5fTl7KqGByeA3d7W375o6DWIIEsOI+dJd7pyPXdakecZQRaVubC6\/ICl+G52OEkdp8jYjkDS8j3NAdJ1udNmg==", "counter":3}'));
		$resp = json_decode('{ "signatureData": "AQAAAAQwRQIhAI6FSrMD3KUUtkpiP0jpIEakql-HNhwWFngyw553pS1CAiAKLjACPOhxzZXuZsVO8im-HStEcYGC50PKhsGp_SUAng==", "clientData": "eyAiY2hhbGxlbmdlIjogImZFbmM5b1Y3OUVhQmdLNUJvTkVSVTVnUEtNMlhHWVdyejRmVWpnYzBRN2ciLCAib3JpZ2luIjogImh0dHA6XC9cL2RlbW8uZXhhbXBsZS5jb20iLCAidHlwIjogIm5hdmlnYXRvci5pZC5nZXRBc3NlcnRpb24iIH0=", "keyHandle": "CTUayZo8hCBeC-sGQJChC0wW-bBg99bmOlGCgw8XGq4dLsxO3yWh9mRYArZxocP5hBB1pEGB3bbJYiM-5acc5w" }');

		$add_method = new ReflectionMethod( $this->provider, 'add_security_key' );
		$add_method->setAccessible(true);
		$get_method = new ReflectionMethod( $this->provider, 'get_security_keys' );
		$get_method->setAccessible(true);
		$update_method = new ReflectionMethod( $this->provider, 'update_security_key' );
		$update_method->setAccessible(true);
		$delete_method = new ReflectionMethod( $this->provider, 'delete_security_key' );
		$delete_method->setAccessible(true);

		$user_id = $this->factory->user->create();

		$data = $this->u2f->doAuthenticate($reqs, $regs, $resp);

		$this->assertInternalType( "int", $update_method->invoke($this->provider, $user_id, $data ) );

		$meta = $get_method->invoke($this->provider, $user_id );
		$this->assertEquals($data->keyHandle,   $meta[0]->keyHandle);
		$this->assertEquals($data->publicKey,   $meta[0]->publicKey);
		$this->assertEquals($data->certificate, $meta[0]->certificate);
		$this->assertEquals($data->counter,     $meta[0]->counter);
		$this->assertEquals(4, $meta[0]->counter);

		$delete_method->invoke($this->provider, $user_id );
	}

	function test_delete_security_key() {
		$get_method = new ReflectionMethod( $this->provider, 'get_security_keys' );
		$get_method->setAccessible(true);
		$delete_method = new ReflectionMethod( $this->provider, 'delete_security_key' );
		$delete_method->setAccessible(true);

		$user_id = $this->factory->user->create();
		$delete_method->invoke($this->provider, $user_id );

		$this->assertEquals(array(), $get_method->invoke($this->provider, $user_id ));
	}

	function test_delete_security_key2() {
		$req = json_decode('{"version":"U2F_V2","challenge":"yKA0x075tjJ-GE7fKTfnzTOSaNUOWQxRd9TWz5aFOg8","appId":"http://demo.example.com"}');
		$resp = json_decode('{ "registrationData": "BQQtEmhWVgvbh-8GpjsHbj_d5FB9iNoRL8mNEq34-ANufKWUpVdIj6BSB_m3eMoZ3GqnaDy3RA5eWP8mhTkT1Ht3QAk1GsmaPIQgXgvrBkCQoQtMFvmwYPfW5jpRgoMPFxquHS7MTt8lofZkWAK2caHD-YQQdaRBgd22yWIjPuWnHOcwggLiMIHLAgEBMA0GCSqGSIb3DQEBCwUAMB0xGzAZBgNVBAMTEll1YmljbyBVMkYgVGVzdCBDQTAeFw0xNDA1MTUxMjU4NTRaFw0xNDA2MTQxMjU4NTRaMB0xGzAZBgNVBAMTEll1YmljbyBVMkYgVGVzdCBFRTBZMBMGByqGSM49AgEGCCqGSM49AwEHA0IABNsK2_Uhx1zOY9ym4eglBg2U5idUGU-dJK8mGr6tmUQflaNxkQo6IOc-kV4T6L44BXrVeqN-dpCPr-KKlLYw650wDQYJKoZIhvcNAQELBQADggIBAJVAa1Bhfa2Eo7TriA_jMA8togoA2SUE7nL6Z99YUQ8LRwKcPkEpSpOsKYWJLaR6gTIoV3EB76hCiBaWN5HV3-CPyTyNsM2JcILsedPGeHMpMuWrbL1Wn9VFkc7B3Y1k3OmcH1480q9RpYIYr-A35zKedgV3AnvmJKAxVhv9GcVx0_CewHMFTryFuFOe78W8nFajutknarupekDXR4tVcmvj_ihJcST0j_Qggeo4_3wKT98CgjmBgjvKCd3Kqg8n9aSDVWyaOZsVOhZj3Fv5rFu895--D4qiPDETozJIyliH-HugoQpqYJaTX10mnmMdCa6aQeW9CEf-5QmbIP0S4uZAf7pKYTNmDQ5z27DVopqaFw00MIVqQkae_zSPX4dsNeeoTTXrwUGqitLaGap5ol81LKD9JdP3nSUYLfq0vLsHNDyNgb306TfbOenRRVsgQS8tJyLcknSKktWD_Qn7E5vjOXprXPrmdp7g5OPvrbz9QkWa1JTRfo2n2AXV02LPFc-UfR9bWCBEIJBxvmbpmqt0MnBTHWnth2b0CU_KJTDCY3kAPLGbOT8A4KiI73pRW-e9SWTaQXskw3Ei_dHRILM_l9OXsqoYHJ4Dd3tbfvmjoNYggSw4j50l3unI9d1qR5xlBFpW5sLr8gKX4bnY4SR2nyNiOQNLyPc0B0nW502aMEUCIQDTGOX-i_QrffJDY8XvKbPwMuBVrOSO-ayvTnWs_WSuDQIgZ7fMAvD_Ezyy5jg6fQeuOkoJi8V2naCtzV-HTly8Nww=", "clientData": "eyAiY2hhbGxlbmdlIjogInlLQTB4MDc1dGpKLUdFN2ZLVGZuelRPU2FOVU9XUXhSZDlUV3o1YUZPZzgiLCAib3JpZ2luIjogImh0dHA6XC9cL2RlbW8uZXhhbXBsZS5jb20iLCAidHlwIjogIm5hdmlnYXRvci5pZC5maW5pc2hFbnJvbGxtZW50IiB9" }');
		$reg = $this->u2f->doRegister($req, $resp);

		$add_method = new ReflectionMethod( $this->provider, 'add_security_key' );
		$add_method->setAccessible(true);
		$get_method = new ReflectionMethod( $this->provider, 'get_security_keys' );
		$get_method->setAccessible(true);
		$delete_method = new ReflectionMethod( $this->provider, 'delete_security_key' );
		$delete_method->setAccessible(true);

		$user_id = $this->factory->user->create();
		$add_method->invoke($this->provider, $user_id, $reg );
		$delete_method->invoke($this->provider, $user_id );

		$this->assertEquals(array(), $get_method->invoke($this->provider, $user_id ));
	}
}
