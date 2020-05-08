#!/bin/bash

export WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
export WP_TESTS_DIR="${WP_TESTS_DIR:-$WP_CORE_DIR/tests/phpunit}"

# Start the MySQL server.
service mysql start

# Setup a test DB.
mysql --user=root --password=root --execute="CREATE DATABASE wordpress_test;"

# Run the command passed to this container.
exec "$@"
