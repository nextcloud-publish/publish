# syntax=docker/dockerfile:1
FROM php:8.5-cli-alpine

# php-amqplib hard-requires ext-sockets. It ships with PHP but is not built in
# the official images, so it has to be compiled in. linux-headers is needed on
# top of $PHPIZE_DEPS because sockets.c includes <linux/sock_diag.h>, which
# Alpine does not ship by default. Both are build-time only, hence the virtual
# package and the immediate cleanup.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install -j"$(nproc)" sockets \
    && apk del .build-deps

# Composer, handy for running composer commands inside the container
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8080

# PHP's built-in web server, routing every request through the Symfony front controller
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
