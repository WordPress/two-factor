#!/bin/bash

export WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
export WP_TESTS_DIR="${WP_TESTS_DIR:-$WP_CORE_DIR/tests/phpunit}"

# Start the MySQL server.
service mysql start

# Setup the test DB.
mysql --user=root --password=root --execute \
	"GRANT ALL ON *.* TO 'root'@'localhost' WITH GRANT OPTION;
	FLUSH PRIVILEGES;
	DROP DATABASE IF EXISTS wordpress_test;
	CREATE DATABASE wordpress_test"

mysqladmin --user=root --password=root password root

# Run the command passed to this container.
exec "$@"
