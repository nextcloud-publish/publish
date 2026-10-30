# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.2.0]: https://github.com/nextcloud-publish/publish/releases/tag/0.2.0
[0.1.0]: https://github.com/nextcloud-publish/publish/releases/tag/0.1.0
