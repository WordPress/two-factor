=== Two-Factor ===
Contributors: georgestephanis
Tags: two factor, authentication, login, two factor authentication
Requires at least: 4.0
Tested up to: 4.0
Stable tag: trunk

A prototype extensible core to enable Two-Factor Authentication.

== Description ==

For more info, see: http://stephanis.info/2013/08/14/two-cents-on-two-factor/

== Get Involved ==

Weekly meetings are Thursdays at 5pm Eastern Time in #core-passwords on wordpress.slack.com

== Developers ==

If you'd like to create your own two-factor provider, take a look at the (tentatively bundled) Dummy class (providers/class.two-factor-dummy.php).  All providers behave as Singletons (currently) storing the instance as a static in the `get_instance()` method.  All providers are currently child classes of the `Two_Factor_Provider` class as well, and you must write three methods (at minimum):

* `get_label()` -- It returns the unescaped human readable name of your method.
* `authentication_page()` -- It prints out the contents of the `<form>` that displays as the interstitial login page.
* `validate_authentication()` -- It processes the submission from the interstitial login page, and returns either `true` or `false` for whether the user has passed the check.

There's also some more details in the GitHub Repository Wiki.
