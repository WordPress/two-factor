<?php
/**
 * Test the Two_Factor_CLI_Command WP-CLI commands.
 *
 * @package Two_Factor
 */

/**
 * Class Tests_Two_Factor_CLI_Command
 *
 * @package Two_Factor
 * @group cli
 */
class Tests_Two_Factor_CLI_Command extends WP_UnitTestCase {

	/**
	 * The command instance under test.
	 *
	 * @var Two_Factor_CLI_Command
	 */
	protected $command;

	/**
	 * Load the WP-CLI test doubles and the command under test.
	 *
	 * The WP-CLI runtime is absent during PHPUnit runs, so the stub classes and
	 * namespaced helper functions must be loaded before the command class, whose
	 * declaration extends WP_CLI_Command.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once __DIR__ . '/class-wp-cli-command.php';
		require_once __DIR__ . '/class-wp-cli-mock-exit-exception.php';
		require_once __DIR__ . '/wp-cli-utils.php';
		require_once __DIR__ . '/class-wp-cli.php';
		require_once dirname( dirname( __DIR__ ) ) . '/CLI/class-two-factor-cli-command.php';
	}

	/**
	 * A user with no two-factor configuration.
	 *
	 * @var WP_User
	 */
	protected $user;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase_Base::set_up()
	 */
	public function set_up() {
		parent::set_up();

		WP_CLI::reset();

		$this->command = new Two_Factor_CLI_Command();
		$this->user    = self::factory()->user->create_and_get(
			array(
				'user_login' => 'cli_test_user',
				'user_email' => 'cli_test_user@example.com',
				'role'       => 'administrator',
			)
		);
	}

	/**
	 * Run a command callback that is expected to abort via WP_CLI::error()/confirm().
	 *
	 * @param callable $callback The command invocation.
	 * @return string The message from the last captured error entry.
	 */
	protected function assert_command_aborts( $callback ) {
		try {
			$callback();
		} catch ( WP_CLI_Mock_Exit_Exception $e ) {
			return $e->getMessage();
		}

		$this->fail( 'Expected the command to abort with WP_CLI::error() or a declined confirmation.' );
	}

	/**
	 * Return the message string from the most recent captured entry of a level.
	 *
	 * @param string $level Message level (success|error|log|warning).
	 * @return string
	 */
	protected function last_message( $level ) {
		$entries = WP_CLI::get_logs( $level );
		$last    = end( $entries );

		return $last ? ( is_array( $last['message'] ) ? implode( "\n", $last['message'] ) : (string) $last['message'] ) : '';
	}

	/**
	 * Return the most recent captured format_items() payload.
	 *
	 * @return array|null
	 */
	protected function last_format() {
		$entries = WP_CLI::get_logs( 'format' );

		return $entries ? end( $entries ) : null;
	}

	/**
	 * Enable a provider for the test user directly through the core API.
	 *
	 * @param string $provider Provider class name.
	 */
	protected function enable_provider( $provider ) {
		$this->assertTrue(
			Two_Factor_Core::enable_provider_for_user( $this->user->ID, $provider ),
			"Failed to enable {$provider} for the test user."
		);
	}

	/**
	 * Create a live session for the test user and assert it exists.
	 */
	protected function create_user_session() {
		$manager = WP_Session_Tokens::get_instance( $this->user->ID );
		$manager->create( time() + HOUR_IN_SECONDS );

		$this->assertNotEmpty( $manager->get_all(), 'Expected a session to exist before the command runs.' );
	}

	/**
	 * Count the test user's active sessions.
	 *
	 * @return int
	 */
	protected function count_user_sessions() {
		return count( WP_Session_Tokens::get_instance( $this->user->ID )->get_all() );
	}

	/**
	 * The command class extends the WP-CLI base command.
	 */
	public function test_extends_wp_cli_command() {
		$this->assertInstanceOf( 'WP_CLI_Command', $this->command );
	}

