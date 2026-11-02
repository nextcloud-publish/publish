# The production setup, explained

What this MR changes in the Docker setup of publish and ssg-worker, and why. A working note
for the review; it is removed before the merge.

## Before and after

Before, each repo had one Dockerfile with two stages. Dev compose built `base` (dependencies,
no source) and mounted the checkout over it. CI pushed `release`, which was `base` plus the
source. So the image in GHCR was the dev image:

- dev dependencies installed (PHPUnit, PHPStan, debug bundles)
- `APP_ENV` from `.env`, which says `dev`, unless the deployment set it
- no cache, built on the first request after every start
- publish: no PHP server except `php -S`, PHP's built-in single-process dev server

Now each repo has two Dockerfiles:

```text
publish/docker/
  Dockerfile        prod image, the one CI pushes
  dev/Dockerfile    dev image, never built by CI
  php/php.ini       time zone and OPcache; prod and dev
  php/php-fpm.conf  the FPM pool; prod and dev
  nginx/default.conf passes every request to PHP-FPM; prod and dev
  compose.yaml      production sample: nginx, publish, RabbitMQ, ssg-worker
  prod-frankenphp/  the same sample with FrankenPHP, to compare

ssg-worker/docker/
  Dockerfile        prod image, the one CI pushes
  dev/Dockerfile    dev image, never built by CI
  php/php.ini       time zone; prod and dev
```

The two Dockerfiles of a repo are the same file except for these lines:

| | dev | prod |
|---|---|---|
| `composer install` | with dev dependencies | `--no-dev --no-autoloader` |
| source | none, compose mounts the checkout | `COPY . .` |
| autoloader | PSR-4, from `composer install` | `dump-autoload --classmap-authoritative` |
| `.env` | read at runtime, `APP_ENV` from compose | `dump-env prod` writes `.env.local.php` |
| cache | built at runtime in the checkout's `var/` | `cache:warmup` at build time |
| `OPCACHE_VALIDATE_TIMESTAMPS` (publish) | `1` | `0` |

## What each prod step does

### No dev dependencies

`composer install --no-dev` leaves out PHPUnit, PHPStan and everything else under
`require-dev`. Less code in the image is less code that can have a vulnerability, and the debug
bundles cannot be switched on by accident.

### Autoloader: PSR-4 and `--classmap-authoritative`

PHP loads a class by asking Composer's autoloader for its file.

- PSR-4 maps a namespace prefix to a folder: `App\` to `src/`. `App\Controller\BuildController`
  becomes `src/Controller/BuildController.php`, which the autoloader checks on disk and loads.
  This finds files created a second ago, which dev needs.
- A classmap is a list `class => file` that Composer writes once, by scanning all files, into
  `vendor/composer/autoload_classmap.php`. A lookup reads an array and touches no disk.
- Authoritative means a class missing from the list does not exist; there is no PSR-4 fallback.

Prod never gets new files, so the list is always complete.

### `composer dump-env prod`

Symfony normally parses `.env`, `.env.local`, `.env.prod` and `.env.prod.local` on every request.
`dump-env prod` merges them once, for `prod`, into a PHP array in `.env.local.php`. Symfony's
startup code looks for that file first and then skips the `.env` files.

- `APP_ENV=prod` is in the image; a forgotten variable cannot start it in dev mode.
- A PHP array is cheaper than parsing text, and OPcache keeps it in memory.
- Real environment variables still win: compose's `PUBLISH_API_TOKEN` or `AMQP_HEARTBEAT=60`
  override the file.

The command is a `composer` command because it comes from `symfony/flex`, a Composer plugin.

### Cache warmup

Symfony compiles its YAML config and PHP attributes into plain PHP in `var/cache/prod/`: the
service container as one class, the routes as a matcher, the Monolog, Messenger and security
config. `cache:warmup` does that at build time, so a new container serves its first request at
full speed and never writes the cache at runtime.

### OPcache (publish)

PHP compiles every `.php` file to bytecode before running it. OPcache keeps the bytecode in
memory shared by all FPM workers, so each file is compiled once. PHP 8.5 has it built in, and it
is on for FPM.

- `opcache.validate_timestamps` decides whether OPcache checks a file's modification time.
- Prod: `0`. The code in an image never changes; a new image is a new container with an empty
  cache.
- Dev: `1` with `revalidate_freq = 0`, so an edit shows on the next request.

ssg-worker runs as a CLI process, where OPcache is off. It is a long-running process that
compiles each file once anyway.

## publish runs on PHP-FPM behind nginx

Dev and prod run the same server:

```text
host proxy (TLS) -> web: nginx :8080 -> publish: PHP-FPM :9000 -> public/index.php
```

- nginx holds no copy of the app. `public/` holds only the front controller, so
  `nginx/default.conf` sends every request to `public/index.php`.
- `php-fpm.conf` replaces the image's FPM config: one pool on port 9000, logs to stderr,
  `clear_env = no` so the container's environment reaches PHP.
- `php -S` served one request at a time, so `/health` waited behind a `POST /build` waiting for
  the broker. FPM runs up to 10 workers.

## One home per service in the container

Each service writes only to `var/` under its project directory:

```text
/publish/
  var/cache/          built into the image
  var/log/            mounted from the host

