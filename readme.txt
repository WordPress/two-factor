=== Two Factor ===
Contributors: georgestephanis, valendesigns, stevenkword, extendwings, sgrant, aaroncampbell, johnbillion, stevegrunwell, netweb, kasparsd, alihusnainarshad, passoniate
Tags:         2fa, mfa, totp, authentication, security
Tested up to: 6.9
Stable tag:   0.15.0
License:      GPL-2.0-or-later
License URI:  https://spdx.org/licenses/GPL-2.0-or-later.html

Enable Two-Factor Authentication (2FA) using time-based one-time passwords (TOTP), Universal 2nd Factor (U2F), email, and backup verification codes.

== Description ==

The Two-Factor plugin adds an extra layer of security to your WordPress login by requiring users to provide a second form of authentication in addition to their password.  This helps protect against unauthorized access even if passwords are compromised.

## Setup Instructions

**Important**: Each user must individually configure their two-factor authentication settings.  There are no site-wide settings for this plugin.

### For Individual Users

1. **Navigate to your profile**: Go to "Users" → "Your Profile" in the WordPress admin
2. **Find Two-Factor Options**: Scroll down to the "Two-Factor Options" section
3. **Choose your methods**: Enable one or more authentication providers (noting a site admin may have hidden one or more so what is available could vary):
   - **Authenticator App (TOTP)** - Use apps like Google Authenticator, Authy, or 1Password
   - **Email Codes** - Receive one-time codes via email
   - **FIDO U2F Security Keys** - Use physical security keys (requires HTTPS)
   - **Backup Codes** - Generate one-time backup codes for emergencies
   - **Dummy Method** - For testing purposes only (requires WP_DEBUG)
4. **Configure each method**: Follow the setup instructions for each enabled provider
5. **Set primary method**: Choose which method to use as your default authentication
6. **Save changes**: Click "Update Profile" to save your settings

### For Site Administrators

