# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-11-11

### Added

- `GET /auth-verification` for clients testing their URL and API token: the same `{"status":"ok"}`
  as `/health`, but only with a valid bearer token, `401` otherwise.
- `openapi.yaml` documents `HEAD /health` for uptime monitors, and that `/health` is for
  monitoring and `/auth-verification` for testing the token.
- `render_options` on `POST /build`: any JSON value, passed unchecked to ssg-worker and
  ssg-library, which validates it. An invalid value ends the build with a `failed` callback.

### Changed

- `openapi.yaml` describes the status callback as a `PATCH` with the header `OCS-APIRequest: true`,
  as ssg-worker sends it.
- Code is formatted to PSR-12, and CI and pre-commit check it with PHP-CS-Fixer.

## [0.3.0] - 2026-11-04

### Added

- Logging with Monolog to `var/log/publish.log` and stderr, in line format, from `LOG_LEVEL` up
  (`info`, `debug` in dev). Every line carries the container's `hostname` and a `uid` per request.
  The `request`, `security` and `messenger` channels write from `warning` up in prod.
- `POST /build` logs `Build enqueued` with the `build_id`, `Build request rejected` with the
  reason, and `Could not enqueue build` with the exception behind a `503`. A wrong bearer token
  logs `Rejected API token` with the client address.
- Sentry through Monolog, in prod with `SENTRY_DSN`: `error` lines with an exception and
  uncaught exceptions (500) become events, the request's earlier lines their breadcrumbs. A 4xx is
  not sent.
- `openapi.yaml`, an OpenAPI 3.1 spec of `GET /health`, `POST /build` and the status callback,
  published with Redoc to GitHub Pages from `main`. A test checks the app's answers against it,
  and CI lints it.
- `docker/compose.yaml`, a production sample of nginx, the API, RabbitMQ and ssg-worker. It keeps
  the published sites in `pages/`, the logs in `logs/` and the build scratch in the `build-temp`
  volume, and uses the `:latest` images. RabbitMQ reads its user from
  `docker/rabbitmq/definitions.json`, which the admin creates from `definitions.example.json` with
  a hash of `RABBITMQ_PASSWORD`.
- The wiki page Deploy publish, which sets up the production sample.
- The wiki pages live in `docs/wiki/`; CI pushes them to the GitHub wiki on every push to `main`.

### Changed

- The dev stack serves the API with PHP-FPM behind nginx instead of PHP's built-in server, from
  `docker/dev/Dockerfile`.
- The image in GHCR runs PHP-FPM on port 9000, without dev dependencies, with `APP_ENV=prod` built
  in, the cache warmed and OPcache not checking files for changes.
- Responses carry no `X-Powered-By` header with the PHP version.
- The RabbitMQ user in dev is `publish` instead of `app`, password `secret`. The dev broker config
  is in `docker/dev/rabbitmq/`.
- The development guide and the tests moved from the README to the wiki page Development.
- The README keeps the API link. The layout moved to the wiki page Home, authentication and
  queueing to How it works, the log messages to Troubleshooting, the image tags to Deploy publish
  and the release rule to Development.

### Security

- Symfony 8.1.11, which fixes 15 advisories in the packages the API uses.

## [0.2.0] - 2026-10-30

### Changed

- Images in GHCR are tagged `main` for the newest build of `main`, the branch name for a pull
  request, and `1.2.3`, `1.2`, `1` and `latest` for a release tag `1.2.3` (no `0` during 0.x).
  Every image also gets `sha-<commit>`. CI runs on pushes to `main` and on release tags.

## [0.1.0] - 2026-10-29

### Added

- `POST /build` validates a build request and enqueues it as a `BuildJob` on RabbitMQ for
  `ssg-worker`. It answers `202` once the broker confirms, `400` for an invalid payload and
  `503` when the broker is unreachable.
- `GET /health`.
- Bearer token authentication for `POST /build` with Symfony SecurityBundle, set by
  `PUBLISH_API_TOKEN`.
- `docker/Dockerfile` with the stages `base` (no source) and `release`. PHP reads its time zone
  from `TZ`, default UTC.
- Dev stack in `docker/dev/compose.yaml`: the API, RabbitMQ, two `ssg-worker` instances and a
  Collectives mock.
- CI on pull requests: lint, dependency audit, PHPStan at level 10, tests, and an image pushed to
  GHCR.
- Pre-commit hooks that run the CI lint and test steps.

[Unreleased]: https://github.com/nextcloud-publish/publish/compare/0.4.0...HEAD
[0.4.0]: https://github.com/nextcloud-publish/publish/releases/tag/0.4.0
[0.3.0]: https://github.com/nextcloud-publish/publish/releases/tag/0.3.0
[0.2.0]: https://github.com/nextcloud-publish/publish/releases/tag/0.2.0
[0.1.0]: https://github.com/nextcloud-publish/publish/releases/tag/0.1.0
