# syntax=docker/dockerfile:1
FROM php:8.5-cli-alpine

# Symfony Messenger's AMQP transport is built on ext-amqp, which links against
# rabbitmq-c -- not on php-amqplib, so there is no ext-sockets here any more.
#
# PIE, not `pecl install`: PECL is deprecated in favour of PIE (the PHP Installer
# for Extensions). PIE replaces the download/build/enable orchestration only --
# the extension still compiles against the rabbitmq-c headers, which is why
# rabbitmq-c-dev is here. The headers are build-time only; the rabbitmq-c runtime
# library has to stay.
#
# PIE is published as a binary-only image tagged `bin` (latest) or `x.y.z-bin`;
# there is no `latest` tag to pull.
COPY --from=ghcr.io/php/pie:bin /pie /usr/bin/pie

# unzip is a PIE requirement rather than an amqp one: PIE downloads the extension
# as a zip, and this base image ships neither unzip nor git to unpack it.
RUN apk add --no-cache rabbitmq-c \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS rabbitmq-c-dev unzip \
    && pie install --no-interaction --no-cache php-amqp/php-amqp \
    && apk del .build-deps \
    && php -m | grep -qx amqp

# Composer, handy for running composer commands inside the container
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8080

# PHP's built-in web server, routing every request through the Symfony front controller
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
