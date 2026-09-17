# publish-api

A minimal Symfony 8.1 REST API. No database, no templating — just JSON. It accepts
build requests and puts them on RabbitMQ for `ssg-worker` to pick up, and it ships the
**result worker** that publishes finished sites and reports every build's outcome back to
the caller.

Two processes, one image. `php -S` serves the API; `messenger:consume` drains the result
queues. They never run in the same container — see
[docs/build-pipeline.md](docs/build-pipeline.md) for the whole system, what changed to
build it, and the AMQP behaviour that will bite you if you touch it.

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

## The status callback

Every build ends with one `POST` to the `callback_status_url` from its request:

```http
POST <callback_status_url>
Content-Type: application/json

{
  "build_id": "16ef078ad37fd894",
  "static_site_id": "11f5b798-6f34-4951-ad8b-bfd623ded5c2",
  "status": "success",
  "finished_at": "2026-09-16T12:00:00+00:00"
}
```

`status` is `success` or `failed`. On `failed` the body also carries `error`, a short
reason taken from the build (`"Extracting content.tar.gz failed (exit 2): not in gzip
format"`). Any `2xx` is acceptance.

**Delivery is at-least-once, and the receiver must dedupe on `(build_id, status)`.**
Messenger acknowledges a message only after the handler returns, so a retry, a broker
requeue, or the worker being killed between the POST and the acknowledgement all deliver
the same callback again. This is a property of the design rather than a bug to fix: the
alternative is acknowledging before the work is durable, which loses outcomes instead of
repeating them.

How failures are treated:

| Response | Treatment |
| --- | --- |
| `2xx` | delivered |
| `5xx`, `408`, `429`, connection or read timeout | retried — 8s, 16s, 32s, 64s, then parked |
| other `4xx` | parked immediately; it will not start working on the fifth attempt |
| `3xx` | parked; redirects are not followed, so a moved endpoint has to be re-supplied |

A parked message goes to `q.build-dead` and nothing else happens automatically. The site
is still published — only the notification failed.

> **Not implemented yet: authenticating the callback.** Nothing signs or authenticates
> this request, so a receiver currently cannot verify it came from us. That belongs with
> the inbound auth work in `todo.md` and should be settled with the Nextcloud side before
> this is exposed to anything but a dev stack.

The URL itself is treated as untrusted throughout: only `http`/`https` are called, private
address ranges are blocked with `NoPrivateNetworkHttpClient`, and redirects are refused.
It is attacker-controlled and validated nowhere upstream — `BuildController` only checks
that it is present.

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

### Queues

| Queue | Written by | Drained by |
| --- | --- | --- |
| `q.builds` | this API | `ssg-worker` |
| `q.build-results` | `ssg-worker`, on a successful build | the result worker |
| `q.build-failures` | `ssg-worker`, on a failed build | the result worker |
| `q.build-dead` | Messenger, once a result's retries are spent | nobody — a parking lot |

Plus two exchanges. `delays` holds Messenger's retry queues, and `x.build-dead` is a
fanout — the only binding in the topology, and load-bearing rather than stylistic:
`AmqpSender` leaks the *original* routing key onto a message sent to a failure transport,
so publishing it to the default exchange would put it straight back on the queue it just
failed out of, forever.

Adding these was an additive change to `definitions.json`, so it needed only
`docker compose restart rabbitmq`, not `down -v`. Changing an existing queue's arguments
still does require recreating the broker.

### Draining the parking queue

`messenger:failed:show` **does not work here** — `AmqpReceiver` is not a
`ListableReceiverInterface`, so the command throws. Inspect `q.build-dead` in the
management UI instead. To replay, either:

```bash
php bin/console messenger:failed:retry          # interactive, one message at a time
php bin/console messenger:consume build_dead    # drain everything back through the handlers
```

Both handlers carry a second `from_transport: build_dead` tag so a replay finds them.

## The result worker

