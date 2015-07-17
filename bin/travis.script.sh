#!/bin/bash

set -e

if [ "$TRAVIS_PULL_REQUEST" != 'false' ] && ( [ "$LIMIT_TRAVIS_PR_CHECK_SCOPE" == 'files' ] || [ "$LIMIT_TRAVIS_PR_CHECK_SCOPE" == 'patches' ] ); then
	git diff --diff-filter=AM --no-prefix --unified=0 $TRAVIS_BRANCH...$TRAVIS_COMMIT -- $PATH_INCLUDES | php $BIN_PATH/parse-diff-ranges.php  > /tmp/checked-files
else
	find $PATH_INCLUDES -type f | grep -v -E "^./(bin|\.git)/" | sed 's:^\.//*::' > /tmp/checked-files
fi

echo "LIMIT_TRAVIS_PR_CHECK_SCOPE: $LIMIT_TRAVIS_PR_CHECK_SCOPE"
echo "TRAVIS_BRANCH: $TRAVIS_BRANCH"
echo "Files to check:"
cat /tmp/checked-files
echo

function remove_diff_range {
	sed 's/:[0-9][0-9]*-[0-9][0-9]*$//' | sort | uniq
}
function filter_php_files {
	 grep -E '\.php(:|$)'
}
function filter_js_files {
	grep -E '\.js(:|$)'
}

# Run PHP syntax check
cat /tmp/checked-files | remove_diff_range | filter_php_files | xargs --no-run-if-empty php -lf

# Run JSHint
if ! cat /tmp/checked-files | remove_diff_range | filter_js_files | xargs --no-run-if-empty jshint --reporter=unix $( if [ -e .jshintignore ]; then echo "--exclude-path .jshintignore"; fi ) > /tmp/jshint-report; then
	echo "## JSHint"
	if [ "$LIMIT_TRAVIS_PR_CHECK_SCOPE" == 'patches' ]; then
		# Note that filter-report-for-patch-ranges will exit 1 if any files and lines in the report match any files of /tmp/checked-files
		echo "Filtering issues to patch subsets..."
		cat /tmp/jshint-report | php $BIN_PATH/filter-report-for-patch-ranges.php /tmp/checked-files
	else
		cat /tmp/jshint-report
		exit 1
	fi
fi

# Run JSCS
if [ -n "$JSCS_CONFIG" ] && [ -e "$JSCS_CONFIG" ]; then
	echo "## JSCS"
	# TODO: Restrict to lines changed (need an emacs/unix reporter)
	cat /tmp/checked-files | remove_diff_range | filter_js_files | xargs --no-run-if-empty jscs --verbose --config="$JSCS_CONFIG"
fi

# Run PHP_CodeSniffer
echo "## PHP_CodeSniffer"
if ! cat /tmp/checked-files | remove_diff_range | filter_php_files | xargs --no-run-if-empty $PHPCS_DIR/scripts/phpcs -s --report-emacs=/tmp/phpcs-report --standard=$WPCS_STANDARD $(if [ -n "$PHPCS_IGNORE" ]; then echo --ignore=$PHPCS_IGNORE; fi); then
	if [ "$LIMIT_TRAVIS_PR_CHECK_SCOPE" == 'patches' ]; then
		# Note that filter-report-for-patch-ranges will exit 1 if any files and lines in the report match any files of /tmp/checked-files
		echo "Filtering issues to patch subsets..."
		cat /tmp/phpcs-report | php $BIN_PATH/filter-report-for-patch-ranges.php /tmp/checked-files
	else
		cat /tmp/phpcs-report
		exit 1
	fi
fi

# Run PHPUnit tests
if [ -e phpunit.xml ] || [ -e phpunit.xml.dist ]; then
	phpunit $( if [ -e .coveralls.yml ]; then echo --coverage-clover build/logs/clover.xml; fi )
fi

# Run YUI Compressor Check
if [ "$YUI_COMPRESSOR_CHECK" == 1 ] && [ 0 != $( cat /tmp/checked-files | filter_js_files | wc -l ) ]; then
	YUI_COMPRESSOR_PATH=/tmp/yuicompressor-2.4.8.jar
	wget -O "$YUI_COMPRESSOR_PATH" https://github.com/yui/yuicompressor/releases/download/v2.4.8/yuicompressor-2.4.8.jar
	cat /tmp/checked-files | remove_diff_range | filter_js_files | xargs --no-run-if-empty java -jar "$YUI_COMPRESSOR_PATH" -o /dev/null 2>&1
fi
