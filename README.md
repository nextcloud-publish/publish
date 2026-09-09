# publish-api

A minimal Symfony 8.1 REST API. No database, no templating — just JSON. It accepts
build requests and puts them on RabbitMQ for `ssg-worker` to pick up.

## Endpoints

| Method | Path      | Response                                                       |
| ------ | --------- | -------------------------------------------------------------- |
| GET    | `/health` | `{"status":"ok"}`                                              |
| POST   | `/build`  | `202` `{"status":"enqueued"}`                                  |
|        |           | `400` `{"status":"invalid",...}` — a required field is missing |
|        |           | `503` `{"status":"error",...}` — the broker was unreachable    |

`POST /build` requires `static_site_id`, `slug`, `content_download_url` and
`callback_status_url`. Anything else in the body is ignored. The `202` is only sent
after the broker confirms the publish, so it is never returned for a job that was not
actually queued.

## Queueing

Enqueueing goes through [Symfony Messenger](https://symfony.com/doc/current/messenger.html):
`BuildController` dispatches an `App\Message\BuildJob` and
`config/packages/messenger.yaml` routes it to the `builds` transport. There is no
handler in this application — the routing entry is what makes `dispatch()` publish to
the broker instead of handling the message locally.

The transport publishes to the default exchange with `q.builds` as the routing key, and
declares no topology: the broker imports the queue, user and permissions from
`docker/rabbitmq-config/definitions.json` at boot and owns them, which is why
`auto_setup` is off.

Messages are serialized as JSON by Messenger's built-in `symfony_serializer` (not the
framework default, which is PHP `serialize()`). `App\Message\BuildJob` is therefore the
wire contract, and its property names are the JSON keys:

```json
{ "build_id": "...", "static_site_id": "...", "slug": "...",
  "content_download_url": "...", "callback_status_url": "...", "created_at": "..." }
```

Messenger also adds `type` and `X-Message-Stamp-*` AMQP headers alongside the body.

> **`ssg-worker` has not been converted to Messenger yet.** It still consumes `q.builds`
> with its own php-amqplib listener, which reads the raw body and knows nothing about
> Messenger's format. End-to-end builds therefore **fail** until that side is migrated;
> the API still returns `202` and the message still lands on the queue. This is known
> and temporary, not a bug to chase.

## Requirements

- PHP >= 8.4 and [Composer](https://getcomposer.org/) for local runs
- [Docker](https://www.docker.com/) with Compose v2 for the containerized dev stack
- `ext-amqp` to actually publish. It is not declared in `composer.json`, so a host
  without it can still `composer install` and run the whole test suite (the tests swap
  in an in-memory transport) — but `POST /build` will answer `503`. The Docker image
  installs it with [PIE](https://github.com/php/pie); use the dev stack if you need a
  working `/build` locally.

## Local development

```bash
composer install
php -S localhost:8080 -t public public/index.php
curl -s localhost:8080/health
```

## Docker (dev stack)

PHP's built-in web server plus a RabbitMQ node. The compose file lives in `docker/`, so
its build context is the repository root.

```bash
docker compose -f docker/compose.dev.yaml up --build
curl -s localhost:8080/health
```

The management UI is on <http://localhost:15672> (`app` / `secret`) — the place to
confirm a job actually landed on `q.builds` and to inspect its body and headers. Note
that this stack only runs the API; to exercise a build end to end with `ssg-worker`
alongside, use `integration-test/docker-compose.yml` instead of this file (they claim
the same host ports, so do not run both).

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
config/
  packages/messenger.yaml  builds transport + BuildJob routing
public/index.php           Front controller
src/
  Controller/              HealthController, BuildController
  Message/BuildJob.php     the q.builds wire contract
tests/Controller/          HealthControllerTest, BuildControllerTest, BuildEnqueueTest
docker/
  Dockerfile               php:8.5-cli-alpine + ext-amqp + built-in server
  compose.dev.yaml         dev stack: api + RabbitMQ
  rabbitmq-config/         definitions.json (topology) + rabbitmq.conf
```
