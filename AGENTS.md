# AI Instructions

Two-Factor is a WordPress plugin, potentially eventually merging into WordPress Core, that provides Multi-Factor Authentication for WordPress interactive logins. It is network-enabled and can be activated across a WordPress multisite network.

## Development Environment

Requires Docker. Uses `@wordpress/env` to run a local WordPress install in containers.

```bash
npm install
npm run build
npm run env start
```

For code coverage support: `npm run env start -- --xdebug=coverage`

`npm test` and `npm run composer` are wrappers that execute commands inside the `tests-cli` wp-env container at the plugin path. Tests must be run through these wrappers, not directly with `phpunit`.

## Commands

### Testing

@TESTS.md

### Linting & Static Analysis

```bash
npm run lint            # all linters (PHP, CSS, JS)
npm run lint:php        # PHPCS with WordPress + VIP-Go standards
npm run lint:phpstan    # PHPStan static analysis (level 0)
npm run lint:css        # wp-scripts lint-style
npm run lint:js         # wp-scripts lint-js
npm run format          # auto-fix PHPCS and JS/CSS issues
```

### Build

```bash
npm run build
```

The Grunt build copies all distributable files to `dist/` (respecting `.distignore`) and copies `node_modules/qrcode-generator/qrcode.js` into `dist/includes/`. The `qrcode-generator` package is a **runtime JS dependency** — it is not present in `includes/` in the source tree and must be built before the plugin is usable in a browser context. Always run `npm run build` after a fresh checkout.

## Architecture

The plugin follows a provider pattern. `Two_Factor_Core` owns the login interception and orchestration; individual providers handle their own credential prompts and validation.

### Core Files

- **`two-factor.php`** — Entry point. Defines `TWO_FACTOR_DIR` and `TWO_FACTOR_VERSION`, loads all core files, instantiates `Two_Factor_Compat`, and calls `Two_Factor_Core::add_hooks()`.
- **`class-two-factor-core.php`** — Central class. Owns the login flow, user meta, nonce management, rate limiting, session tracking, REST API endpoints, and the user profile settings UI.
- **`class-two-factor-compat.php`** — Compatibility shims for third-party plugins (currently: Jetpack SSO). New integrations go here; the goal is to avoid any plugin-specific logic outside this file.
- **`providers/class-two-factor-provider.php`** — Abstract base class all providers extend. Defines the required interface: `get_label()`, `is_available_for_user()`, `authentication_page()`, `validate_authentication()`, and optional hooks for REST routes, settings UI, and uninstall cleanup.
- **`providers/`** — Concrete providers: `class-two-factor-totp.php`, `class-two-factor-email.php`, `class-two-factor-backup-codes.php`, `class-two-factor-dummy.php`.
- **`includes/`** — Custom `login_header()` and `login_footer()` template functions that replace the WordPress core versions with additional filter hooks. Excluded from PHPCS because they intentionally deviate from core function signatures.
- **`tests/`** — PHPUnit tests. See [TESTS.md](TESTS.md).

### Login Flow

1. User submits username/password.
2. `Two_Factor_Core::filter_authenticate()` runs at priority **31** on the `authenticate` filter (one above WP core's 30). If 2FA is required, it intercepts the `WP_User` object to prevent WP from issuing auth cookies.
3. `Two_Factor_Core::wp_login()` runs at priority `PHP_INT_MAX` on `wp_login`, renders the 2FA prompt, and exits.
4. On 2FA form submission, `login_form_validate_2fa` action handles validation and issues the final auth cookie only if the second factor passes.

Auth cookies set during the password phase are tracked via `collect_auth_cookie_tokens` and invalidated before the 2FA step.

### Provider Registration

Providers are registered via the `two_factor_providers` filter, which receives and returns an array of the form:

```php
array( 'Class_Name' => '/absolute/path/to/class-file.php' )
```

The key (class name) is what gets stored in user meta. A per-provider `two_factor_provider_classname_{$provider_key}` filter allows swapping a provider's implementing class without changing its key. Use `two_factor_providers_for_user` to control which providers are available to a specific user.

**The `Two_Factor_Dummy` provider is only available when `WP_DEBUG` is `true`.** It is removed at runtime by `enable_dummy_method_for_debug()` in all other environments. If a dummy provider isn't appearing, check `WP_DEBUG`.

### Provider Self-Registration Pattern

Each concrete provider registers its own hooks in its constructor:

- REST routes → `rest_api_init`
- Assets → `admin_enqueue_scripts`, `wp_enqueue_scripts`
- User profile UI section → `two_factor_user_options_{ClassName}` action

New providers should follow this pattern rather than registering hooks from outside the class.

### Key User Meta (constants on `Two_Factor_Core`)

| Constant | Meta Key | Purpose |
|---|---|---|
| `PROVIDER_USER_META_KEY` | `_two_factor_provider` | Active provider class name |
| `ENABLED_PROVIDERS_USER_META_KEY` | `_two_factor_enabled_providers` | Array of enabled provider class names |
| `USER_META_NONCE_KEY` | `_two_factor_nonce` | Login nonce |
| `USER_RATE_LIMIT_KEY` | `_two_factor_last_login_failure` | Rate limiting timestamp |
| `USER_FAILED_LOGIN_ATTEMPTS_KEY` | `_two_factor_failed_login_attempts` | Failed attempt count |
| `USER_PASSWORD_WAS_RESET_KEY` | `_two_factor_password_was_reset` | Flags compromised-password reset |

### REST API

Namespace: `two-factor/1.0` (constant `Two_Factor_Core::REST_NAMESPACE`). Each provider that exposes REST endpoints registers its own routes in `register_rest_routes()` called from its constructor.

## Code Standards

- PHP 7.2+ compatibility required; enforced by PHPCompatibilityWP.
- Follows WordPress coding standards (WPCS) and WordPress-VIP-Go rules.
- `includes/` is excluded from PHPCS — those files intentionally override core functions.
- The codebase does not fully pass all PHPCS checks (known issue [#437](https://github.com/WordPress/two-factor/issues/437)). Do not treat existing violations as license to introduce new ones.
