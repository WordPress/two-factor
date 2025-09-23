# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/), and will adhere to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - TBD

## [0.13.0] - 2025-04-02
- Add two_factor_providers_for_user filter to limit two-factor providers available to each user by @kasparsd in #669
- Update automated testing to cover PHP 8.4 and default to PHP 8.3 by @BrookeDot in #665

## [0.12.0] - 2025-02-14
- Simplify the Two Factor settings in user profile by @kasparsd in #654
- Fix PHP 8.4 Implicitly marking parameter $previous as nullable is deprecated by @BrookeDot in #664

## [0.11.0] - 2025-01-09
- Remove duplicate two_factor_providers filter calls to allow disabling core providers by @kasparsd in #651
- Encourage setting up a second recovery method by @kasparsd in #642
- Focus in code input when totp is checked by @thrijith in #645
- Add autocomplete "one-time-code" attribute by @stefanmomm in #657
- Add filters for email token and backup code length by @kasparsd in #653
- Enable TOTP method when method is configured by @kasparsd in #643

## [0.10.0] - 2024-12-02
- Bump minimum WP to 6.3, minimum PHP to 7.2. by @dd32 in #625
- Rely on just-in-time translation loading by @swissspidy in #608
- Update/headers by @jeffpaul in #610
- Update short description by @jeffpaul in #612
- Fix typos by @szepeviktor in #617
- Bump tested upto version to WP 6.6 by @mehul0810 in #616
- Fire an action when a user revalites their 2FA session. by @dd32 in #620
- Remove old grunt deploy related code. See #543 by @dd32 in #627
- Fix Action unit testing by @dd32 in #624
- Update two factor options layout by @thrijith in #623
- Bump send and express by @dependabot in #634
- Accessibility for options page by @dd32 in #632
- Fix errors reported by PHPStan by @szepeviktor in #619
- Fix failing unit test by @kasparsd in #639
- Add basic PHPStan linter by @kasparsd in #638
- Update screenshots to match the current UI by @kasparsd in #636
- Improve discoverability by @kasparsd in #635
- Delete user meta on plugin uninstall by @kasparsd in #637
- Bump axios from 1.6.8 to 1.7.4 by @dependabot in #626
- Bump braces from 3.0.2 to 3.0.3 by @dependabot in #613
- Bump webpack from 5.91.0 to 5.94.0 by @dependabot in #628
- Bump symfony/process from 5.4.40 to 5.4.46 by @dependabot in #649

## [0.9.1] - 2024-04-25
- Remove trailing commas in parameters to avoid syntax error with some PHP versions (ex. 7.2.x) by @KZeni in #604
- Ensure PHP 5.6+ support during CI to avoid breaking changes by @kasparsd in #605

## [0.9.0] - 2024-04-25
- Users are now asked to re-authenticate with their two-factor before making changes to their two-factor settings #529. This builds on #528 which associates each login session with the two-factor login meta data for improved handling of that session.
- Fix typo by @pkevan in #551
- Add a filter to filter the classname used for a provider by @dd32 in #546
- Bump tested up to version by @av3nger in #552
- Store the two-factor details in the user session at login time by @dd32 in #528
- Bump guzzlehttp/psr7 from 2.4.3 to 2.5.0 by @dependabot in #555
- Use simpler/less-technical wording and UI. by @dd32 in #521
- Fixing bug where Super Admins cannot setup Time Based One-Time Password as first Two Factor option on WP VIP by @spenserhale in #560
- Enqueue jQuery and wp.apiRequest for use within callbacks. by @dd32 in #561
- Revalidate two factor settings prior to allowing any two-factor changes to an account. by @dd32 in #529
- ReAuth: resolve fatal, code cleanup by @dd32 in #567
- Sync two-factor session meta to newly created sessions by @dd32 in #574
- Require a nonce be present for revalidate POST requests. by @dd32 in #575
- Bump tough-cookie from 4.1.2 to 4.1.3 by @dependabot in #579
- Destroy existing sessions when activating 2FA. by @dd32 in #578
- Bump version identifier by @iandunn in #588
- Add method to disable an individual provider by @iandunn in #587
- Prefer "require_once" in a few spots. by @JJJ in #595
- Update readme.txt by @bph in #597
- Bump postcss from 8.4.17 to 8.4.31 by @dependabot in #589
- Bump word-wrap from 1.2.3 to 1.2.4 by @dependabot in #582

## [0.8.2] - 2023-09-04
- Improved error handling in WP_Two_Factor_Email::generate_code() by ensuring $user_id is a valid WP_User object. Props @apokalyptique. See #560.
- Fixed a bug that could cause a fatal error when using non-object values in wp_get_current_user() by adding type checks. Props @apokalyptique. See #561.
- Fixed "Call to a member function is_locked()" fatal by checking if $provider is an object before method access. Props @apokalyptique. See #578.
- Prevented Call to a member function exists() fatal error by verifying $provider is an object before invoking method calls. Props @apokalyptique. See #552.

