# syntax=docker/dockerfile:1
FROM php:8.5-cli-alpine

# Composer, handy for running composer commands inside the container
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8080

# PHP's built-in web server, routing every request through the Symfony front controller
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
