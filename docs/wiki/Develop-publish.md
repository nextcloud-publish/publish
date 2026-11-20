[`docker/dev/`](https://github.com/nextcloud-publish/publish/tree/main/docker/dev) holds a dev
setup that runs publish, publish-worker and a Nextcloud mock on one machine with Docker Compose. Both
repos are mounted into their containers, so code changes need no rebuild.

`docker/dev/compose.yaml`, project `publish-dev`:

```text
nginx-fpm-gateway      nginx on :8080, passes http request to publish over FastCGI
publish                PHP-FPM, the API; sends each build to RabbitMQ
rabbitmq               queue q.builds; AMQP on :5672, management UI on :15672
publish-worker x2      takes builds from q.builds, writes the sites to docker/dev/pages/
collectives-api-mock   nginx: content archives and status callbacks on :8082, pages/ on :8081
```

## Setup

Needs Docker with [Compose v2](https://docs.docker.com/compose/install/), and both repos cloned into the same parent directory:

```text
publish-service/
  publish/          this repo, run compose from here
  publish-worker/   built and mounted by docker/dev/compose.yaml
```

```bash
mkdir publish-service && cd publish-service
git clone https://github.com/nextcloud-publish/publish.git
git clone https://github.com/nextcloud-publish/publish-worker.git
cd publish

# publish and publish-worker run as your uid and gid, so they can write var/ in the checkouts
cp docker/dev/.env.example docker/dev/.env
sed -i -e "s/_UID=.*/_UID=$(id -u)/" -e "s/_GID=.*/_GID=$(id -g)/" docker/dev/.env

# Shared with nextcloud-docker-dev, see Develop publish with nextcloud-docker-dev
docker network create collectives-dev

docker compose -f docker/dev/compose.yaml up --build
```

All commands on this page run from `publish/`.

## Run a build

Send publish the build request Collectives would send. publish-worker then downloads a sample
collective from `collectives-api-mock` and reports the build status back to it:

```bash
curl -sS -X POST localhost:8080/static-sites \
  -H 'Authorization: Bearer dev-api-token-0123456789' \
  -H 'Content-Type: application/json' \
  -d '{"data":{"static_site_id":"11f5b798-6f34-4951-ad8b-bfd623ded5c2",
       "build_id":"0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b","slug":"demo-site","title":"My Team Handbook",
       "content_download_url":"http://collectives-api-mock:8082/content/sample-collective.tar.gz",
       "callback_status_url":"http://collectives-api-mock:8082/status/1234"}}'
```

publish answers `202` with `{"status":"enqueued"}`: the build is queued, not done yet. The same
request again answers `409`, since the site now exists; send a new `static_site_id` (`uuidgen`),
`build_id` (a UUIDv7: `uuidgen --time-v7`) and `slug` for another one.

The sample pages are in `docker/dev/collectives-api-mock/content/sample-collective/`. After changing
them, rebuild the mock with `docker compose -f docker/dev/compose.yaml up -d --build collectives-api-mock`.

## See the result

```bash
# 200 once publish-worker has published the site
curl -I http://127.0.0.1:8081/demo-site/

# The status callback publish-worker sent
docker compose -f docker/dev/compose.yaml logs collectives-api-mock | grep CALLBACK

# The published files
ls -R docker/dev/pages/demo-site/
```

A successful build shows this callback:

```text
CALLBACK PATCH /status/1234 -> 204 body={"status":"published","data":{"publish_url":"http:\/\/127.0.0.1:8081\/demo-site\/"}}
```

Open `http://127.0.0.1:8081/demo-site/` in a browser to see the site.
`http://127.0.0.1:8081/` lists every published slug.

## Update and delete

Once the site is `published`, rebuild it under a new slug, then delete it. Each request needs a new
`build_id` (`uuidgen --time-v7`):

```bash
curl -sS -X PATCH localhost:8080/static-sites/11f5b798-6f34-4951-ad8b-bfd623ded5c2 \
  -H 'Authorization: Bearer dev-api-token-0123456789' \
  -H 'Content-Type: application/json' \
  -d '{"data":{"build_id":"0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5c","slug":"handbook","title":"My Team Handbook",
       "content_download_url":"http://collectives-api-mock:8082/content/sample-collective.tar.gz",
       "callback_status_url":"http://collectives-api-mock:8082/status/1234"}}'

curl -sS -X DELETE localhost:8080/static-sites/11f5b798-6f34-4951-ad8b-bfd623ded5c2 \
  -H 'Authorization: Bearer dev-api-token-0123456789' \
  -H 'Content-Type: application/json' \
  -d '{"data":{"build_id":"0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5d",
       "callback_status_url":"http://collectives-api-mock:8082/status/1234"}}'
```

The update moves the site to `/handbook/`; the delete removes it and reports `deleted`. A second
request while the first is pending answers `409`.

If the site or the callback doesn't show up, follow the build in the logs, see
[Logs and debugging](#logs-and-debugging). publish-worker adds `build_id`, `static_site_id`, `slug` and
`attempt` to every line it logs for a build, and publish logs the same ids with `Build enqueued`.

## With nextcloud-docker-dev

To build from Collectives in a local Nextcloud instead of `collectives-api-mock`, see
[Develop publish with nextcloud-docker-dev](https://github.com/nextcloud-publish/publish/wiki/Develop-publish-with-nextcloud-docker-dev).

## Ports

| Port | What |
| --- | --- |
| 8080 | the API, token `dev-api-token-0123456789` |
| 5432 | Postgres, `publish` / `secret`, for `bin/console` and the tests on the host; `POSTGRES_PORT` in `docker/dev/.env` changes it |
| 5672 | RabbitMQ AMQP, for a worker running on the host |
| 15672 | RabbitMQ management UI, `publish` / `secret` |
| 8081 | the published sites, from `docker/dev/pages/` |
| 8082 | the Nextcloud mock: content archives under `/content/`, status callbacks under `/status/` |

All ports are bound to `127.0.0.1`. All services run in the time zone `TZ`, default
`Europe/Berlin`.

## Change code

The commands below leave out `-f docker/dev/compose.yaml`.

| You change | It takes effect |
| --- | --- |
| publish code or config | on the next request |
| publish-worker code or config | after `docker compose restart publish-worker`; the worker is a long-running process |
| `composer.json` in either repo | after `docker compose up --build --renew-anon-volumes`; `vendor/` lives in the image |
| `docker/nginx/default.conf` | after `docker compose restart nginx-fpm-gateway` |
| `docker/dev/rabbitmq/` | after `docker compose restart rabbitmq`; it reads the files when it starts |
| a Dockerfile | after `docker compose up --build` |

## Logs and debugging

Both services log at `debug` in dev, to stderr and to a file in their checkout:

```bash
# Follow both services
docker compose -f docker/dev/compose.yaml logs -f publish publish-worker

# Find a build's id by its static_site_id, then all its lines from both services
grep '"static_site_id":"<static_site_id>"' var/log/publish.log
grep -h '<build_id>' var/log/publish.log ../publish-worker/var/log/publish-worker.log

# Jobs waiting in the queue
docker compose -f docker/dev/compose.yaml exec rabbitmq rabbitmqctl list_queues name messages

# A shell in a container, e.g. to run bin/console
docker compose -f docker/dev/compose.yaml exec publish sh
docker compose -f docker/dev/compose.yaml exec publish-worker sh
```

| Service | File |
| --- | --- |
| publish | `var/log/publish.log` |
| publish-worker | `../publish-worker/var/log/publish-worker.log` |

[Troubleshooting](https://github.com/nextcloud-publish/publish/wiki/Troubleshooting#log-messages)
explains the log messages.

- `APP_DEBUG` is on, so an error in publish answers with Symfony's error page and stack trace.
- The management UI on port 15672 shows whether a job is waiting on `q.builds`, and the
  `delays` exchange while a build waits for its retry.
- `docker/dev/pages/` holds the published sites, `docker/dev/builds/` a running build.

## Database

publish uses Doctrine ORM on Postgres 18. Its one table, `static_site`, is mapped by
`src/Entity/StaticSite.php`. The dev stack's `postgres` container (user `publish`, password
`secret`, database `publish`) serves both the `publish` container and the host: it is published on
`127.0.0.1:5432`, where `.env` and `.env.test` point `bin/console`, PHPUnit and the pre-commit hook.
They need the dev stack running.

| Task | Command |
| --- | --- |
| Add or change an entity | `php bin/console make:entity` |
| Generate a migration from the entity changes | `php bin/console make:migration --formatted` |
| Apply pending migrations | `php bin/console doctrine:migrations:migrate --no-interaction` |
| Run SQL | `php bin/console dbal:run-sql "SELECT version()"` |

Commit the generated files under `migrations/`. The `publish` container applies pending ones when it
starts, in the dev stack too: `docker compose -f docker/dev/compose.yaml restart publish`. Run the
commands on the host, or in the `publish` container with `docker compose -f docker/dev/compose.yaml
exec publish …` to act on the dev stack's database instead.

### PHP on the host

The host's PHP needs the Postgres driver. Without it Doctrine fails with `could not find driver`.

- Arch, Manjaro: `sudo pacman -S php-pgsql`, then enable `extension=pdo_pgsql` in
  `/etc/php/php.ini`.
- Debian, Ubuntu: `sudo apt install php8.5-pgsql`.

`php -m | grep pdo_pgsql` confirms it, and `php bin/console dbal:run-sql "SELECT version()"`
prints the server version once the dev stack is up.

### Your own Postgres

A Postgres already on the host's `5432` collides with the published port: set `POSTGRES_PORT` in
`docker/dev/.env` to another port, or use your own server for the host. For your own server, or
another user or port, put your own `DATABASE_URL` into `.env.local`, which git ignores, rather than
changing `.env`. The user needs `CREATEDB` for the tests' database. Keep `serverVersion` at your
server's major version, Doctrine generates its SQL for it:

```dotenv
DATABASE_URL="postgresql://publish:secret@127.0.0.1:5432/publish?serverVersion=17&charset=utf8"
```

`password authentication failed` or `no pg_hba.conf entry` means the server does not accept
password logins over TCP. Set the method of the `host … 127.0.0.1/32` line in its `pg_hba.conf`
(`/var/lib/postgres/data/` on Arch) to `scram-sha-256`, then `sudo systemctl reload postgresql`.

## PHPUnit

With PHP 8.5, `pdo_pgsql` and `composer install` on the host, and the dev stack up:

```bash
php bin/phpunit
```

The tests use an in-memory transport and need no broker. `tests/OpenApiTest.php` checks every
answer against `openapi.yaml`. They do need a database: `publish_test` (Doctrine appends `_test`
in the test env) on the dev stack's Postgres. Create it once, and migrate it after every new
migration:

```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration
```

In the `publish` container the tests run too. The container's `PUBLISH_API_TOKEN` wins over the
token in `.env.test`, so set it for the run:

```bash
docker compose -f docker/dev/compose.yaml exec -e PUBLISH_API_TOKEN=test-api-token-0123456789 publish php bin/phpunit
```

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
tests before each commit. It needs PHP 8.5, `ext-amqp`, `ext-pdo_pgsql` and `composer install` on the host,
and the dev stack running for the tests' database. Install the hook once:

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