`messenger:consume build_failures build_results` — failures first, because
`messenger:consume` drains its transports in strict priority rather than round-robin, and
a busy results queue would otherwise starve failures indefinitely.

On a **success** it promotes the rendered site and then reports:

1. move the build's `output/` into `PUBLISHED_DIR/.staging/<build_id>` — a rename when
   they share a mount, a copy via a `.partial` scratch name when they do not
2. `chmod` it to `0755` — `ssg-worker` creates `output/` at `0750`, which nothing else
   could read
3. `rename()` any live site aside, then `rename()` the new one into place
4. delete the retired site and the build's whole temp tree, `input/` included
5. `POST` the callback

Steps 3 is two renames rather than one because `rename()` onto an existing non-empty
directory fails with `ENOTEMPTY` and never merges — a single rename would work for a
site's first build and break on every rebuild. Doing it this way also means the window
where the site is absent is two syscalls wide instead of however long a recursive delete
takes, and a crash inside it leaves both trees on disk.

On a **failure** it moves the job's directory to `FAILED_DIR/<build_id>` and reports. That
move is best effort: a build that failed *because* its `static_site_id` was unsafe never
had a directory, and that is exactly when the caller most needs to hear why.

Every step is idempotent, because the callback runs last and a failed callback replays the
whole handler. A replay costs three `stat()` calls before it reaches the POST again.

## Environment variables

| Variable | Required | Default | Description |
| --- | --- | --- | --- |
| `AMQP_DSN` | yes | none | RabbitMQ connection string, e.g. `amqp://app:secret@rabbitmq:5672/%2f` |
| `AMQP_HEARTBEAT` | no | `10` | AMQP heartbeat in seconds; must match the broker |
| `JOB_STORAGE_DIR` | yes | none | Root of the build temp tree, shared with `ssg-worker` |
| `PUBLISHED_DIR` | yes | none | Where finished sites are published; served by nginx |
| `FAILED_DIR` | yes | none | Where failed builds are quarantined |

The last three have no fallback, for the same reason `AMQP_DSN` does not: guessing a path
means builds are looked for somewhere nothing writes, which should fail loudly.

**Whether these share a mount point changes how a build is moved.** `rename(2)` rejects
`old_path.mnt != new_path.mnt` with `EXDEV` — mount *points*, not filesystems, so two bind
mounts of one host directory still count as different — and PHP has no directory fallback.

The dev stack deliberately splits them: build temp is a named volume (high churn, nobody
reads it by hand) while `published/` and `build_failed/` are bind mounts under
`docker/dev/` so a finished site or a failed build can be read straight off the host. So
every move between them is a **copy**, via `Filesystem::moveDir()`.

Two consequences worth knowing:

- The copy is not atomic, so it lands on a `<build_id>.partial` scratch name on the
  destination and is renamed into place only once complete. A crash mid-copy leaves the
  scratch name, which nothing publishes. What *stays* atomic is the part that matters —
  the staging and retiring directories both live inside `PUBLISHED_DIR`, so swapping the
  live site is still two renames within one mount.
- The copy is not instant, and the message stays unacked throughout it. A large enough
  site could in principle run past the broker's `consumer_timeout` (30s dev, 300s prod)
  and be redelivered.

Point all three at one mount and every copy silently becomes a rename again — the code
tries that first and needs no change.

## Requirements

