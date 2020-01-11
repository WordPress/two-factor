=== Two-Factor ===
Contributors: georgestephanis, valendesigns, stevenkword, extendwings, sgrant, aaroncampbell, johnbillion, stevegrunwell, netweb, kasparsd, alihusnainarshad
Tags: two factor, two step, authentication, login, totp, fido u2f, u2f, email, backup codes, 2fa, yubikey
Requires at least: 4.3
Tested up to: 5.3
Requires PHP: 5.6
Stable tag: trunk

Enable Two-Factor Authentication using time-based one-time passwords (OTP, Google Authenticator), Universal 2nd Factor (FIDO U2F, YubiKey), email and backup verification codes.

== Description ==

Use the "Two-Factor Options" section under "Users" → "Your Profile" to enable and configure one or multiple two-factor authentication providers for your account:

- Email codes
- Time Based One-Time Passwords (TOTP)
- FIDO Universal 2nd Factor (U2F)
- Backup Codes
- Dummy Method (only for testing purposes)

For more history, see [this post](https://stephanis.info/2013/08/14/two-cents-on-two-factor/).


== Screenshots ==

1. Two-factor options under User Profile.
2. U2F Security Keys section under User Profile.
3. Email Code Authentication during WordPress Login.

== Get Involved ==

Development happens [on GitHub](https://github.com/wordpress/two-factor/). Join the `#core-passwords` channel [on WordPress Slack](http://wordpress.slack.com) ([sign up here](http://chat.wordpress.org)).

Here is how to get started:

    $ git clone https://github.com/wordpress/two-factor.git
    $ npm install

Then open [a pull request](https://help.github.com/articles/creating-a-pull-request-from-a-fork/) with the suggested changes.

== Changelog ==

See the [release history](https://github.com/wordpress/two-factor/releases).
