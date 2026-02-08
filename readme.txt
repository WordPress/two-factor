=== Two-Factor ===
Contributors: georgestephanis, valendesigns, stevenkword, extendwings, sgrant, aaroncampbell, johnbillion, stevegrunwell, netweb, kasparsd, alihusnainarshad, passoniate
Tags:         2fa, mfa, totp, authentication, security
Tested up to: 6.7
Stable tag:   0.13.0
License:      GPL-2.0-or-later
License URI:  https://spdx.org/licenses/GPL-2.0-or-later.html

Enable Two-Factor Authentication (2FA) using time-based one-time passwords (TOTP), Universal 2nd Factor (U2F), email, and backup verification codes.

== Description ==

Use the "Two-Factor Options" section under "Users" → "Your Profile" to enable and configure one or multiple two-factor authentication providers for your account:

- Email codes
- Time Based One-Time Passwords (TOTP)
- FIDO Universal 2nd Factor (U2F)
- Backup Codes
- Dummy Method (only for testing purposes)

For more history, see [this post](https://georgestephanis.wordpress.com/2013/08/14/two-cents-on-two-factor/).

= Actions & Filters =

Here is a list of action and filter hooks provided by the plugin:

- `two_factor_providers` filter overrides the available two-factor providers such as email and time-based one-time passwords. Array values are PHP classnames of the two-factor providers.
- `two_factor_providers_for_user` filter overrides the available two-factor providers for a specific user. Array values are instances of provider classes and the user object `WP_User` is available as the second argument.
- `two_factor_enabled_providers_for_user` filter overrides the list of two-factor providers enabled for a user. First argument is an array of enabled provider classnames as values, the second argument is the user ID.
- `two_factor_user_authenticated` action which receives the logged in `WP_User` object as the first argument for determining the logged in user right after the authentication workflow.
- `two_factor_email_token_ttl` filter overrides the time interval in seconds that an email token is considered after generation. Accepts the time in seconds as the first argument and the ID of the `WP_User` object being authenticated.
- `two_factor_email_token_length` filter overrides the default 8 character count for email tokens.
- `two_factor_backup_code_length` filter overrides the default 8 character count for backup codes. Providers the `WP_User` of the associated user as the second argument.

== Frequently Asked Questions ==

= What PHP and WordPress versions does the Two-Factor plugin support? =

This plugin supports the last two major versions of WordPress and <a href="https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/">the minimum PHP version</a> supported by those WordPress versions.

= How can I send feedback or get help with a bug? =

The best place to report bugs, feature suggestions, or any other (non-security) feedback is at <a href="https://github.com/WordPress/two-factor/issues">the Two Factor GitHub issues page</a>. Before submitting a new issue, please search the existing issues to check if someone else has reported the same feedback.

= Where can I report security bugs? =

The plugin contributors and WordPress community take security bugs seriously. We appreciate your efforts to responsibly disclose your findings, and will make every effort to acknowledge your contributions.

To report a security issue, please visit the [WordPress HackerOne](https://hackerone.com/wordpress) program.

== Screenshots ==

1. Two-factor options under User Profile.
2. U2F Security Keys section under User Profile.
3. Email Code Authentication during WordPress Login.

== Changelog ==

= 0.14.2 - 2025-12-11 =

* **New Features:** Add filter for rest_api_can_edit_user_and_update_two_factor_options by @gutobenn in #689
* **Development Updates:** Remove Coveralls tooling and add inline coverage report by @kasparsd in #717
* **Development Updates:** Update blueprint path to pull from main branch instead of a deleted f… by @georgestephanis in #719
* **Development Updates:** Fix blueprint and wporg asset deploys by @kasparsd in #734
* **Development Updates:** Upload release only on tag releases by @kasparsd in #735
* **Development Updates:** Bump playwright and @playwright/test by @dependabot[bot] in #721
* **Development Updates:** Bump tar-fs from 3.1.0 to 3.1.1 by @dependabot[bot] in #720
* **Development Updates:** Bump node-forge from 1.3.1 to 1.3.2 by @dependabot[bot] in #724
* **Development Updates:** Bump js-yaml by @dependabot[bot] in #725
* **Development Updates:** Mark as tested with the latest WP core version by @kasparsd in #730

= 0.14.1 - 2025-09-05 =

- Don't URI encode the TOTP url for display. by @dd32 in #711
- Removed the duplicate Security.md by @slvignesh05 in #712
- Fixed linting issues by @sudar in #707
- Update development dependencies and fix failing QR unit test by @kasparsd in #714
- Trigger checkbox js change event by @gedeminas in #688

= 0.14.0 - 2025-07-03 =

* **Features:** Enable Application Passwords for REST API and XML-RPC authentication (by default) by @joostdekeijzer in #697 and #698. Previously this required two_factor_user_api_login_enable filter to be set to true which is now the default during application password auth. XML-RPC login is still disabled for regular user passwords.
* **Features:** Label recommended methods to simplify the configuration by @kasparsd in #676 and #675
* **Documentation:** Add WP.org plugin demo by @kasparsd in #667
* **Documentation:** Document supported versions of WP core and PHP by @jeffpaul in #695
* **Documentation:** Document the release process by @jeffpaul in #684
* **Tooling:** Remove duplicate WP.org screenshots and graphics from SVN trunk by @jeffpaul in #683

= 0.13.0 - 2025-04-02 =

- Add two_factor_providers_for_user filter to limit two-factor providers available to each user by @kasparsd in #669
- Update automated testing to cover PHP 8.4 and default to PHP 8.3 by @BrookeDot in #665

[View the complete changelog details here](https://github.com/wordpress/two-factor/blob/master/CHANGELOG.md).

== Upgrade Notice ==

= 0.10.0 =
Bumps WordPress minimum supported version to 6.3 and PHP minimum to 7.2.

= 0.9.0 =
Users are now asked to re-authenticate with their two-factor before making changes to their two-factor settings. This associates each login session with the two-factor login meta data for improved handling of that session.
