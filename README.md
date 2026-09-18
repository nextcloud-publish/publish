# publish-api

A minimal Symfony 8.1 REST API. No database, no templating — just JSON. It accepts
build requests and puts them on RabbitMQ for `ssg-worker` to pick up.

## Endpoints

| Method | Path      | Response                                                            |
| ------ | --------- | ------------------------------------------------------------------- |
| GET    | `/health` | `{"status":"ok"}` — public, no token                                |
| POST   | `/build`  | `202` `{"status":"enqueued"}`                                       |
|        |           | `400` `{"status":"invalid",...}` — a required field is missing      |
|        |           | `401` `{"status":"unauthorized",...}` — missing or invalid token    |
|        |           | `503` `{"status":"error",...}` — the broker was unreachable         |

`POST /build` requires `static_site_id`, `slug`, `content_download_url` and
`callback_status_url`. Anything else in the body is ignored. The `202` is only sent
after the broker confirms the publish, so it is never returned for a job that was not
actually queued.

## Authentication

`POST /build` requires a shared secret, sent as a bearer token:

```
Authorization: Bearer <PUBLISH_API_TOKEN>
```

| Variable            | Required | Default | Description                              |
| ------------------- | -------- | ------- | ---------------------------------------- |
| `PUBLISH_API_TOKEN` | yes      | none    | Shared secret for protected endpoints    |

`PUBLISH_API_TOKEN` has no fallback. Left unset, Symfony cannot resolve the env var and
the application fails to serve; left blank, every request gets a `401`.

Generate one per deployment:

```bash
openssl rand -hex 32
```

A missing token and a wrong one return the same `401` body, so the response cannot be
used to tell which half of a guess was right. The check runs before the request body is
parsed, and `401` responses carry `WWW-Authenticate: Bearer`.

`/health` deliberately stays public so container healthchecks and monitoring can reach
it — `HealthController` simply does not inject `ApiTokenCheck`. There is no firewall
config and no route allow-list: an endpoint is protected exactly when its controller
asks for `ApiTokenCheck`, which is also what a new controller has to do to opt in.

> **Deploying behind Apache:** Apache with PHP-FPM strips the `Authorization` header
> unless `CGIPassAuth On` is set for the vhost (or it is forwarded with a
> `SetEnvIf Authorization` rewrite). Without that, every request arrives without a token
> and gets a `401`. nginx passes it through unchanged.

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
framework default, which is PHP `serialize()`). `App\Message\BuildJob` therefore defines
the message shape, and its property names are the JSON keys:

```json
{ "build_id": "...", "static_site_id": "...", "slug": "...",
  "content_download_url": "...", "callback_status_url": "...", "created_at": "..." }
```

Messenger also adds `type` and `X-Message-Stamp-*` AMQP headers alongside the body.

`ssg-worker` consumes `q.builds` through Symfony Messenger too, decoding the JSON body
and its `type` header back into its own `App\Message\BuildJob`.

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
PUBLISH_API_TOKEN=$(openssl rand -hex 32) php -S localhost:8080 -t public public/index.php
curl -s localhost:8080/health
curl -s -X POST localhost:8080/build \
  -H "Authorization: Bearer $PUBLISH_API_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"static_site_id":"1234-5678","slug":"demo","content_download_url":"https://example.org/a.tar.gz","callback_status_url":"https://example.org/status"}'
```

`PUBLISH_API_TOKEN` has to be set for the server to start serving — see
[Authentication](#authentication).

## Docker (dev stack)

The whole system: this API, a RabbitMQ node, `ssg-worker`, and a mock that stands in
for the Nextcloud Collectives content API.

`ssg-worker` is built from a **sibling checkout** at `../ssg-worker`, so clone both
repos into the same parent directory first.

```bash
docker compose -f docker/dev/compose.yaml up --build
curl -s localhost:8080/health
```

| Port | What |
| --- | --- |
| 8080 | this API |
| 15672 | RabbitMQ management UI (`app` / `secret`) |
| 8081 | the published sites, served read-only from `docker/dev/published/` |
| 8082 | the content archives the mock hands out |
| 8083 | the status-callback sink — read it with `docker compose -f docker/dev/compose.yaml logs collectives-mock` |

The management UI is where you confirm a job actually landed on `q.builds` and inspect
its body and headers. Port 8083 is where you see what the worker reported back, which
is the only place a build's outcome is visible.

A build end to end:

```bash
curl -sS -X POST localhost:8080/build \
  -H 'Authorization: Bearer dev-api-token-0123456789' \
  -H 'Content-Type: application/json' \
  -d '{"static_site_id":"demo","slug":"demo-site","title":"My Team Handbook",
       "content_download_url":"http://collectives-mock/sample-collective.tar.gz",
       "callback_status_url":"http://collectives-mock:8083/status/1234"}'

curl -I http://127.0.0.1:8081/demo-site/     # expect 200
```

Swap the download URL for `not-an-archive.tar.gz` to exercise the retry path (two
build attempts, ~15s and ~60s apart, then one `failed` callback) or
`no-pages.tar.gz` for a terminal failure, which is reported immediately with no
retries.

The project directory is bind-mounted into the container, so code changes are
picked up without rebuilding. Install PHP dependencies once on the host (or via
`docker compose -f docker/dev/compose.yaml exec app composer install`) so
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
config/services.yaml       binds PUBLISH_API_TOKEN into ApiTokenCheck
src/
  Controller/              HealthController, BuildController
                           ApiTokenCheck: shared bearer-token check the controllers inject
  Message/BuildJob.php     the message shape for q.builds
tests/Controller/          HealthControllerTest, BuildControllerTest, BuildEnqueueTest,
                           ApiTokenCheckTest
docker/
  Dockerfile               php:8.5-cli-alpine + ext-amqp + built-in server
  dev/compose.yaml         dev stack: api + RabbitMQ + ssg-worker + mock
  dev/collectives-mock/    content API stand-in, published-tree server, callback sink
  dev/published/           bind mount the worker promotes into (gitignored)
  rabbitmq-config/         definitions.json (topology) + rabbitmq.conf
```
