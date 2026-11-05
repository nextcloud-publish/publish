The dev stack runs the whole system on one machine: publish behind nginx, RabbitMQ with its
management UI, two ssg-worker instances, and a mock that stands in for Nextcloud. Both repos are
mounted into their containers, so code changes need no rebuild.

## Start it

Needs Docker with Compose v2, and both repos cloned into the same parent directory:

```bash
git clone https://github.com/nextcloud-publish/publish.git
```

```bash
git clone https://github.com/nextcloud-publish/ssg-worker.git
```

From `publish/`:

```bash
docker compose -f docker/dev/compose.yaml up --build
```

| Port | What |
| --- | --- |
| 8080 | the API, token `dev-api-token-0123456789` |
| 5672 | RabbitMQ AMQP, for a worker running on the host |
| 15672 | RabbitMQ management UI, `publish` / `secret` |
| 8081 | the published sites, from `docker/dev/published/` |
| 8082 | the Nextcloud mock: content archives under `/content/`, status callbacks under `/status/` |

All ports are bound to `127.0.0.1`. All services run in the time zone `TZ`, default
`Europe/Berlin`.

publish and ssg-worker run as uid and gid 1000, so they can write `var/` in the checkouts. If your
ids differ, create `docker/dev/.env`, which compose reads and git ignores, from the sample:

```bash
cp docker/dev/.env.example docker/dev/.env
```

Set your ids in it:

```bash
sed -i -e "s/_UID=.*/_UID=$(id -u)/" -e "s/_GID=.*/_GID=$(id -g)/" docker/dev/.env
```

`TZ` can be set there too.

A build end to end:

```bash
curl -sS -X POST localhost:8080/build \
  -H 'Authorization: Bearer dev-api-token-0123456789' \
  -H 'Content-Type: application/json' \
  -d '{"static_site_id":"11f5b798-6f34-4951-ad8b-bfd623ded5c2","slug":"demo-site","title":"My Team Handbook",
       "content_download_url":"http://collectives-mock:8082/content/sample-collective.tar.gz",
       "callback_status_url":"http://collectives-mock:8082/status/1234"}'
```

```bash
curl -I http://127.0.0.1:8081/11f5b798-6f34-4951-ad8b-bfd623ded5c2/demo-site/
```

The second command answers `200`, and the mock logs the callback:

```text
CALLBACK PUT /status/1234 -> 204 body={"status":"published","data":{"publish_url":"http://127.0.0.1:8081/11f5b798-6f34-4951-ad8b-bfd623ded5c2/demo-site/"}}
```

## Change code

The commands below run from `publish/` with `-f docker/dev/compose.yaml`, left out here.

| You change | It takes effect |
| --- | --- |
| publish code or config | on the next request |
| ssg-worker code or config | after `docker compose restart ssg-worker`; the worker is a long-running process |
| `composer.json` in either repo | after `docker compose up --build --renew-anon-volumes`; `vendor/` lives in the image |
| `docker/nginx/default.conf` | after `docker compose restart web` |
| `docker/dev/rabbitmq/` | after `docker compose restart rabbitmq`; it reads the files when it starts |
| a Dockerfile | after `docker compose up --build` |

## Logs and debugging

Both services log at `debug` in dev, to stderr and to a file in their checkout:

```bash
docker compose -f docker/dev/compose.yaml logs -f publish ssg-worker
```

| Service | File |
| --- | --- |
| publish | `var/log/publish.log` |
| ssg-worker | `../ssg-worker/var/log/ssg-worker.log` |