/ssg-worker/
  var/cache/          built into the image
  var/log/            mounted from the host
  var/build_temp/     JOB_STORAGE_DIR, mounted
  var/published/      PUBLISHED_DIR, mounted; the host proxy serves it
```

`JOB_STORAGE_DIR` and `PUBLISHED_DIR` default to `%kernel.project_dir%/var/build_temp` and
`var/published`, so dev, prod and a local run outside Docker use the same layout without setting
them. Before, prod used `/opt/ssg/…` and dev `/ssg-worker/…`.

## What else differs between dev and prod, and why

### publish and nginx

| | dev | prod | why |
|---|---|---|---|
| user | your host uid (default 1000) | 10001 | dev writes `var/` in your checkout |
| debug | `APP_DEBUG=1` | off | stack traces only in dev |
| token, AMQP password | fixed, on the wiki page Development | required in `.env` | copyable curl commands in dev; secrets in prod |
| `LOG_LEVEL`, `SENTRY_DSN` | not set | from `.env` | dev logs `debug` by default; Sentry runs only in prod |
| logs | checkout's `var/log` | `logs/publish` | the prod container has no other writable mount |
| port | 8080 | `PUBLISH_PORT` | a prod host may run other services |
| healthcheck, restart | none | on `web`; `unless-stopped` | prod must come back after a crash or reboot |
| TZ | `Europe/Berlin` | `Europe/Berlin` | the images fall back to UTC without compose |

### RabbitMQ

| | dev | prod | why |
|---|---|---|---|
| image | `4-management`, UI on 15672 | `4` | dev checks `q.builds` in the UI |
| AMQP port | 127.0.0.1:5672 | not published | dev can run a worker on the host |
| user | `publish`, hash of `secret`, `administrator` | `publish`, hash set by the admin, no tags | the UI login needs the tag |
| `heartbeat` / `consumer_timeout` | 10 s / 120 s | 60 s / 300 s | dev notices a hung worker fast; prod must not drop a long build |
| `disk_free_limit` | 100 MB | 1 GB | room to react on a server before publishers block |

### ssg-worker

| | dev | prod | why |
|---|---|---|---|
| image | built from `../ssg-worker`, source mounted | `ghcr.io/…/ssg-worker:latest` | dev works on both repos at once |
| published sites | served by `collectives-mock` on :8081 | served by the host proxy | dev has no host proxy |
| Nextcloud | `collectives-mock` | the real Collectives instance | dev has no Nextcloud |

## The production sample

`docker/compose.yaml` runs the whole system behind a TLS proxy on the host:

- one `.env`, from `.env.example`; compose fails with a message if a required value is missing
- compose hands each service only its own variables, so `PUBLISH_API_TOKEN` reaches only publish
- `pages/` and `logs/` on the host, owned by uid 10001; build scratch in the `build-temp` volume
- RabbitMQ reads its user from `rabbitmq/definitions.json`, which holds a hash of
  `RABBITMQ_PASSWORD` made with `rabbitmqctl hash_password`

`docs/wiki/Deploy-publish.md` sets it up step by step.

## Until the merge

The prod sample pulls both app images from GHCR, and `:latest` and `:main` are still the old dev
image of each. Set `PUBLISH_IMAGE` and `SSG_WORKER_IMAGE` to the branch tags.
