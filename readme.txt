=== Two Factor ===
Contributors: georgestephanis, valendesigns, stevenkword, extendwings, sgrant, aaroncampbell, johnbillion, stevegrunwell, netweb, kasparsd, alihusnainarshad, passoniate
Tags:         2fa, mfa, totp, authentication, security
Tested up to: 6.9
Stable tag:   0.14.2
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

One small challenge back to you: do you want this to read as “one good option” or “the obvious option”? This version lands in the middle. If you expect admins to skim, tightening it even further to “recommended provider” language might improve adoption, but that’s a subtle policy call for core-adjacent plugins.

== Screenshots ==

1. Two-factor options under User Profile - Shows the main configuration area where users can enable different authentication methods.
2. U2F Security Keys section under User Profile - Displays the security key management interface for registering and managing FIDO U2F devices.
3. Email Code Authentication during WordPress Login - Shows the email verification screen that appears during login.
4. Authenticator App (TOTP) setup with QR code - Demonstrates the QR code generation and manual key entry for TOTP setup.
5. Backup codes generation and management - Shows the backup codes interface for generating and managing emergency access codes.

== Changelog ==

See the [release history](https://github.com/wordpress/two-factor/releases).