- PHP >= 8.4 and [Composer](https://getcomposer.org/) for local runs
- [Docker](https://www.docker.com/) with Compose v2 for the containerized dev stack
- `ext-amqp` to actually publish. It is not declared in `composer.json`, so a host
  without it can still `composer install` and run the whole test suite (the tests swap
  in an in-memory transport) — but `POST /build` will answer `503`. The Docker image
  installs it with [PIE](https://github.com/php/pie); use the dev stack if you need a
  working `/build` locally.
- `ext-pcntl` for the result worker to shut down cleanly. The image installs it; without
  it `docker stop` kills the worker mid-message and the status callback is replayed on
  every redeploy.

## Local development

```bash
composer install
php -S localhost:8080 -t public public/index.php
curl -s localhost:8080/health
```

## Docker (dev stack)

The whole system: API, broker, build worker, result worker, and a stand-in for the
Nextcloud Collectives content API.

```bash
docker compose -f docker/dev/compose.yaml up --build
curl -s localhost:8080/health
```

**`ssg-worker` is built from a sibling checkout** (`../../../ssg-worker`), so clone both
repos into the same parent directory or the two worker services will not build.

| Port | What |
| --- | --- |
| 8080 | the API |
| 15672 | RabbitMQ management UI (`app` / `secret`) |
| 8081 | published sites, read-only |
| 8082 | the Collectives mock's content archives |

`collectives-mock` serves `sample-collective.tar.gz`, built at image build time from the
markdown under `docker/dev/collectives-mock/content/`. Edit a page and
`docker compose build collectives-mock` to regenerate it. It also serves
`not-an-archive.tar.gz`, which is plain text — one URL swap in a build request exercises
the whole failure path.

An end-to-end build:

```bash
curl -s localhost:8080/build -H 'Content-Type: application/json' -d '{
  "static_site_id": "demo-site",
  "slug": "Demo Collective",
  "content_download_url": "http://collectives-mock/sample-collective.tar.gz",
  "callback_status_url": "http://example.invalid/status"
}'
```

Then watch it land on `q.builds`, move through `q.build-results`, and appear at
<http://localhost:8081/demo-site/>. The callback URL above is deliberately unreachable, so
this also demonstrates the retry schedule and the message eventually parking on
`q.build-dead`.

The project directory is bind-mounted into the containers, so code changes are picked up
without rebuilding. `vendor/` is the exception: the image installs it at build time and an
anonymous volume keeps the bind mount from shadowing it, so a host-side `composer require`
needs `docker compose build` before the containers see it.

## Tests

```bash
php bin/phpunit
```

`tests/Message/MessageContractTest.php` is worth knowing about: it pins the exact `type`
header and JSON body of all three messages against hard-coded fixtures, and **the same
file with the same fixtures exists in `ssg-worker`**. Those classes are duplicated by hand
across the two repos, so renaming a property on one side is otherwise a decode failure in
production that nothing catches first. If that test needs editing, the other repo needs
the same edit in the same commit.

**What the test suite cannot catch.** `InMemoryTransportFactory` discards its options
entirely, so every AMQP detail is invisible offline: a wrong queue name, a queue or
exchange missing from `definitions.json`, the `exchange:` block whose absence kills a
retrying worker, the delay exchange, the quorum-queue redeclare, the routing-key loop on
the failure transport, strict-priority starvation, `EXDEV` across mounts, and the
directory permissions nginx needs. Passing tests say nothing about whether
`messenger:consume` can connect. The dev stack above is the only thing that exercises any
of it.

## Layout

```
config/
  packages/messenger.yaml  builds + result transports, routing, failure transport
  services.yaml            JobLayout paths, SSRF-guarded HTTP client, handler tags
public/index.php           Front controller
src/
  Controller/              HealthController, BuildController
  Message/                 BuildJob, BuildSucceeded, BuildFailed (hand-synced with
                           ssg-worker) + the two result handlers
  Storage/                 JobLayout: every path in one place
                           BuildPromoter: atomic, idempotent publish
                           BuildQuarantine: move a failed build aside
                           Filesystem: primitives that fail with the real reason
  Callback/StatusNotifier  the outbound POST, and the retryable/permanent split
tests/
  Message/MessageContractTest.php   the cross-repo wire contract (mirrored in ssg-worker)
docker/
  Dockerfile               php:8.5-cli-alpine + ext-amqp + ext-pcntl + vendor/
  dev/compose.yaml         the whole stack
  dev/collectives-mock/    Nextcloud Collectives stand-in; also serves published sites
  rabbitmq-config/         definitions.json (topology) + rabbitmq.conf
docs/                      the two design decisions and their reasoning
```
