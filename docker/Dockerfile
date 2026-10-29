# syntax=docker/dockerfile:1

# Stage "base": everything but the source.
FROM alpine:latest AS base

RUN apk add --no-cache \
        php85 php85-pecl-amqp \
        php85-ctype php85-iconv php85-simplexml php85-xml \
        php85-dom php85-mbstring php85-tokenizer php85-xmlwriter \
        php85-curl php85-phar php85-openssl php85-zip \
        tzdata \
    && php -m | grep -qx amqp

# PHP reads TZ when a process starts; the deployment sets it, UTC otherwise.
ENV TZ=UTC
RUN echo 'date.timezone = ${TZ}' > /etc/php85/conf.d/99-timezone.ini

ARG UID=10001
ARG GID=10001
RUN addgroup -S -g "$GID" publish && adduser -S -u "$UID" -G publish publish

WORKDIR /publish

COPY --from=composer/composer:2-bin /composer /usr/bin/composer
# --no-scripts: Flex's auto-scripts call bin/console, which is not copied yet.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts

# var/ holds the Symfony cache, the only directory in the image the app writes.
RUN mkdir var && chown publish:publish var

USER publish

# Stage "release", the default: base plus the source. It has no CMD, the deployment sets the server.
FROM base AS release

COPY . .
