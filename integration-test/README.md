# Integration stack

Runs `publish`, `ssg-worker` and the broker they share, so a build can be
followed from `POST /build` to rendered HTML. `docker/compose.dev.yaml` runs the
API alone; this stack adds the consumer and a fixture content server.

The two compose files claim the same host ports — run one or the other.

## Prerequisites

`ssg-worker` checked out next to this repo, since the stack builds it from
source (neither repo publishes a stable image tag):

```
<workspace>/
  publish/
  ssg-worker/
```

Nothing has to be installed on the host — not even `ext-amqp`. Both services
install their PHP dependencies inside their containers.

## Run it

```bash
docker compose -f integration-test/docker-compose.yml -f integration-test/docker-compose.nextcloud-docker-dev.yml up --build
```

The `api` container runs as uid/gid `1000` so the `vendor/` and `var/` it
generates in the bind-mounted repo belong to you rather than to root. If your
uid is not 1000, pass your own — otherwise those directories come out
unwritable from the host:

```bash
HOST_UID=$(id -u) HOST_GID=$(id -g) \
  docker compose -f integration-test/docker-compose.yml up --build
```

First boot pulls images, compiles `ext-amqp` with PIE in both images and runs
two `composer install`s, so it takes a few minutes. Wait for the API to answer:

```bash
curl -s localhost:8080/health
```

Then enqueue a build against the fixture archive:

```bash
curl -s -X POST localhost:8080/build \
  -H 'Authorization: Bearer dev-api-token-0123456789' \
  -H 'Content-Type: application/json' \
  -d '{"static_site_id":"demo-site-1",
       "slug":"Demo Site",
       "content_download_url":"http://content/sample-site.tar.gz",
       "callback_status_url":"http://content/ignored"}'
```

`202 {"status":"enqueued"}` means the broker confirmed the publish. The worker
logs each stage of the pipeline:

```bash
docker compose -f integration-test/docker-compose.yml logs -f worker
```

```
[INFO] prepared job workdir /srv/jobs/demo-site-1
[INFO] downloaded http://content/sample-site.tar.gz to .../content.tar.gz (N bytes)
[INFO] extracted .../content.tar.gz into .../input/content_unarchived
[INFO] rendered 4 page(s) into /srv/jobs/demo-site-1/output
```

Inspect the result on the shared volume. Go through `worker`, not `api`: the
worker creates every job directory as root with mode `0750` (hardcoded in its
`Helper::ensureDir`), and `api` runs as your host uid, so it cannot read them.

```bash
docker compose -f integration-test/docker-compose.yml exec worker \
  find /srv/jobs/demo-site-1/output -name '*.html'
```

Copy the rendered site out to open it in a browser:

```bash
docker compose -f integration-test/docker-compose.yml cp \
  worker:/srv/jobs/demo-site-1/output integration-test/rendered
```

`integration-test/rendered/` is gitignored, so copies of a build can be left
lying around.

## Services

| Service    | What it is                     | Host port                      |
| ---------- | ------------------------------ | ------------------------------ |
| `api`      | `publish`, built from `..`     | 8080                           |
| `worker`   | `ssg-worker`, from `../../`    | —                              |
| `rabbitmq` | broker, topology from the repo | 5672, 15672 (UI: `app`/`secret`) |
| `content`  | serves the fixture tar.gz      | 8081                           |

`api` and `worker` both bind-mount their repo, so code edits are live — restart
the service rather than rebuilding. Rebuild (`up --build`) only after changing a
`Dockerfile` or `composer.json`.

## The fixture

`fixtures/sample-site/` is a Collectives-shaped page tree: each folder is a page
whose body is its `Readme.md`, plain `.md` files next to it are leaf sub-pages.
The `content` service packs it into `sample-site.tar.gz` at container start, so
editing a page only needs `restart content` — nothing is committed pre-packed.

To test with real content instead, drop any Collectives export into
`fixtures/sample-site/` (including its `.attachments.<id>` folders), or point
`content_download_url` at any other reachable `http`/`https` tar.gz.

## Testing against a real nextcloud-docker-dev instance

To exercise a build against an actual Nextcloud/Collectives instance instead
of the `content` fixture, use `docker-compose.nextcloud-docker-dev.yml` on top
of this stack:

```bash
docker compose \
  -f integration-test/docker-compose.yml \
  -f integration-test/docker-compose.nextcloud-docker-dev.yml \
  up --build
```

## Resetting

Job output accumulates on the `job-storage` volume and rebuilding the same
`static_site_id` reuses its folders rather than clearing them, so a re-run
renders on top of the previous one. To start clean:

```bash
docker compose -f integration-test/docker-compose.yml down -v
```

This is also the only way to change the broker's topology: queue arguments are
immutable once created, so a re-import of `definitions.json` silently keeps the
existing `q.builds`.

## Notes

- A job that fails is not retried (`max_retries: 0` in the worker) and there is
  no dead-letter queue — the message is acked and the reason is only in the
  worker's log.
- `static_site_id` becomes a directory name and must match
  `/^[A-Za-z0-9_-]{1,128}$/`. The API does not check it; only the worker does,
  so a bad id is a `202` followed by a failed job.
- `callback_status_url` is required by the API but nothing consumes it yet.
