ARG WP_PHP_VERSION=7.4
FROM wordpress:php${WP_PHP_VERSION}-fpm

# Allow custom versions of WP to be pulled in.
ARG WP_SVN_URL=https://develop.svn.wordpress.org/trunk/

ENV DEBIAN_FRONTEND noninteractive

# Development tooling dependencies.
RUN apt-get update \
	&& apt-get install --yes --no-install-recommends \
		bash less subversion default-mysql-server default-mysql-client libxml2-utils rsync git zip unzip \
		nodejs npm curl \
	&& npm install --global npm@latest \
	&& rm -rf /var/lib/apt/lists/*

# Setup xdebug.
RUN	pecl install xdebug \
	&& docker-php-ext-enable xdebug

# Install Composer.
RUN curl -s https://getcomposer.org/installer | php \
	&& mv composer.phar /usr/local/bin/composer

# Checkout WP with tests.
RUN svn export "$WP_SVN_URL" /tmp/wordpress

# Copy our WP tests config.
COPY wp-tests-config.php /tmp/wordpress/wp-tests-config.php

# Setup a custom entrypoint that bootstraps the environment.
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

VOLUME /tmp/wordpress

ENTRYPOINT ["entrypoint.sh"]