	/*
	 * ---------------------------------------------------------------------
	 * User resolution
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The user can be resolved by numeric ID.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_resolve_user_by_id() {
		$this->command->status( array( (string) $this->user->ID ), array() );

		$format = $this->last_format();
		$this->assertNotNull( $format );
		$this->assertSame( $this->user->ID, $format['items'][0]['user_id'] );
	}

	/**
	 * The user can be resolved by login.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_resolve_user_by_login() {
		$this->command->status( array( 'cli_test_user' ), array() );

		$format = $this->last_format();
		$this->assertSame( $this->user->ID, $format['items'][0]['user_id'] );
	}

	/**
	 * The user can be resolved by email.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_resolve_user_by_email() {
		$this->command->status( array( 'cli_test_user@example.com' ), array() );

		$format = $this->last_format();
		$this->assertSame( $this->user->ID, $format['items'][0]['user_id'] );
	}

	/**
	 * An unknown identifier aborts with a "User not found" error.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_unknown_user_errors() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->status( array( 'nobody-here' ), array() );
			}
		);

		$this->assertStringContainsString( 'User not found: nobody-here', $message );
	}

	/*
	 * ---------------------------------------------------------------------
	 * status
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A user without 2FA reports using_2fa false and no providers.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_status_without_two_factor() {
		$this->command->status( array( 'cli_test_user' ), array() );

		$item = $this->last_format()['items'][0];
		$this->assertSame( 'false', $item['using_2fa'] );
		$this->assertSame( '', $item['enabled_providers'] );
		$this->assertSame( '', $item['primary_provider'] );
		$this->assertSame( 0, $item['backup_codes_remaining'] );
	}

	/**
	 * A user with providers enabled reports them in the status output.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_status_with_providers() {
		$this->enable_provider( 'Two_Factor_Email' );
		$this->enable_provider( 'Two_Factor_Totp' );

		$this->command->status( array( 'cli_test_user' ), array() );

		$item = $this->last_format()['items'][0];
		$this->assertSame( 'true', $item['using_2fa'] );
		$this->assertStringContainsString( 'Two_Factor_Email', $item['enabled_providers'] );
		$this->assertStringContainsString( 'Two_Factor_Totp', $item['enabled_providers'] );
		$this->assertNotEmpty( $item['primary_provider'] );
	}

	/**
	 * The --format flag is passed through to the formatter.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_status_honors_format_flag() {
		$this->command->status( array( 'cli_test_user' ), array( 'format' => 'json' ) );

		$this->assertSame( 'json', $this->last_format()['format'] );
	}

	/**
	 * The backup code count is reported in status output.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_status_reports_backup_code_count() {
		Two_Factor_Backup_Codes::get_instance()->generate_codes(
			$this->user,
			array(
				'number' => 4,
				'method' => 'replace',
			)
		);

		$this->command->status( array( 'cli_test_user' ), array() );

		$this->assertSame( 4, $this->last_format()['items'][0]['backup_codes_remaining'] );
	}

	/**
	 * A user without a TOTP key reports storage none.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_status_reports_totp_storage_none() {
		$this->command->status( array( 'cli_test_user' ), array() );

		$this->assertSame( 'none', $this->last_format()['items'][0]['totp_storage'] );
		$this->assertContains( 'totp_storage', $this->last_format()['fields'] );
	}

	/**
	 * A plaintext TOTP key reports storage plaintext.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_status_reports_totp_storage_plaintext() {
		add_filter( 'two_factor_use_secrets_api', '__return_false' );
		Two_Factor_Totp::get_instance()->set_user_totp_key( $this->user->ID, 'ABCDEFGH' );

		$this->command->status( array( 'cli_test_user' ), array() );

		$this->assertSame( 'plaintext', $this->last_format()['items'][0]['totp_storage'] );
	}

	/**
	 * A Secrets API TOTP key reports storage secrets-api.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_status_reports_totp_storage_secrets_api() {
		if ( ! function_exists( 'wp_get_network_secret' ) ) {
			$this->markTestSkipped( 'Secrets API feature plugin is not loaded.' );
		}
		Two_Factor_Totp::get_instance()->set_user_totp_key( $this->user->ID, 'ABCDEFGH' );

		$this->command->status( array( 'cli_test_user' ), array() );

		$this->assertSame( 'secrets-api', $this->last_format()['items'][0]['totp_storage'] );
	}

	/**
	 * An unreachable key reports storage unavailable.
	 *
	 * @covers Two_Factor_CLI_Command::status
	 */
	public function test_status_reports_totp_storage_unavailable() {
		update_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );
		add_filter( 'two_factor_secrets_api_present', '__return_false' );

		$this->command->status( array( 'cli_test_user' ), array() );

