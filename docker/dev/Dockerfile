# syntax=docker/dockerfile:1

# Dev image: PHP-FPM and the dependencies, without the source; compose.yaml mounts the checkout.
# Build context is the repo root: docker build -f docker/dev/Dockerfile .
FROM alpine:latest

# openssl and zip are for composer.
RUN apk add --no-cache \
        php85 php85-fpm php85-pecl-amqp php85-pdo php85-pdo_pgsql \
        php85-ctype php85-iconv php85-simplexml php85-xml \
        php85-dom php85-mbstring php85-tokenizer php85-xmlwriter \
        php85-curl php85-phar php85-openssl php85-zip \
        tzdata

ENV TZ=UTC \
    OPCACHE_VALIDATE_TIMESTAMPS=1
COPY docker/php/php.ini /etc/php85/conf.d/99-publish.ini
COPY docker/php/php-fpm.conf /etc/php85/php-fpm.conf

ARG UID=10001
ARG GID=10001
RUN addgroup -S -g "$GID" publish && adduser -S -u "$UID" -G publish publish

WORKDIR /publish

COPY --from=composer/composer:2-bin /composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-interaction --no-progress

# var/ holds the Symfony cache and var/log/, the only directory the app writes.
RUN mkdir var && chown publish:publish var

USER publish

EXPOSE 9000

CMD ["php-fpm85", "--nodaemonize"]