## [0.8.1] - 2023-03-27
- Remove unnecessary comma to fix fatal error on PHP 7.2 #547

## [0.8.0] - 2023-03-27
- Reduce the login nonce expiration from 60 minutes to 10 minutes by default, and include user ID in the login nonce to make them unique #473.
- Replace QR generation for TOTP secrets with local Javascript tooling instead of Google Charts API #487 and #495.
- Fix Backup code download with quotes in translations #494.
- Block sending authentication cookies upon 2FA login #502.
- Backup Codes: Always generate 10 codes via REST #514.
- TOTP: Enforce single-use of TOTP one-time passwords #517.
- Add rate limiting to two factor attempts #510.
- Core: Reset compromised passwords after 2FA failures #482.
- Document the TOTP Filters, add Issuer filter #530.
- Support login-by-email in maybe_show_reset_password_notice() #532.
- Be more tolerant of user input for auth codes #518.
- Standardise on int|WP_User input to the "for user" functions #535.

## [0.7.3] - 2022-10-17
- Make wp_login_failed action call compatible with the WP core argument count and types. Reported in #471 by @dziudek and fixed in #478 by @dd32.
- Use hash_equals() for nonce comparison to improve security. Reported in #458 and fixed in #458 by @calvinalkan.
- Improve compatibility with PHP 8.1 by replacing all instances of FILTER_SANITIZE_STRING usage. Reported and fixed in #428 by @sjinks.
- Add automated checks for PHP 8 compatibility in #465 and #466 by @kasparsd.
- Improve accessibility of two-factor settings in the user profile by introducing a label that links the method names with the associated checkboxes. Reported and fixed in #387 by @r-a-y.
- Improve TOTP autocomplete behaviour by setting the autocomplete attribute to one-time-code. Reported and fixed in #420 by @squaredpx.

