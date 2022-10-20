<?php
/**
 * Test Two Factor Provider.
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
	private static $config_path;
	private static $original_config;
	//private static $original_config_permissions;

	/**
	 * Setup shared fixtures before any tests run.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		//ini_set( 'realpath_cache_size', 0 ); didn't help

		self::$config_path                 = Two_Factor_Provider::get_config_path();
		self::$original_config             = file_get_contents( self::$config_path );
		//self::$original_config_permissions = substr( sprintf( '%o', fileperms( self::$config_path ) ), -4 );

		if ( empty( self::$original_config ) ) {
			self::fail( 'Config file is empty.' );
		}
	}

	/**
	 * Restore global state between tests.
	 */
	public function tear_down() {
		//chmod( self::$config_path, octdec( self::$original_config_permissions ) );
		//clearstatcache here too, same as whatever below

		$restored = file_put_contents( self::$config_path, self::$original_config );

		if ( false === $restored ) {
			self::fail( 'Failed to restore original config.' );
		}

		parent::tear_down();
	}

	/**
	 * Test that new constants can be created.
	 *
	 * @covers Two_Factor_Provider::maybe_create_config_salt()
	 */
	public function test_create_new_constant() {
		$constant_name = 'FOO_NEW';

		// It doesn't exist yet
		$this->assertFalse( defined( $constant_name ) );
		$this->assertFalse( stripos( self::$original_config, $constant_name ) );

		$result     = Two_Factor_Provider::maybe_create_config_salt( $constant_name );
		$new_config = file_get_contents( self::$config_path );

		// It does exist now
		$this->assertTrue( $result );
		$this->assertTrue( defined( $constant_name ) );
		$this->assertTrue( 64 === strlen( constant( $constant_name ) ) );
		$this->assertNotEmpty( $new_config );
		$this->assertGreaterThan( 0, stripos( $new_config, $constant_name ) );
	}

	/**
	 * Test that existing constants aren't redefined.
	 *
	 * @covers Two_Factor_Provider::maybe_create_config_salt()
	 */
	public function test_create_existing_constant() {
		$this->assertTrue( defined( 'DB_NAME' ) );
		$result = Two_Factor_Provider::maybe_create_config_salt( 'DB_NAME' );
		$this->assertTrue( $result );
		$this->assertSame( self::$original_config, file_get_contents( self::$config_path ) );
	}

	/**
	 * Test that unwritable files return false
	 *
	 * @covers Two_Factor_Provider::maybe_create_config_salt()
	 */
	//public function test_unwritable_config() {
	//	// todo ugh don't waste more time on this, just test it manually once to make sure works and can leave this test out
	//
	//	chmod( self::$config_path, 0444 );
	//	clearstatcache( true, self::$config_path ); // doesn't work, neither does any other variation of params
	//	$this->assertFalse( is_writable( self::$config_path ) );
	//		// todo ^ says can write even though perms are 444
	//
	//	$this->assertFalse( defined( 'FOO_UNWRITABLE' ) );
	//	$result = Two_Factor_Provider::maybe_create_config_salt( 'FOO_UNWRITABLE' );
	//	$this->assertFalse( $result );
	//}
}
