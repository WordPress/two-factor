#!/bin/bash

export WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
export WP_TESTS_DIR="${WP_TESTS_DIR:-$WP_CORE_DIR/tests/phpunit}"

# Start the MySQL server.
service mysql start

# Ensure we have a password and a fresh database.
# TODO: Figure out the ERROR 1045 (28000) error because of the password change.
mysql --user=root --password=root << END
	DROP DATABASE IF EXISTS wordpress_test;
	CREATE DATABASE wordpress_test;
	SET PASSWORD = PASSWORD('root');
	GRANT ALL ON *.* TO 'root'@'localhost' WITH GRANT OPTION;
	FLUSH PRIVILEGES;
END

# Run the command passed to this container.
exec "$@"