		$this->assertSame( 'unavailable', $this->last_format()['items'][0]['totp_storage'] );
	}

	/**
	 * Secrets status reports a present API and user counts.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_status_reports_present_api_and_counts() {
		if ( ! function_exists( 'wp_get_network_secret' ) ) {
			$this->markTestSkipped( 'Secrets API feature plugin is not loaded.' );
		}
		$plaintext_user = self::factory()->user->create();
		$migrated_user  = self::factory()->user->create();
		$totp           = Two_Factor_Totp::get_instance();

		add_filter( 'two_factor_use_secrets_api', '__return_false' );
		$totp->set_user_totp_key( $plaintext_user, 'ABCDEFGH' );
		remove_filter( 'two_factor_use_secrets_api', '__return_false' );
		$totp->set_user_totp_key( $migrated_user, 'ABCDEFGH' );

		$this->command->secrets( array( 'status' ), array() );

		$item = $this->last_format()['items'][0];
		$this->assertSame( 'true', $item['api_present'] );
		$this->assertSame( 1, $item['plaintext_users'] );
		$this->assertSame( 1, $item['migrated_users'] );
		$this->assertSame( 0, $item['affected_users'] );
		$this->assertSame( array( 'api_present', 'provider', 'writable', 'filter_enabled', 'plaintext_users', 'migrated_users', 'affected_users' ), $this->last_format()['fields'] );
	}

	/**
	 * Secrets status works when TOTP is disabled site-wide.
	 */
	public function test_secrets_status_works_when_totp_disabled_site_wide() {
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Email' ) );

		try {
			$this->command->secrets( array( 'status' ), array() );
		} finally {
			delete_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );
		}

		$this->assertArrayHasKey( 'plaintext_users', $this->last_format()['items'][0] );
	}

	/**
	 * Secrets status reports an absent API.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_status_reports_absent_api() {
		add_filter( 'two_factor_secrets_api_present', '__return_false' );
		update_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_NETWORK_META_KEY, (string) get_current_network_id() );

		$this->command->secrets( array( 'status' ), array() );

		$item = $this->last_format()['items'][0];
		$this->assertSame( 'false', $item['api_present'] );
		$this->assertSame( '', $item['provider'] );
		$this->assertSame( 1, $item['affected_users'] );
	}

	/**
	 * The --format flag is passed through for secrets status.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_status_format_passthrough() {
		$this->command->secrets( array( 'status' ), array( 'format' => 'json' ) );

		$this->assertSame( 'json', $this->last_format()['format'] );
	}

	/**
	 * An unknown secrets action errors.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_unknown_action_errors() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->secrets( array( 'bogus' ), array() );
			}
		);

		$this->assertStringContainsString( 'Unknown action "bogus"', $message );
	}

	/**
	 * A missing secrets action errors.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_missing_action_errors() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->secrets( array(), array() );
			}
		);

		$this->assertStringContainsString( 'Unknown action', $message );
	}

	/**
	 * Seed a plaintext TOTP key directly in user meta.
	 *
	 * @param int $user_id User ID.
	 */
	protected function seed_plaintext_key( $user_id ) {
		update_user_meta( $user_id, Two_Factor_Totp::SECRET_META_KEY, 'ABCDEFGH' );
	}

	/**
	 * Skip unless the Secrets API is loaded.
	 */
	protected function require_secrets_api() {
		if ( ! function_exists( 'wp_get_network_secret' ) ) {
			$this->markTestSkipped( 'Secrets API feature plugin is not loaded.' );
		}
	}

	/**
	 * Reset adapter test seams.
	 */
	public function tear_down() {
		Two_Factor_Secrets::$test_overrides = array();
		parent::tear_down();
	}

	/**
	 * Migrating a single user moves the secret.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_migrate_single_user() {
		$this->require_secrets_api();
		$this->seed_plaintext_key( $this->user->ID );

		$this->command->secrets( array( 'migrate', 'cli_test_user' ), array() );

		$this->assertSame( '', (string) get_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_META_KEY, true ) );
		$this->assertSame( (string) get_current_network_id(), get_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true ) );
		$this->assertSame( 'Migrated 1, failed 0, skipped 0.', $this->last_message( 'success' ) );
	}

	/**
	 * A dry run changes nothing.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_migrate_dry_run_changes_nothing() {
		$this->require_secrets_api();
		$this->seed_plaintext_key( $this->user->ID );

		$this->command->secrets( array( 'migrate' ), array( 'dry-run' => true ) );

		$this->assertSame( 'ABCDEFGH', get_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_META_KEY, true ) );
		$this->assertSame( '', (string) get_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true ) );
		$this->assertStringStartsWith( 'Dry run', $this->last_message( 'success' ) );
	}

	/**
	 * Migration pages through users in batches.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_migrate_pages_through_users_in_batches() {
		$this->require_secrets_api();
		$ids = array( $this->user->ID, self::factory()->user->create(), self::factory()->user->create() );
		foreach ( $ids as $id ) {
			$this->seed_plaintext_key( $id );
		}

		$this->command->secrets( array( 'migrate' ), array( 'batch-size' => '2' ) );

		foreach ( $ids as $id ) {
			$this->assertSame( '', (string) get_user_meta( $id, Two_Factor_Totp::SECRET_META_KEY, true ) );
		}
		$this->assertSame( 'Migrated 3, failed 0, skipped 0.', $this->last_message( 'success' ) );
	}

	/**
	 * A failure for one user does not stop the run.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_migrate_reports_failed_users_and_continues() {
		$this->require_secrets_api();
		$bad = self::factory()->user->create();
		$ids = array( $this->user->ID, $bad, self::factory()->user->create() );
		foreach ( $ids as $id ) {
			$this->seed_plaintext_key( $id );
		}
		Two_Factor_Secrets::$test_overrides['set'] = function ( $name, $value ) use ( $bad ) {
			if ( "two-factor/totp-{$bad}" === $name ) {
				return new WP_Error( 'write_failed', 'write failed' );
			}
			return wp_set_network_secret( $name, $value );
		};

		$this->command->secrets( array( 'migrate' ), array( 'batch-size' => '2' ) );

		$this->assertSame( 'Migrated 2, failed 1, skipped 0.', $this->last_message( 'success' ) );
		$this->assertStringContainsString( "User {$bad}:", $this->last_message( 'warning' ) );
		$this->assertSame( 'ABCDEFGH', get_user_meta( $bad, Two_Factor_Totp::SECRET_META_KEY, true ) );
	}

	/**
	 * Migrating without the API errors.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_migrate_errors_when_api_absent() {
		add_filter( 'two_factor_secrets_api_present', '__return_false' );

		$message = $this->assert_command_aborts(
			function () {
				$this->command->secrets( array( 'migrate' ), array() );
			}
		);

		$this->assertStringContainsString( 'Nothing was migrated', $message );
	}

	/**
	 * Migrating with the filter opted out errors.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_migrate_errors_when_filter_opts_out() {
		add_filter( 'two_factor_use_secrets_api', '__return_false' );

		$message = $this->assert_command_aborts(
			function () {
				$this->command->secrets( array( 'migrate' ), array() );
			}
		);

		$this->assertStringContainsString( 'Nothing was migrated', $message );
	}

	/**
	 * An invalid batch size errors.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_migrate_invalid_batch_size_errors() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->secrets( array( 'migrate' ), array( 'batch-size' => '0' ) );
			}
		);

		$this->assertStringContainsString( 'Invalid value for --batch-size', $message );
	}

	/**
	 * An unknown user errors.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_migrate_user_not_found() {
		$this->require_secrets_api();

		$message = $this->assert_command_aborts(
			function () {
				$this->command->secrets( array( 'migrate', 'nobody-here' ), array() );
			}
		);

		$this->assertStringContainsString( 'User not found: nobody-here', $message );
	}

	/**
	 * Export needs confirmation.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_export_requires_confirmation() {
		$this->require_secrets_api();
		Two_Factor_Totp::get_instance()->set_user_totp_key( $this->user->ID, 'ABCDEFGH' );

		$message = $this->assert_command_aborts(
			function () {
				$this->command->secrets( array( 'export' ), array() );
			}
		);

		$this->assertStringContainsString( 'confirmation declined', $message );
		$this->assertSame( '', (string) get_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_META_KEY, true ) );
		$this->assertSame( (string) get_current_network_id(), get_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true ) );
	}

	/**
	 * Exporting a single user round-trips.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_export_single_user_round_trip() {
		$this->require_secrets_api();
		Two_Factor_Totp::get_instance()->set_user_totp_key( $this->user->ID, 'ABCDEFGH' );
		$other_user = self::factory()->user->create();
		Two_Factor_Totp::get_instance()->set_user_totp_key( $other_user, 'IJKLMNOP' );

		// The user is positional: `--user` is a WP-CLI global parameter and never reaches the command.
		$this->command->secrets( array( 'export', 'cli_test_user' ), array( 'yes' => true ) );

		$this->assertSame( 'ABCDEFGH', get_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_META_KEY, true ) );
		$this->assertSame( '', (string) get_user_meta( $this->user->ID, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true ) );

		// Only the named user is exported.
		$this->assertSame( '', (string) get_user_meta( $other_user, Two_Factor_Totp::SECRET_META_KEY, true ) );
		$this->assertSame( (string) get_current_network_id(), get_user_meta( $other_user, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true ) );

		add_filter( 'two_factor_use_secrets_api', '__return_false' );
		$this->command->status( array( 'cli_test_user' ), array() );
		$this->assertSame( 'plaintext', $this->last_format()['items'][0]['totp_storage'] );
	}

	/**
	 * Exporting all users pages through batches.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_export_all_users_in_batches() {
		$this->require_secrets_api();
		$ids = array( $this->user->ID, self::factory()->user->create(), self::factory()->user->create() );
		foreach ( $ids as $id ) {
			Two_Factor_Totp::get_instance()->set_user_totp_key( $id, 'ABCDEFGH' );
		}

		$this->command->secrets(
			array( 'export' ),
			array(
				'yes'        => true,
				'batch-size' => '2',
			) 
		);

		foreach ( $ids as $id ) {
			$this->assertSame( 'ABCDEFGH', get_user_meta( $id, Two_Factor_Totp::SECRET_META_KEY, true ) );
		}
		$this->assertSame( 'Exported 3, unreadable 0, skipped 0.', $this->last_message( 'success' ) );
	}

	/**
	 * An unreadable secret is skipped with a warning.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_export_skips_unreadable_secret() {
		$this->require_secrets_api();
		$bad = self::factory()->user->create();
		$ids = array( $this->user->ID, $bad, self::factory()->user->create() );
		foreach ( $ids as $id ) {
			Two_Factor_Totp::get_instance()->set_user_totp_key( $id, 'ABCDEFGH' );
		}
		Two_Factor_Secrets::$test_overrides['get'] = function ( $name ) use ( $bad ) {
			if ( "two-factor/totp-{$bad}" === $name ) {
				return new WP_Error( 'secret_decryption_failed' );
			}
			$secret = wp_get_network_secret( $name );
			return $secret ? $secret->reveal() : null;
		};

		$this->command->secrets(
			array( 'export' ),
			array(
				'yes'        => true,
				'batch-size' => '2',
			) 
		);

		$this->assertSame( 'Exported 2, unreadable 1, skipped 0.', $this->last_message( 'success' ) );
		$this->assertStringContainsString( "User {$bad}:", $this->last_message( 'warning' ) );
		$this->assertSame( (string) get_current_network_id(), get_user_meta( $bad, Two_Factor_Totp::SECRET_NETWORK_META_KEY, true ) );
	}

	/**
	 * Exporting without the API errors.
	 *
	 * @covers Two_Factor_CLI_Command::secrets
	 */
	public function test_secrets_export_errors_when_api_absent() {
		add_filter( 'two_factor_secrets_api_present', '__return_false' );

		$message = $this->assert_command_aborts(
			function () {
				$this->command->secrets( array( 'export' ), array( 'yes' => true ) );
			}
		);

		$this->assertStringContainsString( 'nothing to read the secrets from', $message );
	}

	/*
	 * ---------------------------------------------------------------------
	 * list-providers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The command lists the registered providers.
	 *
	 * @covers Two_Factor_CLI_Command::list_providers
	 */
	public function test_list_providers() {
		$this->command->list_providers( array(), array() );

		$format  = $this->last_format();
		$classes = wp_list_pluck( $format['items'], 'class' );

		$this->assertContains( 'Two_Factor_Email', $classes );
		$this->assertContains( 'Two_Factor_Totp', $classes );
		$this->assertContains( 'Two_Factor_Backup_Codes', $classes );

		foreach ( $format['items'] as $item ) {
			$this->assertArrayHasKey( 'label', $item );
			$this->assertNotEmpty( $item['label'] );
		}
	}

	/**
	 * The --format flag is passed through when listing providers.
	 *
	 * @covers Two_Factor_CLI_Command::list_providers
	 */
	public function test_list_providers_honors_format_flag() {
		$this->command->list_providers( array(), array( 'format' => 'json' ) );

		$this->assertSame( 'json', $this->last_format()['format'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * enable
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Enabling a secret-free provider succeeds and shows in status.
	 *
	 * @covers Two_Factor_CLI_Command::enable
	 */
	public function test_enable_email_provider() {
		$this->command->enable( array( 'cli_test_user', 'Two_Factor_Email' ), array() );

		$this->assertStringContainsString( 'enabled', $this->last_message( 'success' ) );
		$this->assertContains( 'Two_Factor_Email', Two_Factor_Core::get_enabled_providers_for_user( $this->user ) );
	}

	/**
	 * Enabling a provider destroys the user's existing sessions.
	 *
	 * @covers Two_Factor_CLI_Command::enable
	 */
	public function test_enable_destroys_sessions() {
		$this->create_user_session();

		$this->command->enable( array( 'cli_test_user', 'Two_Factor_Email' ), array() );

		$this->assertSame( 0, $this->count_user_sessions() );
	}

	/**
	 * Enabling TOTP is refused because it needs a pre-shared secret.
	 *
	 * @covers Two_Factor_CLI_Command::enable
	 */
	public function test_enable_totp_is_refused() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->enable( array( 'cli_test_user', 'Two_Factor_Totp' ), array() );
			}
		);

		$this->assertStringContainsString( 'pre-shared secret', $message );
		// The error must not reference the non-existent "Phase 3" totp subcommand.
		$this->assertStringNotContainsString( 'Phase 3', $message );
		$this->assertNotContains( 'Two_Factor_Totp', Two_Factor_Core::get_enabled_providers_for_user( $this->user ) );
	}

	/**
	 * Enabling backup codes is refused with a pointer to the generate command.
	 *
	 * @covers Two_Factor_CLI_Command::enable
	 */
	public function test_enable_backup_codes_is_refused() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->enable( array( 'cli_test_user', 'Two_Factor_Backup_Codes' ), array() );
			}
		);

		$this->assertStringContainsString( 'backup-codes generate', $message );
	}

	/**
	 * Enabling an unregistered provider aborts with an error.
	 *
	 * @covers Two_Factor_CLI_Command::enable
	 */
	public function test_enable_unknown_provider_errors() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->enable( array( 'cli_test_user', 'Not_A_Real_Provider' ), array() );
			}
		);

		$this->assertStringContainsString( 'registered provider', $message );
	}

	/**
	 * Enable requires a provider argument.
	 *
	 * @covers Two_Factor_CLI_Command::enable
	 */
	public function test_enable_requires_provider_argument() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->enable( array( 'cli_test_user' ), array() );
			}
		);

		$this->assertStringContainsString( 'Usage:', $message );
	}

	/*
	 * ---------------------------------------------------------------------
	 * disable (single provider)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Disabling a single provider leaves the others intact.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_single_provider() {
		$this->enable_provider( 'Two_Factor_Email' );
		$this->enable_provider( 'Two_Factor_Totp' );

		$this->command->disable( array( 'cli_test_user', 'Two_Factor_Totp' ), array( 'yes' => true ) );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user );
		$this->assertNotContains( 'Two_Factor_Totp', $enabled );
		$this->assertContains( 'Two_Factor_Email', $enabled );
		$this->assertStringContainsString( 'disabled', $this->last_message( 'success' ) );
	}

	/**
	 * Disabling TOTP via CLI also removes the stored TOTP secret.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_single_totp_clears_stored_secret() {
		$this->enable_provider( 'Two_Factor_Totp' );
		$totp = Two_Factor_Totp::get_instance();
		$key  = Two_Factor_Totp::generate_key();

		$this->assertNotFalse( $totp->set_user_totp_key( $this->user->ID, $key ) );
		$this->assertSame( $key, $totp->get_user_totp_key( $this->user->ID ) );

		$this->command->disable( array( 'cli_test_user', 'Two_Factor_Totp' ), array( 'yes' => true ) );

		$this->assertSame( '', $totp->get_user_totp_key( $this->user->ID ) );
	}

	/**
	 * Disabling one provider while others remain destroys the user's sessions.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_single_provider_destroys_sessions() {
		$this->enable_provider( 'Two_Factor_Email' );
		$this->enable_provider( 'Two_Factor_Totp' );
		$this->create_user_session();

		$this->command->disable( array( 'cli_test_user', 'Two_Factor_Totp' ), array( 'yes' => true ) );

		$this->assertSame( 0, $this->count_user_sessions() );
	}

	/**
	 * Disabling a provider that is not enabled is a no-op success.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_single_provider_not_enabled() {
		$this->command->disable( array( 'cli_test_user', 'Two_Factor_Totp' ), array( 'yes' => true ) );

		$this->assertStringContainsString( 'no changes made', $this->last_message( 'success' ) );
	}

	/**
	 * Disabling a single provider requires confirmation without --yes.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_single_provider_requires_confirmation() {
		$this->enable_provider( 'Two_Factor_Totp' );

		$this->assert_command_aborts(
			function () {
				$this->command->disable( array( 'cli_test_user', 'Two_Factor_Totp' ), array() );
			}
		);

		// The prompt was shown, and the provider remains enabled because it was declined.
		$this->assertNotEmpty( WP_CLI::get_logs( 'confirm' ), 'Expected a confirmation prompt without --yes.' );
		$this->assertContains( 'Two_Factor_Totp', Two_Factor_Core::get_enabled_providers_for_user( $this->user ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * disable (all)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A full disable clears all providers and residual state.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_all_providers() {
		$this->enable_provider( 'Two_Factor_Email' );
		$this->enable_provider( 'Two_Factor_Totp' );
		Two_Factor_Backup_Codes::get_instance()->generate_codes( $this->user, array( 'method' => 'replace' ) );
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 3 );

		$this->command->disable( array( 'cli_test_user' ), array( 'yes' => true ) );

		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $this->user->ID ) );
		$this->assertEmpty( Two_Factor_Core::get_enabled_providers_for_user( $this->user ) );
		$this->assertEmpty( get_user_meta( $this->user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, true ) );
		$this->assertEmpty( get_user_meta( $this->user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
		$this->assertStringContainsString( 'All 2FA disabled', $this->last_message( 'success' ) );
	}

	/**
	 * A full disable destroys the user's existing sessions.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_all_providers_destroys_sessions() {
		$this->enable_provider( 'Two_Factor_Email' );
		$this->create_user_session();

		$this->command->disable( array( 'cli_test_user' ), array( 'yes' => true ) );

		$this->assertSame( 0, $this->count_user_sessions() );
	}

	/**
	 * A full disable preserves the compromised-password-reset flag and its notice.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_all_providers_preserves_password_reset_flag() {
		$this->enable_provider( 'Two_Factor_Email' );
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true );

		$this->command->disable( array( 'cli_test_user' ), array( 'yes' => true ) );

		$this->assertNotEmpty(
			get_user_meta( $this->user->ID, Two_Factor_Core::USER_PASSWORD_WAS_RESET_KEY, true ),
			'The password-was-reset flag should survive a full 2FA reset.'
		);
	}

	/**
	 * Disabling an already-disabled user is an idempotent no-op.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_all_providers_when_already_disabled() {
		$this->command->disable( array( 'cli_test_user' ), array( 'yes' => true ) );

		$this->assertStringContainsString( 'already disabled', $this->last_message( 'success' ) );
	}

	/**
	 * A full disable clears stale meta even when the provider class no longer exists.
	 *
	 * Guards the fail-closed email fallback: a stale enabled-providers meta value
	 * must not leave email 2FA active after a reset.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_all_providers_clears_stale_meta() {
		update_user_meta(
			$this->user->ID,
			Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY,
			array( 'Some_Removed_Provider_Class' )
		);

		$this->command->disable( array( 'cli_test_user' ), array( 'yes' => true ) );

		$this->assertEmpty( get_user_meta( $this->user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $this->user->ID ) );
		$this->assertStringContainsString( 'All 2FA disabled', $this->last_message( 'success' ) );
	}

	/**
	 * A full disable requires confirmation without --yes.
	 *
	 * @covers Two_Factor_CLI_Command::disable
	 */
	public function test_disable_all_providers_requires_confirmation() {
		$this->enable_provider( 'Two_Factor_Email' );

		$this->assert_command_aborts(
			function () {
				$this->command->disable( array( 'cli_test_user' ), array() );
			}
		);

		$this->assertNotEmpty( WP_CLI::get_logs( 'confirm' ), 'Expected a confirmation prompt without --yes.' );
		$this->assertContains( 'Two_Factor_Email', Two_Factor_Core::get_enabled_providers_for_user( $this->user ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * backup-codes generate
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Generating backup codes prints the default number of codes.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_default_count() {
		$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array() );

		$this->assertSame(
			Two_Factor_Backup_Codes::NUMBER_OF_CODES,
			Two_Factor_Backup_Codes::codes_remaining_for_user( $this->user )
		);
		$this->assertStringContainsString( 'Backup codes generated', $this->last_message( 'success' ) );
	}

	/**
	 * Codes generated through the CLI must be usable at login.
	 *
	 * Generating codes without enabling the provider leaves the user looking
	 * set up in `status` while being rejected at the 2FA step, because the
	 * provider is only offered at login when it is both enabled and configured
	 * (present in get_available_providers_for_user()).
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_enables_provider_for_login() {
		$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array() );

		$this->assertContains(
			'Two_Factor_Backup_Codes',
			Two_Factor_Core::get_enabled_providers_for_user( $this->user ),
			'The backup codes provider should be enabled after generation.'
		);

		$available = Two_Factor_Core::get_available_providers_for_user( $this->user );
		$this->assertArrayHasKey(
			'Two_Factor_Backup_Codes',
			$available,
			'Backup codes generated via the CLI must be usable at login.'
		);
		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $this->user->ID ) );
	}

	/**
	 * Generating backup codes for a user without 2FA destroys their sessions.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_destroys_sessions_when_first_enabled() {
		$this->create_user_session();

		$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array() );

		$this->assertSame( 0, $this->count_user_sessions() );
	}

	/**
	 * The --count flag controls how many codes are generated.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_custom_count() {
		$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array( 'count' => 5 ) );

		$this->assertSame( 5, Two_Factor_Backup_Codes::codes_remaining_for_user( $this->user ) );

		$printed = count( WP_CLI::get_logs( 'log' ) );
		// One header line plus five code lines.
		$this->assertSame( 6, $printed );
	}

	/**
	 * A count lower than 1 is rejected.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_rejects_zero_count() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array( 'count' => 0 ) );
			}
		);

		$this->assertStringContainsString( 'Invalid value for --count', $message );
		$this->assertSame( 0, Two_Factor_Backup_Codes::codes_remaining_for_user( $this->user ) );
	}

	/**
	 * Negative count values are rejected.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_rejects_negative_count() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array( 'count' => -5 ) );
			}
		);

		$this->assertStringContainsString( 'Invalid value for --count', $message );
		$this->assertSame( 0, Two_Factor_Backup_Codes::codes_remaining_for_user( $this->user ) );
	}

	/**
	 * Count values with non-digit suffixes are rejected.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_rejects_non_decimal_count() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array( 'count' => '2garbage' ) );
			}
		);

		$this->assertStringContainsString( 'Invalid value for --count', $message );
		$this->assertSame( 0, Two_Factor_Backup_Codes::codes_remaining_for_user( $this->user ) );
	}

	/**
	 * Count values above the safety cap are rejected.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_rejects_count_above_maximum() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->backup_codes(
					array( 'generate', 'cli_test_user' ),
					array( 'count' => Two_Factor_CLI_Command::BACKUP_CODES_MAX_GENERATE_COUNT + 1 )
				);
			}
		);

		$this->assertStringContainsString( 'Invalid value for --count', $message );
		$this->assertStringContainsString( (string) Two_Factor_CLI_Command::BACKUP_CODES_MAX_GENERATE_COUNT, $message );
		$this->assertSame( 0, Two_Factor_Backup_Codes::codes_remaining_for_user( $this->user ) );
	}

	/**
	 * Regenerating replaces the previous set of codes.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_replaces_existing() {
		$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array( 'count' => 8 ) );
		$this->command->backup_codes(
			array( 'generate', 'cli_test_user' ),
			array(
				'count' => 3,
				'yes'   => true,
			)
		);

		$this->assertSame( 3, Two_Factor_Backup_Codes::codes_remaining_for_user( $this->user ) );
	}

	/**
	 * Regenerating existing backup codes requires confirmation without --yes.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_generate_replacing_existing_requires_confirmation() {
		$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array( 'count' => 8 ) );

		$this->assert_command_aborts(
			function () {
				$this->command->backup_codes( array( 'generate', 'cli_test_user' ), array( 'count' => 3 ) );
			}
		);

		$this->assertNotEmpty( WP_CLI::get_logs( 'confirm' ), 'Expected a confirmation prompt without --yes.' );
		$this->assertSame( 8, Two_Factor_Backup_Codes::codes_remaining_for_user( $this->user ) );
	}

	/**
	 * An unknown backup-codes action aborts with an error.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_unknown_action_errors() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->backup_codes( array( 'destroy', 'cli_test_user' ), array() );
			}
		);

		$this->assertStringContainsString( 'Unknown action', $message );
	}

	/**
	 * The backup-codes generate action requires a user argument.
	 *
	 * @covers Two_Factor_CLI_Command::backup_codes
	 */
	public function test_backup_codes_requires_user_argument() {
		$message = $this->assert_command_aborts(
			function () {
				$this->command->backup_codes( array( 'generate' ), array() );
			}
		);

		$this->assertStringContainsString( 'Usage:', $message );
	}

	/*
	 * ---------------------------------------------------------------------
	 * unlock
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Unlocking a rate-limited user clears the throttle.
	 *
	 * @covers Two_Factor_CLI_Command::unlock
	 */
	public function test_unlock_rate_limited_user() {
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );
		update_user_meta( $this->user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 5 );
		$this->assertTrue( Two_Factor_Core::is_user_rate_limited( $this->user ) );

		$this->command->unlock( array( 'cli_test_user' ), array() );

		$this->assertFalse( Two_Factor_Core::is_user_rate_limited( $this->user ) );
		$this->assertEmpty( get_user_meta( $this->user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, true ) );
		$this->assertStringContainsString( 'Login throttle cleared', $this->last_message( 'success' ) );
	}

	/**
	 * Unlocking a user who is not rate-limited reports no changes.
	 *
	 * @covers Two_Factor_CLI_Command::unlock
	 */
	public function test_unlock_not_rate_limited_user() {
		$this->command->unlock( array( 'cli_test_user' ), array() );

		$this->assertStringContainsString( 'was not rate-limited', $this->last_message( 'success' ) );
	}
}
