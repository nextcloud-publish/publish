<p align="center">
  <img src="https://avatars.githubusercontent.com/u/291029189?s=300" width="150" alt="publish logo">
</p>

Publish builds static websites from Nextcloud Collectives. Nextcloud sends a build request to the
publish API; ssg-worker downloads the collective's content, renders the site into `pages/` and
reports the result back to Nextcloud.

## Technology

- publish and ssg-worker run PHP 8.5 on Alpine.
- Symfony 8.1. [Messenger](https://symfony.com/doc/current/messenger.html) sends the `BuildJob`
  as JSON in publish and consumes it in ssg-worker.
- [Monolog](https://symfony.com/doc/current/logging.html) writes the logs; errors go to Sentry or
  GlitchTip when a DSN is set.
- RabbitMQ 4 as the broker. Its user, queue and exchanges come from `definitions.json`.
- [ssg-library](https://github.com/nextcloud-publish/ssg-library) renders the site in ssg-worker.
- Docker Compose runs everything; the images are in GHCR.

## Layout

The publish repository:

```text
config/
  packages/messenger.yaml  builds transport + BuildJob routing
  packages/security.yaml   the firewalls, and the one access_control rule
  services.yaml            binds PUBLISH_API_TOKEN into ApiTokenHandler
public/index.php           front controller
src/
  Controller/              HealthController, BuildController
  Security/ApiTokenHandler.php  compares the token, yields the API identity
  Message/BuildJob.php     the message shape for q.builds
tests/Controller/          HealthControllerTest, BuildControllerTest, BuildEnqueueTest
tests/Security/            ApiTokenHandlerTest
tests/OpenApiTest.php      checks the app's answers against openapi.yaml
openapi.yaml               the API spec, OpenAPI 3.1
redocly.yaml               Redocly lint rules for the spec
phpstan.dist.neon          PHPStan level and paths
.php-cs-fixer.dist.php     PHP-CS-Fixer rules (PSR-12) and paths
.pre-commit-config.yaml    the hooks run before each commit
docs/wiki/                 the wiki pages, pushed to the GitHub wiki from main
docker/
  Dockerfile               the image CI pushes: PHP-FPM with the app, no dev dependencies
  compose.yaml             production sample: nginx + api + RabbitMQ + ssg-worker
  .env.example             the sample's settings
  nginx/default.conf       passes every request to PHP-FPM; prod and dev
  php/php-fpm.conf         replaces the image's FPM config; prod and dev
  php/php.ini              time zone and OPcache; prod and dev
  rabbitmq/                production broker config and definitions
  prod-frankenphp/         the same sample with FrankenPHP, to compare
  dev/Dockerfile           alpine + php85-fpm + dev dependencies, no source
  dev/compose.yaml         dev stack: nginx + api + RabbitMQ + ssg-worker + mock
  dev/.env.example         host ids and TZ for the dev stack; copy to dev/.env
  dev/collectives-mock/    content API stand-in, published-tree server, callback sink
  dev/published/           bind mount the worker publishes into (gitignored)
  dev/build_temp/          bind mount for the worker's build scratch (gitignored)
  dev/rabbitmq/            definitions.json (topology) + rabbitmq.conf
```

## Pages

- [How it works](https://github.com/nextcloud-publish/publish/wiki/How-it-works): the components, authentication, the build message, retries and callbacks.
- [Deploy publish](https://github.com/nextcloud-publish/publish/wiki/Deploy-publish): run it with Docker Compose, and update it.
- [Troubleshooting](https://github.com/nextcloud-publish/publish/wiki/Troubleshooting): the logs, tracing a build, Sentry.
- [Development](https://github.com/nextcloud-publish/publish/wiki/Development): the dev stack, the tests and PHPStan.
- [API reference](https://nextcloud-publish.github.io/publish/): the endpoints and the status
  callback.