## [0.7.2] - 2022-09-12
- Security improvement: Store the second factor authentication step nonce hashed to prevent leaking it via database read access #453. Props to @calvinalkan for reporting the issue.
- Fix: Add wp_specialchars_decode() to escape the HTML entity on the Email Subject line (#412), props @nbwpuk.
- Fix: Use hash_equals() when comparing the email token (#425), props @Mati02K.
- Tooling: Introduce @wordpress/env for development tooling and move to GitHub actions for CI (#436).

## [0.7.1] - 2021-09-07
- Update the login_header() and login_footer() methods to match the WP core (see #407), props @cfaria.
- Mark as compatible with WordPress 5.8.

## [0.7.0] - 2020-08-26
- Fix: improve time-based one-time (TOTP) autofill when using password managers like 1Password, see #373. Props @omelhus.
- Fix: allow spaces in email code input and strip them away before processing, see #379. Props @shay1383.
- Fix: remove references to Google Authenticator app since there are a lot more TOTP authenticators these days, see #367. Props @r-a-y.
- Fix: register FIDO U2F related scripts during the suggested action hooks to avoid PHP noticed, see #356 and #368. Props @cojennin.
- Rename and deprecate action and filter names two-factor-user-options- and two-factor-totp-time-step-allowance that don't following the WP coding standards. Use two_factor_user_options_ and two_factor_totp_time_step_allowance now. See #363. Props @paulschreiber.
- Update codebase to match the WordPress coding standards, see #340. Props @paulschreiber.
- Add tooling to run PHPUnit tests locally during development, see #355. Props @kasparsd.

## [0.6.0] - 2020-05-06
- Security fix: escape the U2F key value when doing the key lookup in database during login. Props @mjangda from WordPress VIP. See #351.
- New feature: invalidate email tokens 15 minutes after they were generated. Use the two_factor_token_ttl filter to override this time-to-live interval. See #352.
- Document some of the available filters.

## [0.5.2] - 2020-04-30
- Bugfix: saving standard user profile fields no longer resets the time-based-password key, see #341.
- Bugfix: remove spaces around authentication codes before verifying them, see #339 (props @paulschreiber).
- Bugfix: allow admins to configure FIDO U2F keys for other users, see #349.
- Enable the "Dummy" authenticator method only when WP_DEBUG is set since we don't want regular users using it.
- New: Add an two_factor_user_authenticated action when the user is logged-in after the second factor has been verified, see #324 (props @Kubitomakita).
- New: Add two_factor_token_email_subject and two_factor_token_email_message filters to customize the email code subject and body, see #345 (props @christianc1).
- Update the reference article URL in the readme files to account for domain change, see #332 (props @todeveni).

## [0.5.1] - 2020-02-05
- Security fix: invalidate the session token used for the first password-based authentication, props @aapost0l.
- Typo fixes in code comments, props @akkspros.

## [0.5.0] - 2020-01-11
- Add a compatibility layer for Jetpack Secure Sign On to support longer session cookies, see #276. Props @pyronaur.
- Fix spelling errors in code comments, see #318. Props @akkspros.
- Add license file, #313. Props @axelsimon.
- Bump the supported version of PHP to 5.6 to match the WordPress core.

## [0.4.8] - 2019-12-26
- Mark as tested with WordPress 5.3.
- Add a screenshot with email code authentication prompt.
- Update development tooling versions.

## [0.4.7] - 2019-05-08
- Introduce a two_factor_totp_title filter to allow TOTP title to be changed, see #294 (props @BrookeDot).
- Mark as tested with WordPress 5.2.

## [0.4.6] - 2019-04-26
- Add a unique ID for the two-factor options section, see #286 (props @joshbetz).
- Add usage instructions and plugin screenshots, fixes #272.

## [0.4.5] - 2019-04-22
- Add the missing two-factor textdomains, see #281 (props @Sonic853).
- Fix U2F feature detection in Firefox, see #285.

## [0.4.4] - 2019-04-15
- Add the closing </div> to match the WP core login form structure, see #274 (props @claytoncollie).

## [0.4.3] - 2019-04-12
- Bump the actual version in the plugin header. That's what you get for deploying on Fridays.

## [0.4.2] - 2019-04-12
- Developer tooling update, see #277.

## [0.4.1] - 2019-04-12
- Redirect to admin_url() instead of $_SERVER['REQUEST_URI'] if $_REQUEST['redirect_to'] is not set, see #276 (props @joshbetz).

## [0.4.0] - 2019-03-19
- Disable authentication via REST and XML-RPC endpoints for users with any of the two-factor methods enabled, see #271.
- Mark as tested with WordPress 5.1.

## [0.3.0] - 2018-11-06
- Mark as tested with WordPress 5.0.
- Always post the two-factor login form to wp-login.php which runs all the required hooks for processing. Fixes login issues on WP Engine #257 and when a custom login URL is used #256.

## [0.2.0] - 2018-10-16
- Add developer tools for deploying to WP.org manually.

[Unreleased]: https://github.com/WordPress/two-factor/compare/master...develop
[0.13.0]: https://github.com/WordPress/two-factor/compare/0.12.0...0.13.0
[0.12.0]: https://github.com/WordPress/two-factor/compare/0.11.0...0.12.0
[0.11.0]: https://github.com/WordPress/two-factor/compare/0.10.0...0.11.0
[0.10.0]: https://github.com/WordPress/two-factor/compare/0.9.1...0.10.0
[0.9.1]: https://github.com/WordPress/two-factor/compare/0.9.0...0.9.1
[0.9.0]: https://github.com/WordPress/two-factor/compare/0.8.2...0.9.0
[0.8.2]: https://github.com/WordPress/two-factor/compare/0.8.1...0.8.2
[0.8.1]: https://github.com/WordPress/two-factor/compare/0.8.0...0.8.1
[0.8.0]: https://github.com/WordPress/two-factor/compare/0.7.3...0.8.0
[0.7.3]: https://github.com/WordPress/two-factor/compare/0.7.2...0.7.3
[0.7.2]: https://github.com/WordPress/two-factor/compare/0.7.1...0.7.2
[0.7.1]: https://github.com/WordPress/two-factor/compare/0.7.0...0.7.1
[0.7.0]: https://github.com/WordPress/two-factor/compare/0.6.0...0.7.0
[0.6.0]: https://github.com/WordPress/two-factor/compare/0.5.2...0.6.0
[0.5.2]: https://github.com/WordPress/two-factor/compare/0.5.1...0.5.2
[0.5.1]: https://github.com/WordPress/two-factor/compare/0.5.0...0.5.1
[0.5.0]: https://github.com/WordPress/two-factor/compare/0.4.8...0.5.0
[0.4.8]: https://github.com/WordPress/two-factor/compare/0.4.7...0.4.8
[0.4.7]: https://github.com/WordPress/two-factor/compare/0.4.6...0.4.7
[0.4.6]: https://github.com/WordPress/two-factor/compare/0.4.5...0.4.6
[0.4.5]: https://github.com/WordPress/two-factor/compare/0.4.4...0.4.5
[0.4.4]: https://github.com/WordPress/two-factor/compare/0.4.3...0.4.4
[0.4.3]: https://github.com/WordPress/two-factor/compare/0.4.2...0.4.3
[0.4.2]: https://github.com/WordPress/two-factor/compare/0.4.1...0.4.2
[0.4.1]: https://github.com/WordPress/two-factor/compare/0.4.0...0.4.1
[0.4.0]: https://github.com/WordPress/two-factor/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/WordPress/two-factor/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/WordPress/two-factor/tree/0.2.0
