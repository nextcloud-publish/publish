# publish-api

A minimal Symfony 8.1 REST API skeleton. No database, no templating — just JSON.

## Endpoint

| Method | Path      | Response            |
| ------ | --------- | ------------------- |
| GET    | `/health` | `{"status":"ok"}`   |

## Requirements

- PHP >= 8.4 and [Composer](https://getcomposer.org/) for local runs
- [Docker](https://www.docker.com/) with Compose v2 for the containerized dev stack

## Local development

```bash
composer install
php -S localhost:8080 -t public public/index.php
curl -s localhost:8080/health
```

## Docker (dev stack)

A single container running PHP's built-in web server. The compose file lives in
`docker/`, so its build context is the repository root.

```bash
docker compose -f docker/compose.dev.yaml up --build
curl -s localhost:8080/health
```

The project directory is bind-mounted into the container, so code changes are
picked up without rebuilding. Install PHP dependencies once on the host (or via
`docker compose -f docker/compose.dev.yaml exec app composer install`) so
`vendor/` is populated for the bind mount.

## Tests

```bash
php bin/phpunit
```

## Layout

```
config/                 Symfony configuration
public/index.php        Front controller
src/Controller/         HealthController
tests/Controller/       HealthControllerTest
docker/
  Dockerfile            php:8.5-cli-alpine + built-in server
  compose.dev.yaml      single-service dev stack
```