- **No global settings**: This plugin operates on a per-user basis only. For more, see [GH#249](https://github.com/WordPress/two-factor/issues/249).
- **User management**: Administrators can configure 2FA for other users by editing their profiles
- **Security recommendations**: Encourage users to enable backup methods to prevent account lockouts

## Available Authentication Methods

### Authenticator App (TOTP) - Recommended
- **Security**: High - Time-based one-time passwords
- **Setup**: Scan QR code with authenticator app
- **Compatibility**: Works with Google Authenticator, Authy, 1Password, and other TOTP apps
- **Best for**: Most users, provides excellent security with good usability

### Backup Codes - Recommended
- **Security**: Medium - One-time use codes
- **Setup**: Generate 10 backup codes for emergency access
- **Compatibility**: Works everywhere, no special hardware needed
- **Best for**: Emergency access when other methods are unavailable

### Email Codes
- **Security**: Medium - One-time codes sent via email
- **Setup**: Automatic - uses your WordPress email address
- **Compatibility**: Works with any email-capable device
- **Best for**: Users who prefer email-based authentication

### FIDO U2F Security Keys
- **Security**: High - Hardware-based authentication
- **Setup**: Register physical security keys (USB, NFC, or Bluetooth)
- **Requirements**: HTTPS connection required, compatible browser needed
- **Browser Support**: Chrome, Firefox, Edge (varies by key type)
- **Best for**: Users with security keys who want maximum security

### Dummy Method
- **Security**: None - Always succeeds
- **Setup**: Only available when WP_DEBUG is enabled
- **Purpose**: Testing and development only
- **Best for**: Developers testing the plugin

## Important Notes

### HTTPS Requirement
- FIDO U2F Security Keys require an HTTPS connection to function
- Other methods work on both HTTP and HTTPS sites

### Browser Compatibility
- FIDO U2F requires a compatible browser and may not work on all devices
- TOTP and email methods work on all devices and browsers

### Account Recovery
- Always enable backup codes to prevent being locked out of your account
- If you lose access to all authentication methods, contact your site administrator

### Security Best Practices
- Use multiple authentication methods when possible
- Keep backup codes in a secure location
- Regularly review and update your authentication settings

For more information about two-factor authentication in WordPress, see the [WordPress Advanced Administration Security Guide](https://developer.wordpress.org/advanced-administration/security/mfa/).

For more history, see [this post](https://georgestephanis.wordpress.com/2013/08/14/two-cents-on-two-factor/).

= Actions & Filters =

Here is a list of action and filter hooks provided by the plugin:

- `two_factor_providers` filter overrides the available two-factor providers such as email and time-based one-time passwords. Array values are PHP classnames of the two-factor providers.
- `two_factor_providers_for_user` filter overrides the available two-factor providers for a specific user. Array values are instances of provider classes and the user object `WP_User` is available as the second argument.
- `two_factor_enabled_providers_for_user` filter overrides the list of two-factor providers enabled for a user. First argument is an array of enabled provider classnames as values, the second argument is the user ID.
- `two_factor_user_authenticated` action which receives the logged in `WP_User` object as the first argument for determining the logged in user right after the authentication workflow.
- `two_factor_user_api_login_enable` filter restricts authentication for REST API and XML-RPC to application passwords only. Provides the user ID as the second argument.
- `two_factor_email_token_ttl` filter overrides the time interval in seconds that an email token is considered after generation. Accepts the time in seconds as the first argument and the ID of the `WP_User` object being authenticated.
- `two_factor_email_token_length` filter overrides the default 8 character count for email tokens.
- `two_factor_backup_code_length` filter overrides the default 8 character count for backup codes. Provides the `WP_User` of the associated user as the second argument.
- `two_factor_rest_api_can_edit_user` filter overrides whether a user’s Two-Factor settings can be edited via the REST API. First argument is the current `$can_edit` boolean, the second argument is the user ID.
- `two_factor_before_authentication_prompt` action which receives the provider object and fires prior to the prompt shown on the authentication input form.
- `two_factor_after_authentication_prompt` action which receives the provider object and fires after the prompt shown on the authentication input form.
- `two_factor_after_authentication_input`action which receives the provider object and fires after the input shown on the authentication input form (if form contains no input, action fires immediately after `two_factor_after_authentication_prompt`).

== Frequently Asked Questions ==

= What PHP and WordPress versions does the Two-Factor plugin support? =

This plugin supports the last two major versions of WordPress and <a href="https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/">the minimum PHP version</a> supported by those WordPress versions.

= How can I send feedback or get help with a bug? =

The best place to report bugs, feature suggestions, or any other (non-security) feedback is at <a href="https://github.com/WordPress/two-factor/issues">the Two Factor GitHub issues page</a>. Before submitting a new issue, please search the existing issues to check if someone else has reported the same feedback.

= Where can I report security bugs? =

The plugin contributors and WordPress community take security bugs seriously. We appreciate your efforts to responsibly disclose your findings, and will make every effort to acknowledge your contributions.

To report a security issue, please visit the [WordPress HackerOne](https://hackerone.com/wordpress) program.

= Why doesn't this plugin have site-wide settings? =

This plugin is designed to work on a per-user basis, allowing each user to choose their preferred authentication methods. This approach provides maximum flexibility and security. Site administrators can still configure 2FA for other users by editing their profiles. For more information, see [issue #437](https://github.com/WordPress/two-factor/issues/437).

= What if I lose access to all my authentication methods? =

If you have backup codes enabled, you can use one of those to regain access. If you don't have backup codes or have used them all, you'll need to contact your site administrator to reset your account. This is why it's important to always enable backup codes and keep them in a secure location.

= Can I use this plugin with WebAuthn? =

The plugin currently supports FIDO U2F, which is the predecessor to WebAuthn. For full WebAuthn support, you may want to look into additional plugins that extend this functionality. The current U2F implementation requires HTTPS and has browser compatibility limitations.

= Is there a recommended way to use passkeys or hardware security keys with Two-Factor? =

Yes. For passkeys and hardware security keys, you can install the Two-Factor Provider: WebAuthn plugin: https://wordpress.org/plugins/two-factor-provider-webauthn/
. It integrates directly with Two-Factor and adds WebAuthn-based authentication as an additional two-factor option for users.

== Screenshots ==

1. Two-factor options under User Profile - Shows the main configuration area where users can enable different authentication methods.
2. U2F Security Keys section under User Profile - Displays the security key management interface for registering and managing FIDO U2F devices.
3. Email Code Authentication during WordPress Login - Shows the email verification screen that appears during login.
4. Authenticator App (TOTP) setup with QR code - Demonstrates the QR code generation and manual key entry for TOTP setup.
5. Backup codes generation and management - Shows the backup codes interface for generating and managing emergency access codes.

== Changelog ==

= 0.15.0 - 2026-02-13 =

* **Breaking Changes:** Trigger two-factor flow only when expected by @kasparsd in [#660](https://github.com/WordPress/two-factor/pull/660) and [#793](https://github.com/WordPress/two-factor/pull/793).
* **New Features:** Include user IP address and contextual warning in two-factor code emails by @todeveni in [#728](https://github.com/WordPress/two-factor/pull/728)
* **New Features:** Optimize email text for TOTP by @masteradhoc in [#789](https://github.com/WordPress/two-factor/pull/789)
* **New Features:** Add "Settings" action link to plugin list for quick access to profile by @hardikRathi in [#740](https://github.com/WordPress/two-factor/pull/740)
* **New Features:** Additional form hooks by @eric-michel in [#742](https://github.com/WordPress/two-factor/pull/742)
* **New Features:** Full RFC6238 Compatibility by @ericmann in [#656](https://github.com/WordPress/two-factor/pull/656)
* **New Features:** Consistent user experience for TOTP setup by @kasparsd in [#792](https://github.com/WordPress/two-factor/pull/792)
* **Documentation:** `@since` docs by @masteradhoc in [#781](https://github.com/WordPress/two-factor/pull/781)
* **Documentation:** Update user and admin docs, prepare for more screenshots by @jeffpaul in [#701](https://github.com/WordPress/two-factor/pull/701)
* **Documentation:** Add changelog & credits, update release notes by @jeffpaul in [#696](https://github.com/WordPress/two-factor/pull/696)
* **Documentation:** Clear readme.txt by @masteradhoc in [#785](https://github.com/WordPress/two-factor/pull/785)
* **Documentation:** Add date and time information above TOTP setup instructions by @masteradhoc in [#772](https://github.com/WordPress/two-factor/pull/772)
* **Documentation:** Clarify TOTP setup instructions by @masteradhoc in [#763](https://github.com/WordPress/two-factor/pull/763)
* **Documentation:** Update RELEASING.md by @jeffpaul in [#787](https://github.com/WordPress/two-factor/pull/787)
* **Development Updates:** Pause deploys to SVN trunk for merges to `master` by @kasparsd in [#738](https://github.com/WordPress/two-factor/pull/738)
* **Development Updates:** Fix CI checks for PHP compatability by @kasparsd in [#739](https://github.com/WordPress/two-factor/pull/739)
* **Development Updates:** Fix Playground refs by @kasparsd in [#744](https://github.com/WordPress/two-factor/pull/744)
* **Development Updates:** Persist existing translations when introducing new helper text in emails by @kasparsd in [#745](https://github.com/WordPress/two-factor/pull/745)
* **Development Updates:** Fix `missing_direct_file_access_protection` by @masteradhoc in [#760](https://github.com/WordPress/two-factor/pull/760)
* **Development Updates:** Fix `mismatched_plugin_name` by @masteradhoc in [#754](https://github.com/WordPress/two-factor/pull/754)
* **Development Updates:** Introduce Props Bot workflow by @jeffpaul in [#749](https://github.com/WordPress/two-factor/pull/749)
* **Development Updates:** Plugin Check: Fix Missing $domain parameter by @masteradhoc in [#753](https://github.com/WordPress/two-factor/pull/753)
* **Development Updates:** Tests: Update to supported WP version 6.8 by @masteradhoc in [#770](https://github.com/WordPress/two-factor/pull/770)
* **Development Updates:** Fix PHP 8.5 deprecated message by @masteradhoc in [#762](https://github.com/WordPress/two-factor/pull/762)
* **Development Updates:** Exclude 7.2 and 7.3 checks against trunk by @masteradhoc in [#769](https://github.com/WordPress/two-factor/pull/769)
* **Development Updates:** Fix Plugin Check errors: `MissingTranslatorsComment` & `MissingSingularPlaceholder` by @masteradhoc in [#758](https://github.com/WordPress/two-factor/pull/758)
* **Development Updates:** Add PHP 8.5 tests for latest and trunk version of WP by @masteradhoc in [#771](https://github.com/WordPress/two-factor/pull/771)
* **Development Updates:** Add `phpcs:ignore` for falsepositives by @masteradhoc in [#777](https://github.com/WordPress/two-factor/pull/777)
* **Development Updates:** Fix(totp): `otpauth` link in QR code URL by @sjinks in [#784](https://github.com/WordPress/two-factor/pull/784)
* **Development Updates:** Update deploy.yml by @masteradhoc in [#773](https://github.com/WordPress/two-factor/pull/773)
* **Development Updates:** Update required WordPress Version by @masteradhoc in [#765](https://github.com/WordPress/two-factor/pull/765)
* **Development Updates:** Fix: ensure execution stops after redirects by @sjinks in [#786](https://github.com/WordPress/two-factor/pull/786)
* **Development Updates:** Fix `WordPress.Security.EscapeOutput.OutputNotEscaped` errors by @masteradhoc in [#776](https://github.com/WordPress/two-factor/pull/776)
* **Dependency Updates:** Bump qs and express by @dependabot[bot] in [#746](https://github.com/WordPress/two-factor/pull/746)
* **Dependency Updates:** Bump lodash from 4.17.21 to 4.17.23 by @dependabot[bot] in [#750](https://github.com/WordPress/two-factor/pull/750)
* **Dependency Updates:** Bump lodash-es from 4.17.21 to 4.17.23 by @dependabot[bot] in [#748](https://github.com/WordPress/two-factor/pull/748)
* **Dependency Updates:** Bump phpunit/phpunit from 8.5.44 to 8.5.52 by @dependabot[bot] in [#755](https://github.com/WordPress/two-factor/pull/755)
* **Dependency Updates:** Bump symfony/process from 5.4.47 to 5.4.51 by @dependabot[bot] in [#756](https://github.com/WordPress/two-factor/pull/756)
* **Dependency Updates:** Bump qs and body-parser by @dependabot[bot] in [#782](https://github.com/WordPress/two-factor/pull/782)
* **Dependency Updates:** Bump webpack from 5.101.3 to 5.105.0 by @dependabot[bot] in [#780](https://github.com/WordPress/two-factor/pull/780)

= 0.14.2 - 2025-12-11 =

* **New Features:** Add filter for rest_api_can_edit_user_and_update_two_factor_options by @gutobenn in [#689](https://github.com/WordPress/two-factor/pull/689)
* **Development Updates:** Remove Coveralls tooling and add inline coverage report by @kasparsd in [#717](https://github.com/WordPress/two-factor/pull/717)
* **Development Updates:** Update blueprint path to pull from main branch instead of a deleted f… by @georgestephanis in [#719](https://github.com/WordPress/two-factor/pull/719)
* **Development Updates:** Fix blueprint and wporg asset deploys by @kasparsd in [#734](https://github.com/WordPress/two-factor/pull/734)
* **Development Updates:** Upload release only on tag releases by @kasparsd in [#735](https://github.com/WordPress/two-factor/pull/735)
* **Development Updates:** Bump playwright and @playwright/test by @dependabot[bot] in [#721](https://github.com/WordPress/two-factor/pull/721)
* **Development Updates:** Bump tar-fs from 3.1.0 to 3.1.1 by @dependabot[bot] in [#720](https://github.com/WordPress/two-factor/pull/720)
* **Development Updates:** Bump node-forge from 1.3.1 to 1.3.2 by @dependabot[bot] in [#724](https://github.com/WordPress/two-factor/pull/724)
* **Development Updates:** Bump js-yaml by @dependabot[bot] in [#725](https://github.com/WordPress/two-factor/pull/725)
* **Development Updates:** Mark as tested with the latest WP core version by @kasparsd in [#730](https://github.com/WordPress/two-factor/pull/730)

= 0.14.1 - 2025-09-05 =

- Don't URI encode the TOTP url for display. by @dd32 in [#711](https://github.com/WordPress/two-factor/pull/711)
- Removed the duplicate Security.md by @slvignesh05 in [#712](https://github.com/WordPress/two-factor/pull/712)
- Fixed linting issues by @sudar in [#707](https://github.com/WordPress/two-factor/pull/707)
- Update development dependencies and fix failing QR unit test by @kasparsd in [#714](https://github.com/WordPress/two-factor/pull/714)
- Trigger checkbox js change event by @gedeminas in [#688](https://github.com/WordPress/two-factor/pull/688)

= 0.14.0 - 2025-07-03 =

* **Features:** Enable Application Passwords for REST API and XML-RPC authentication (by default) by @joostdekeijzer in [#697](https://github.com/WordPress/two-factor/pull/697) and [#698](https://github.com/WordPress/two-factor/pull/698). Previously this required two_factor_user_api_login_enable filter to be set to true which is now the default during application password auth. XML-RPC login is still disabled for regular user passwords.
* **Features:** Label recommended methods to simplify the configuration by @kasparsd in [#676](https://github.com/WordPress/two-factor/pull/676) and [#675](https://github.com/WordPress/two-factor/pull/675)
* **Documentation:** Add WP.org plugin demo by @kasparsd in [#667](https://github.com/WordPress/two-factor/pull/667)
* **Documentation:** Document supported versions of WP core and PHP by @jeffpaul in [#695](https://github.com/WordPress/two-factor/pull/695)
* **Documentation:** Document the release process by @jeffpaul in [#684](https://github.com/WordPress/two-factor/pull/684)
* **Tooling:** Remove duplicate WP.org screenshots and graphics from SVN trunk by @jeffpaul in [#683](https://github.com/WordPress/two-factor/pull/683)

= 0.13.0 - 2025-04-02 =

- Add two_factor_providers_for_user filter to limit two-factor providers available to each user by @kasparsd in [#669](https://github.com/WordPress/two-factor/pull/669)
- Update automated testing to cover PHP 8.4 and default to PHP 8.3 by @BrookeDot in [#665](https://github.com/WordPress/two-factor/pull/665)

[View the complete changelog details here](https://github.com/wordpress/two-factor/blob/master/CHANGELOG.md).

== Upgrade Notice ==

= 0.10.0 =
Bumps WordPress minimum supported version to 6.3 and PHP minimum to 7.2.

= 0.9.0 =
Users are now asked to re-authenticate with their two-factor before making changes to their two-factor settings. This associates each login session with the two-factor login meta data for improved handling of that session.

