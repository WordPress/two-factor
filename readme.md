# Two-Factor

[![Test](https://github.com/WordPress/two-factor/actions/workflows/test.yml/badge.svg)](https://github.com/WordPress/two-factor/actions/workflows/test.yml) [![Deploy](https://github.com/WordPress/two-factor/actions/workflows/deploy.yml/badge.svg)](https://github.com/WordPress/two-factor/actions/workflows/deploy.yml)

Two-Factor plugin for WordPress. [View on WordPress.org →](https://wordpress.org/plugins/two-factor/)

## Usage

See the [readme.txt](readme.txt) for installation and usage instructions.

## Contribute

Please [report (non-security) issues](https://github.com/WordPress/two-factor/issues) and [open pull requests](https://github.com/WordPress/two-factor/pulls) on GitHub. See below for information on reporting potential security/privacy vulnerabilities.

Join the `#core-passwords` channel [on WordPress Slack](http://wordpress.slack.com) ([sign up here](http://chat.wordpress.org)).

To use the provided development environment, you'll first need to install and launch Docker. Once it's running, the next steps are:

    $ git clone https://github.com/wordpress/two-factor.git
    $ cd two-factor
    $ composer install
    $ npm install
    $ npm run build
    $ npm run env start

See `package.json` for other available scripts you might want to use during development, like linting and testing.

When you're ready, open [a pull request](https://help.github.com/articles/creating-a-pull-request-from-a-fork/) with the suggested changes.

## Testing

1. Run `npm test` or `npm run test:watch`.

To generate a code coverage report, be sure to start the testing environment with coverage support enabled:
    npm run env start -- --xdebug=coverage

To view the code coverage report, you can open a web browser, go to `File > Open file...`, and then select `{path to two-factor}/tests/logs/html/index.html`.

## Deployments

Deployments [to WP.org plugin repository](https://wordpress.org/plugins/two-factor/) are handled automatically by the GitHub action [.github/workflows/deploy.yml](.github/workflows/deploy.yml). All merges to the `master` branch are committed to the [`trunk` directory](https://plugins.trac.wordpress.org/browser/two-factor/trunk) while all [Git tags](https://github.com/WordPress/two-factor/tags) are pushed as versioned releases [under the `tags` directory](https://plugins.trac.wordpress.org/browser/two-factor/tags).

## Known Issues

- PHP codebase doesn't pass the WordPress coding standard checks, see [#437](https://github.com/WordPress/two-factor/issues/437).

## Credits

Created [by contributors](https://github.com/WordPress/two-factor/graphs/contributors) and released under [GPLv2 or later](LICENSE.md).

## Security

Please privately report any potential security issues to the [WordPress HackerOne](https://hackerone.com/wordpress) program.
