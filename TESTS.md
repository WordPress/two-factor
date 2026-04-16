# Tests

The test suite uses PHPUnit and runs inside the Docker-based `@wordpress/env` environment against a live WordPress install. The `npm run composer` script is a wrapper that executes `composer` inside the `tests-cli` container at the plugin path.

## Running Tests

```bash
# Full test suite
npm test

# Watch mode (re-runs on file changes, no coverage)
npm run test:watch

# Full test suite with coverage (requires xdebug-enabled env)
npm run env start -- --xdebug=coverage
npm test
```

Coverage reports are written to `tests/logs/clover.xml` and `tests/logs/html/`. Open `tests/logs/html/index.html` in a browser to view the HTML report.

### Filtering

Pass PHPUnit arguments through the `composer` wrapper:

```bash
# Run a single test class
npm run composer -- test -- --filter Tests_Two_Factor_Core

# Run a single test method
npm run composer -- test -- --filter test_create_login_nonce

# Run by @group annotation
npm run composer -- test -- --group totp
npm run composer -- test -- --group email
npm run composer -- test -- --group backup-codes
npm run composer -- test -- --group providers
npm run composer -- test -- --group core

# Run a single file
npm run composer -- test -- tests/providers/class-two-factor-totp.php
```

## Test Files

### Plugin Bootstrap — `tests/two-factor.php`

**Class:** `Tests_Two_Factor`
Smoke tests that the plugin loaded correctly: the `TWO_FACTOR_DIR` constant is defined and the core classes exist.

### Core — `tests/class-two-factor-core.php`

**Class:** `Tests_Two_Factor_Core` · **Group:** `core`
The largest test file. Covers the full authentication lifecycle managed by `Two_Factor_Core`:

- Hook registration (`add_hooks`)
- Provider registration and retrieval (`get_providers`, `get_enabled_providers_for_user`, `get_available_providers_for_user`, `get_primary_provider_for_user`)
- Login interception (`filter_authenticate`, `show_two_factor_login`, `process_provider`)
- Login nonce creation, verification, and deletion
- Rate limiting (`get_user_time_delay`, `is_user_rate_limited`)
- Session management: two-factor factored vs. non-factored sessions, session destruction on 2FA enable/disable, revalidation
- Password reset flow (compromise detection, email notifications, reset notices)
- REST API permission callbacks (`rest_api_can_edit_user_and_update_two_factor_options`)
- User settings actions (`trigger_user_settings_action`, `current_user_can_update_two_factor_options`)
- Uninstall cleanup
- Filter hooks (`two_factor_providers`, `two_factor_primary_provider_for_user`, `two_factor_user_api_login_enable`)

### Provider Base Class — `tests/providers/class-two-factor-provider.php`

**Class:** `Tests_Two_Factor_Provider` · **Group:** `providers`
Tests the abstract `Two_Factor_Provider` base class:

- Singleton pattern (`get_instance`)
- Code generation (`get_code`) and request sanitization (`sanitize_code_from_request`)
- `get_key` returning the class name
- `is_supported_for_user` (globally registered vs. not)
- Default implementations of `get_alternative_provider_label`, `pre_process_authentication`, `uninstall_user_meta_keys`, `uninstall_options`

### TOTP Provider — `tests/providers/class-two-factor-totp.php`

**Class:** `Tests_Two_Factor_Totp` · **Groups:** `providers`, `totp`
Tests `Two_Factor_Totp`:

- Base32 encode/decode (including invalid input exception)
- QR code URL generation
- TOTP key storage and retrieval per user
- Auth code validation (current tick, spaces stripped, invalid chars rejected)
- `validate_code_for_user` replay protection
- Algorithm variants: SHA1, SHA256, SHA512 (code generation and authentication)
- Secret padding (`pad_secret`)

### TOTP REST API — `tests/providers/class-two-factor-totp-rest-api.php`

**Class:** `Tests_Two_Factor_Totp_REST_API` · **Groups:** `providers`, `totp`
Extends `WP_Test_REST_TestCase`. Tests the TOTP REST endpoints:

- Setting a TOTP key with a valid/invalid/missing auth code
- Updating an existing TOTP key
- Deleting own secret
- Admin deleting another user's secret
- Non-admin cannot delete another user's secret

### Email Provider — `tests/providers/class-two-factor-email.php`

**Class:** `Tests_Two_Factor_Email` · **Groups:** `providers`, `email`
Tests `Two_Factor_Email`:

- Token generation and validation (same user, different user, deleted token)
- Email delivery (`generate_and_email_token`)
- Authentication page rendering (no user, no token, existing token)
- `validate_authentication` (valid, missing input, spaces stripped)
- Token TTL and expiry
- Token generation time tracking
- Custom token length filter
- `pre_process_authentication` (resend vs. no resend)
- User options UI output
- Uninstall meta key cleanup

### Backup Codes Provider — `tests/providers/class-two-factor-backup-codes.php`

**Class:** `Tests_Two_Factor_Backup_Codes` · **Groups:** `providers`, `backup-codes`
Tests `Two_Factor_Backup_Codes`:

- Code generation and validation
- Replay prevention (code invalidated after use)
- Cross-user isolation (code invalid for different user)
- `is_available_for_user` (no codes vs. codes generated)
- User options UI output
- Code deletion
- `two_factor_backup_code_length` filter for customizing code length

### Backup Codes REST API — `tests/providers/class-two-factor-backup-codes-rest-api.php`

**Class:** `Tests_Two_Factor_Backup_Codes_REST_API` · **Groups:** `providers`, `backup-codes`
Extends `WP_Test_REST_TestCase`. Tests the backup codes REST endpoints:

- Generate codes and validate the downloadable file contents
- User cannot generate codes for a different user
- Admin can generate codes for other users

### Dummy Provider — `tests/providers/class-two-factor-dummy.php`

**Class:** `Tests_Two_Factor_Dummy` · **Groups:** `providers`, `dummy`
Tests the `Two_Factor_Dummy` provider (always passes authentication — used as a test fixture):

- `get_instance`, `get_label`, `authentication_page`, `validate_authentication`, `is_available_for_user`

### Dummy Secure Provider — `tests/providers/class-two-factor-dummy-secure.php`

**Class:** `Tests_Two_Factor_Dummy_Secure` · **Groups:** `providers`, `dummy`
Tests `Two_Factor_Dummy_Secure` (a fixture that always _fails_ authentication, used to test the provider class name filter):

- `get_key` override returns `Two_Factor_Dummy`
- Authentication page rendering
- `validate_authentication` always returns false
- `two_factor_provider_classname` filter

## Test Helpers

- **`tests/bootstrap.php`** — Locates the WordPress test library (via `WP_TESTS_DIR` env var, relative path, or `/tmp/wordpress-tests-lib`), loads the plugin via `muplugins_loaded`, then boots the WP test environment.
- **`tests/class-two-factor-dummy-secure.php`** — Defines `Two_Factor_Dummy_Secure`, a test-only provider class that spoofs the key of `Two_Factor_Dummy` but always fails `validate_authentication`. Used by `Tests_Two_Factor_Dummy_Secure` and some core tests.