[Troubleshooting](https://github.com/nextcloud-publish/publish/wiki/Troubleshooting#trace-a-build)
shows how to follow one build through both files by its `build_id`.

- `APP_DEBUG` is on, so an error in publish answers with Symfony's error page and stack trace.
- The management UI on port 15672 shows whether a job is waiting on `q.builds`, and the
  `delays` exchange while a build waits for its retry.
- `docker compose logs collectives-mock` shows the status callbacks ssg-worker sent.
- `docker/dev/published/` holds the published sites, `docker/dev/build_temp/` a running build.

## Database

publish uses Doctrine ORM on Postgres 18. Development has two databases:

- **In the dev stack:** the `postgres` container, user `publish`, password `secret`, database
  `publish`. Only the `publish` container reaches it; its port is not published on the host.
- **On the host:** for `bin/console`, PHPUnit and the pre-commit hook run on the host. `.env`
  points them at `127.0.0.1:5432` with the same user and password.

| Task | Command |
| --- | --- |
| Add or change an entity | `php bin/console make:entity` |
| Generate a migration from the entity changes | `php bin/console make:migration --formatted` |
| Apply pending migrations | `php bin/console doctrine:migrations:migrate --no-interaction` |
| Run SQL | `php bin/console dbal:run-sql "SELECT version()"` |

Commit the generated files under `migrations/`; deployments apply them with `migrate`. Run them
on the host, or in the `publish` container with `docker compose -f docker/dev/compose.yaml exec
publish …` to act on the dev stack's database instead.

### Set up Postgres on the host

1. Install the PHP driver. Without it Doctrine fails with `could not find driver`.
   - Arch, Manjaro: `sudo pacman -S php-pgsql`, then enable `extension=pdo_pgsql` in
     `/etc/php/php.ini`.
   - Debian, Ubuntu: `sudo apt install php8.5-pgsql`.

   `php -m | grep pdo_pgsql` confirms it.
2. Install and start Postgres from your distribution, then create the user and database. The
   user needs `CREATEDB`, because the tests' database is created by it:

   ```bash
   sudo -u postgres psql -c "CREATE ROLE publish WITH LOGIN PASSWORD 'secret' CREATEDB;"
   sudo -u postgres createdb -O publish publish
   ```
3. `php bin/console dbal:run-sql "SELECT version()"` should print the server version.

If your host Postgres is not version 18, or you use another user or port, put your own
`DATABASE_URL` into `.env.local`, which git ignores, rather than changing `.env`. Keep
`serverVersion` at your server's major version, Doctrine generates its SQL for it:

```dotenv
DATABASE_URL="postgresql://publish:secret@127.0.0.1:5432/publish?serverVersion=17&charset=utf8"
```

`password authentication failed` or `no pg_hba.conf entry` means the server does not accept
password logins over TCP. Set the method of the `host … 127.0.0.1/32` line in its `pg_hba.conf`
(`/var/lib/postgres/data/` on Arch) to `scram-sha-256`, then `sudo systemctl reload postgresql`.

## PHPUnit

The tests send the token from `.env.test`. The container's `PUBLISH_API_TOKEN` would win over it,
so set it for the run:

```bash
docker compose -f docker/dev/compose.yaml exec -e PUBLISH_API_TOKEN=test-api-token-0123456789 publish php bin/phpunit
```

With PHP 8.5 and `composer install` on the host, `php bin/phpunit` works too. The tests use an
in-memory transport and need no broker. `tests/OpenApiTest.php` checks every answer against
`openapi.yaml`.

The tests do need a database: they use `publish_test` (Doctrine appends `_test` in the test env)
on the [host Postgres](#set-up-postgres-on-the-host). Create it once, and migrate it after every
new migration:

```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration
```

Inside the `publish` container the same commands use the dev stack's Postgres instead.

## PHPStan

PHPStan checks `src/` and `tests/` at level 10. It reads the service container from
`var/cache/dev/`, so warm the cache first:

```bash
docker compose -f docker/dev/compose.yaml exec publish sh -c 'php bin/console cache:warmup && vendor/bin/phpstan analyse'
```

## pre-commit

[pre-commit](https://pre-commit.com/) runs the CI lint steps, PHPStan and the tests before each
commit. It needs PHP 8.5, `ext-amqp`, `ext-pdo_pgsql` and `composer install` on the host, and
the [host Postgres](#set-up-postgres-on-the-host) running for the tests. Install the hook once:

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

A release is a git tag like `1.2.3`, without a leading `v`, on `main`. It must match the version
in `CHANGELOG.md`. CI then pushes the image with the tags listed in
[Deploy publish](https://github.com/nextcloud-publish/publish/wiki/Deploy-publish#8-update).
