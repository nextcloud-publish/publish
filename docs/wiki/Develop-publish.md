[`docker/dev/`](https://github.com/nextcloud-publish/publish/tree/main/docker/dev) holds a dev
setup that runs publish, ssg-worker and a Nextcloud mock on one machine with Docker Compose. Both
repos are mounted into their containers, so code changes need no rebuild.

`docker/dev/compose.yaml`, project `publish-dev`:

```text
nginx-fpm-gateway      nginx on :8080, passes http request to publish over FastCGI
publish                PHP-FPM, the API; sends each build to RabbitMQ
rabbitmq               queue q.builds; AMQP on :5672, management UI on :15672
ssg-worker x2          takes builds from q.builds, writes the sites to docker/dev/published/
collectives-api-mock   nginx: content archives and status callbacks on :8082, published/ on :8081
```

## Setup

Needs Docker with [Compose v2](https://docs.docker.com/compose/install/), and both repos cloned into the same parent directory:

```text
publish-service/
  publish/      this repo, run compose from here
  ssg-worker/   built and mounted by docker/dev/compose.yaml
```

```bash
mkdir publish-service && cd publish-service
git clone https://github.com/nextcloud-publish/publish.git
git clone https://github.com/nextcloud-publish/ssg-worker.git
cd publish

# publish and ssg-worker run as your uid and gid, so they can write var/ in the checkouts
cp docker/dev/.env.example docker/dev/.env
sed -i -e "s/_UID=.*/_UID=$(id -u)/" -e "s/_GID=.*/_GID=$(id -g)/" docker/dev/.env

# Shared with nextcloud-docker-dev, see Develop publish with nextcloud-docker-dev
docker network create collectives-dev

docker compose -f docker/dev/compose.yaml up --build
```

All commands on this page run from `publish/`.

## Run a build

Send publish the build request Collectives would send. ssg-worker then downloads a sample
collective from `collectives-api-mock` and reports the build status back to it:

```bash
curl -sS -X POST localhost:8080/build \
  -H 'Authorization: Bearer dev-api-token-0123456789' \
  -H 'Content-Type: application/json' \
  -d '{"static_site_id":"11f5b798-6f34-4951-ad8b-bfd623ded5c2","slug":"demo-site","title":"My Team Handbook",
       "content_download_url":"http://collectives-api-mock:8082/content/sample-collective.tar.gz",
       "callback_status_url":"http://collectives-api-mock:8082/status/1234"}'
```

publish answers `202` with `{"status":"enqueued"}`: the build is queued, not done yet.

The sample pages are in `docker/dev/collectives-api-mock/content/sample-collective/`. After changing
them, rebuild the mock with `docker compose -f docker/dev/compose.yaml up -d --build collectives-api-mock`.

## See the result

```bash
# 200 once ssg-worker has published the site
curl -I http://127.0.0.1:8081/11f5b798-6f34-4951-ad8b-bfd623ded5c2/demo-site/

# The status callback ssg-worker sent
docker compose -f docker/dev/compose.yaml logs collectives-api-mock | grep CALLBACK

# The published files
ls -R docker/dev/published/11f5b798-6f34-4951-ad8b-bfd623ded5c2/demo-site/
```

A successful build shows this callback:

```text
CALLBACK PATCH /status/1234 -> 204 body={"status":"published","data":{"publish_url":"http://127.0.0.1:8081/11f5b798-6f34-4951-ad8b-bfd623ded5c2/demo-site/"}}
```

Open `http://127.0.0.1:8081/11f5b798-6f34-4951-ad8b-bfd623ded5c2/demo-site/` in a browser to see the site.
`http://127.0.0.1:8081/` lists every published `static_site_id`.

If the site or the callback doesn't show up, follow the build in the logs, see
[Logs and debugging](#logs-and-debugging). ssg-worker adds `build_id`, `static_site_id`, `slug` and
`attempt` to every line it logs for a build, and publish logs the same ids with `Build enqueued`.

## With nextcloud-docker-dev

To build from Collectives in a local Nextcloud instead of `collectives-api-mock`, see
[Develop publish with nextcloud-docker-dev](https://github.com/nextcloud-publish/publish/wiki/Develop-publish-with-nextcloud-docker-dev).

## Ports

| Port | What |
| --- | --- |
| 8080 | the API, token `dev-api-token-0123456789` |
| 5672 | RabbitMQ AMQP, for a worker running on the host |
| 15672 | RabbitMQ management UI, `publish` / `secret` |
| 8081 | the published sites, from `docker/dev/published/` |
| 8082 | the Nextcloud mock: content archives under `/content/`, status callbacks under `/status/` |

All ports are bound to `127.0.0.1`. All services run in the time zone `TZ`, default
`Europe/Berlin`.

## Change code

The commands below leave out `-f docker/dev/compose.yaml`.

| You change | It takes effect |
| --- | --- |
| publish code or config | on the next request |
| ssg-worker code or config | after `docker compose restart ssg-worker`; the worker is a long-running process |
| `composer.json` in either repo | after `docker compose up --build --renew-anon-volumes`; `vendor/` lives in the image |
| `docker/nginx/default.conf` | after `docker compose restart nginx-fpm-gateway` |
| `docker/dev/rabbitmq/` | after `docker compose restart rabbitmq`; it reads the files when it starts |
| a Dockerfile | after `docker compose up --build` |

## Logs and debugging

Both services log at `debug` in dev, to stderr and to a file in their checkout:

```bash
# Follow both services
docker compose -f docker/dev/compose.yaml logs -f publish ssg-worker

# Find a build's id by its static_site_id, then all its lines from both services
grep '"static_site_id":"<static_site_id>"' var/log/publish.log
grep -h '<build_id>' var/log/publish.log ../ssg-worker/var/log/ssg-worker.log

# Jobs waiting in the queue
docker compose -f docker/dev/compose.yaml exec rabbitmq rabbitmqctl list_queues name messages

# A shell in a container, e.g. to run bin/console
docker compose -f docker/dev/compose.yaml exec publish sh
docker compose -f docker/dev/compose.yaml exec ssg-worker sh
```

| Service | File |
| --- | --- |
| publish | `var/log/publish.log` |
| ssg-worker | `../ssg-worker/var/log/ssg-worker.log` |

[Troubleshooting](https://github.com/nextcloud-publish/publish/wiki/Troubleshooting#log-messages)
explains the log messages.

- `APP_DEBUG` is on, so an error in publish answers with Symfony's error page and stack trace.
- The management UI on port 15672 shows whether a job is waiting on `q.builds`, and the
  `delays` exchange while a build waits for its retry.
- `docker/dev/published/` holds the published sites, `docker/dev/build_temp/` a running build.

## PHPUnit

The tests send the token from `.env.test`. The container's `PUBLISH_API_TOKEN` would win over it,
so set it for the run:

```bash
docker compose -f docker/dev/compose.yaml exec -e PUBLISH_API_TOKEN=test-api-token-0123456789 publish php bin/phpunit
```

With PHP 8.5 and `composer install` on the host, `php bin/phpunit` works too. The tests use an
in-memory transport and need no broker. `tests/OpenApiTest.php` checks every answer against
`openapi.yaml`.

## PHPStan

PHPStan checks `src/` and `tests/` at level 10. It reads the service container from
`var/cache/dev/`, so warm the cache first:

```bash
docker compose -f docker/dev/compose.yaml exec publish sh -c 'php bin/console cache:warmup && vendor/bin/phpstan analyse'
```

## Code style

PHP-CS-Fixer checks `src/` and `tests/` against PSR-12; `fix` reformats them:

```bash
docker compose -f docker/dev/compose.yaml exec publish vendor/bin/php-cs-fixer check --diff
docker compose -f docker/dev/compose.yaml exec publish vendor/bin/php-cs-fixer fix
```

## pre-commit

[pre-commit](https://pre-commit.com/) runs the CI lint steps, the style check, PHPStan and the
tests before each commit. It needs PHP 8.5, `ext-amqp` and `composer install` on the host. Install
the hook once:

```bash
pre-commit install
```

CI also lints `openapi.yaml` with Redocly. To lint it and render the page locally (needs Node.js):

```bash
npx @redocly/cli lint openapi.yaml
```

```bash
npx @redocly/cli build-docs openapi.yaml -o var/api-docs/index.html
```

## Release

A release is a tag `X.Y.Z` on `main`. CI builds only tags of this form:

1. Turn `## [Unreleased]` in `CHANGELOG.md` into a version section, add its link at the bottom,
   commit and push to `main`.
2. Tag the commit and push the tag:

   ```bash
   git tag -a 1.2.3 -m 1.2.3
   git push origin 1.2.3
   ```

CI pushes the image with the tags listed in
[Deploy publish](https://github.com/nextcloud-publish/publish/wiki/Deploy-publish#7-update), then
creates the GitHub release from the version's CHANGELOG section, with the `docker pull` command
after its first paragraph. A paragraph before the first `###` heading becomes the summary at the
top of the release, and a second one, e.g. what to change before updating, follows the pull
command.
