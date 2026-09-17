# publish-api

A minimal Symfony 8.1 REST API. No database, no templating — just JSON. It accepts
build requests and puts them on RabbitMQ for `ssg-worker` to pick up.

## Endpoints

| Method | Path      | Response                                                            |
| ------ | --------- | ------------------------------------------------------------------- |
| GET    | `/health` | `{"status":"ok"}` — public, no token                                |
| POST   | `/build`  | `202` `{"status":"enqueued"}`                                       |
|        |           | `400` `{"status":"invalid",...}` — a required field is missing      |
|        |           | `401` empty body — missing or invalid token, see below              |
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

`PUBLISH_API_TOKEN` has no fallback. Left blank, every request gets a `401`. Left unset,
the env var cannot be resolved, so a caller that actually sends a token gets a `500` and
nothing is ever enqueued. Either way a misconfigured deployment is closed, not open.

Generate one per deployment:

```bash
openssl rand -hex 32
```

The scheme is matched case-sensitively and the token must consist of
`A-Z a-z 0-9 - _ + ~ / .` with optional trailing `=` — `openssl rand -hex 32`, base64 and
base64url all qualify. `bearer <token>` in lower case does not.

`401` responses have an empty body and carry the challenge in the header, per RFC 6750:

| Situation | `WWW-Authenticate` |
| --- | --- |
| No token, or one the header parser rejects | `Bearer` |
| A token that parsed but did not match | `Bearer error="invalid_token",error_description="Invalid credentials."` |

Authentication is a firewall, configured in `config/packages/security.yaml` — not
something each controller opts into. One `access_control` rule requires `ROLE_API` for
every path, so **a new endpoint is protected by default** and opting out means editing
that file. The check runs on `kernel.request`, before the controller is resolved, so a
request without a valid token never reaches any application code — a malformed JSON body
without a token is a `401`, not a `500`. Routing runs earlier still, so an unregistered
path is a `404` rather than a `401`.

`/health` deliberately stays public so container healthchecks and monitoring can reach
it. It has its own firewall with `security: false`, which means no authenticator ever
inspects its requests: a probe that sends a stale or malformed `Authorization` header
still gets a `200`. A health check that can fail authentication is useless to a load
balancer.

`docs/security-bundle.md` records why this replaced the hand-written check, and what it
cost.

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

- PHP >= 8.4.1 and [Composer](https://getcomposer.org/) for local runs. `ext-ctype`,
  `ext-iconv` and `ext-xml` are declared in `composer.json`; the last one comes from
  `symfony/security-bundle`.
- [Docker](https://www.docker.com/) with Compose v2 for the containerized dev stack
- `ext-amqp` to actually publish. `symfony/amqp-messenger` requires it, so `composer
  install` refuses to run on a host without it; pass
  `--ignore-platform-req=ext-amqp` to install anyway. Everything then works except
  publishing — the whole test suite passes (the tests swap in an in-memory transport)
  but `POST /build` answers `503`. The Docker image installs it with
  [PIE](https://github.com/php/pie); use the dev stack if you need a working `/build`
  locally.

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

`PUBLISH_API_TOKEN` has to be set for `POST /build` to work at all — see
[Authentication](#authentication). `/health` does not need it.

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
  packages/security.yaml   the firewalls, and the one access_control rule
public/index.php           Front controller
config/services.yaml       binds PUBLISH_API_TOKEN into ApiTokenHandler
src/
  Controller/              HealthController, BuildController
  Security/                ApiTokenHandler: compares the token, yields the API identity
                           BearerEntryPoint: the 401 for a request with no credentials
  Message/BuildJob.php     the message shape for q.builds
tests/Controller/          HealthControllerTest, BuildControllerTest, BuildEnqueueTest
tests/Security/            ApiTokenHandlerTest
docs/security-bundle.md    why SecurityBundle replaced the hand-written check
docker/
  Dockerfile               php:8.5-cli-alpine + ext-amqp + built-in server
  compose.dev.yaml         dev stack: api + RabbitMQ
  rabbitmq-config/         definitions.json (topology) + rabbitmq.conf
```
