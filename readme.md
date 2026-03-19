# Two-Factor
![Two-Factor](https://github.com/WordPress/two-factor/blob/master/.wordpress-org/banner-1544x500.png)

![Required PHP Version](https://img.shields.io/wordpress/plugin/required-php/two-factor?label=Requires%20PHP) ![Required WordPress Version](https://img.shields.io/wordpress/plugin/wp-version/two-factor?label=Requires%20WordPress) ![WordPress Tested Up To](https://img.shields.io/wordpress/plugin/tested/two-factor?label=WordPress) [![GPL-2.0-or-later License](https://img.shields.io/github/license/WordPress/two-factor.svg)](https://github.com/WordPress/two-factor/blob/trunk/LICENSE.md?label=License)

![WordPress.org Rating](https://img.shields.io/wordpress/plugin/rating/two-factor?label=WP.org%20Rating) ![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/two-factor?label=WP.org%20Downloads) ![WordPress Plugin Active Installs](https://img.shields.io/wordpress/plugin/installs/two-factor?label=WP.org%20Active%20Installs) [![WordPress Playground Demo](https://img.shields.io/wordpress/plugin/v/two-factor?logo=wordpress&logoColor=FFFFFF&label=Live%20Demo&labelColor=3858E9&color=3858E9)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/two-factor/master/.wordpress-org/blueprints/blueprint.json)

[![Test](https://github.com/WordPress/two-factor/actions/workflows/test.yml/badge.svg)](https://github.com/WordPress/two-factor/actions/workflows/test.yml) [![Deploy](https://github.com/WordPress/two-factor/actions/workflows/deploy.yml/badge.svg)](https://github.com/WordPress/two-factor/actions/workflows/deploy.yml)

> Two-Factor plugin for WordPress. [View on WordPress.org →](https://wordpress.org/plugins/two-factor/)

## Description

The Two-Factor plugin adds an extra layer of security to your WordPress login by requiring users to provide a second form of authentication in addition to their password. This helps protect against unauthorized access even if passwords are compromised.


## Usage

See the [readme.txt](readme.txt) for installation and usage instructions.

## Contribute

Please [report (non-security) issues](https://github.com/WordPress/two-factor/issues) and [open pull requests](https://github.com/WordPress/two-factor/pulls) on GitHub. See below for information on reporting potential security/privacy vulnerabilities.

Join the `#core-passwords` channel [on WordPress Slack](http://wordpress.slack.com) ([sign up here](http://chat.wordpress.org)).

To use the provided development environment, you'll first need to install and launch Docker. Once it's running, the next steps are:

    git clone https://github.com/wordpress/two-factor.git
    cd two-factor
    npm install
    npm run build
    npm run env start

See `package.json` for other available scripts you might want to use during development, like linting and testing.

When you're ready, open [a pull request](https://help.github.com/articles/creating-a-pull-request-from-a-fork/) with the suggested changes.

## Testing

1. Run `npm test` or `npm run test:watch`.

To generate a code coverage report, be sure to start the testing environment with coverage support enabled:
    npm run env start -- --xdebug=coverage

To view the code coverage report, you can open a web browser, go to `File > Open file...`, and then select `{path to two-factor}/tests/logs/html/index.html`.

## Deployments

Deployments [to WP.org plugin repository](https://wordpress.org/plugins/two-factor/) are handled automatically by the GitHub action [.github/workflows/deploy.yml](.github/workflows/deploy.yml). All merges to the `master` branch are committed to the [`trunk` directory](https://plugins.trac.wordpress.org/browser/two-factor/trunk) while all [Git tags](https://github.com/WordPress/two-factor/tags) are pushed as versioned releases [under the `tags` directory](https://plugins.trac.wordpress.org/browser/two-factor/tags).

[View release documentation →](RELEASING.md)

## Known Issues

- PHP codebase doesn't pass the WordPress coding standard checks, see [#437](https://github.com/WordPress/two-factor/issues/437).

## Changelog

A complete listing of all notable changes are documented in [CHANGELOG.md](https://github.com/wordpress/two-factor/blob/master/CHANGELOG.md).

## Credits

Created [by contributors](https://github.com/WordPress/two-factor/blob/master/CREDITS.md) and released under [GPLv2 or later](LICENSE.md).

## Security

Please privately report any potential security issues to the [WordPress HackerOne](https://hackerone.com/wordpress) program.
