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

I (George Stephanis) am going to be pitching this as a feature plugin for core on Wednesday (July 8th) at the core dev meeting at the end.  I'm currently building a list of folks interested in being involved, currently including:

* @JeffMatson https://twitter.com/thejeffmatson/status/617527690799783936
* @brennenbyrne (and the Clef gang) https://twitter.com/brennenbyrne/status/617059593827414016
* @nikv https://twitter.com/techvoltz/status/617511256191279104
* @ericmann https://twitter.com/ericmann/status/617500383020093440
* @daveross https://twitter.com/csixty4/status/617499908556357632
* @jjj https://twitter.com/jjj/status/617426588662169600
* @moonomo https://twitter.com/moonomo/status/617422900627312641
* @rezzz-dev https://twitter.com/rezzz/status/617395429005729792
* @morganestes https://twitter.com/morganestes/status/617133299757002752
* @voldemortensen https://twitter.com/garth_mortensen/status/617087740618764288
* @mor10

If cleared as a feature plugin, we'll get a weekly meeting time sorted.

== Developers ==

If you'd like to create your own two-factor provider, take a look at the (tentatively bundled) Dummy class (providers/class.two-factor-dummy.php).  All providers behave as Singletons (currently) storing the instance as a static in the `get_instance()` method.  All providers are currently child classes of the `Two_Factor_Provider` class as well, and you must write three methods (at minimum):

* `get_label()` -- It returns the unescaped human readable name of your method.
* `authentication_page()` -- It prints out the contents of the `<form>` that displays as the interstitial login page.
* `validate_authentication()` -- It processes the submission from the interstitial login page, and returns either `true` or `false` for whether the user has passed the check.
