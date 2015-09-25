#!/bin/bash

set -e
shopt -s expand_aliases

# TODO: These should not override any existing environment variables
export WP_CORE_DIR=/tmp/wordpress
export WP_TESTS_DIR=${WP_CORE_DIR}/tests/phpunit
export PLUGIN_DIR=$(pwd)
export PLUGIN_SLUG=$(basename $(pwd) | sed 's/^wp-//')
export PHPCS_DIR=/tmp/phpcs
export PHPCS_GITHUB_SRC=squizlabs/PHP_CodeSniffer
export PHPCS_GIT_TREE=master
export PHPCS_IGNORE='tests/*,bin/*,includes/*'
export WPCS_DIR=/tmp/wpcs
export WPCS_GITHUB_SRC=WordPress-Coding-Standards/WordPress-Coding-Standards
export WPCS_GIT_TREE=master
export YUI_COMPRESSOR_CHECK=1
export DISALLOW_EXECUTE_BIT=0
export LIMIT_TRAVIS_PR_CHECK_SCOPE=files # when set to 'patches', limits reports to only lines changed; TRAVIS_PULL_REQUEST must not be 'false'
export PATH_INCLUDES=./
export WPCS_STANDARD=$(if [ -e phpcs.ruleset.xml ]; then echo phpcs.ruleset.xml; else echo WordPress-Core; fi)
if [ -e .jscsrc ]; then
	export JSCS_CONFIG=.jscsrc
elif [ -e .jscs.json ]; then
	export JSCS_CONFIG=.jscs.json
fi

# Load a .ci-env.sh to override the above environment variables
if [ -e $BIN_PATH/.ci-env.sh ]; then
	source $BIN_PATH/.ci-env.sh
fi

# Install the WordPress Unit Tests
if [ -e phpunit.xml ] || [ -e phpunit.xml.dist ]; then
	bash $BIN_PATH/install-wp-tests.sh wordpress_test root '' localhost $WP_VERSION
	cd ${WP_CORE_DIR}/src/wp-content/plugins
	mv $PLUGIN_DIR $PLUGIN_SLUG
	cd $PLUGIN_SLUG
	ln -s $(pwd) $PLUGIN_DIR
	echo "Plugin location: $(pwd)"

	if ! command -v phpunit >/dev/null 2>&1; then
		wget -O /tmp/phpunit.phar https://phar.phpunit.de/phpunit.phar
		chmod +x /tmp/phpunit.phar
		alias phpunit='/tmp/phpunit.phar'
	fi
fi

# Install PHP_CodeSniffer and the WordPress Coding Standards
mkdir -p $PHPCS_DIR && curl -L https://github.com/$PHPCS_GITHUB_SRC/archive/$PHPCS_GIT_TREE.tar.gz | tar xvz --strip-components=1 -C $PHPCS_DIR
mkdir -p $WPCS_DIR && curl -L https://github.com/$WPCS_GITHUB_SRC/archive/$WPCS_GIT_TREE.tar.gz | tar xvz --strip-components=1 -C $WPCS_DIR
$PHPCS_DIR/scripts/phpcs --config-set installed_paths $WPCS_DIR

# Install JSHint
if ! command -v jshint >/dev/null 2>&1; then
	npm install -g jshint
fi

# Install jscs
if [ -n "$JSCS_CONFIG" ] && [ -e "$JSCS_CONFIG" ] && ! command -v jscs >/dev/null 2>&1; then
	npm install -g jscs
fi

# Install Composer
if [ -e composer.json ]; then
	curl -s http://getcomposer.org/installer | php && php composer.phar install --dev --no-interaction
fi

set +e