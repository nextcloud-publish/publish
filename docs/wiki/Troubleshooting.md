## Logs

Both services write the same lines to a file and to stderr:

| Service | File | stderr |
| --- | --- | --- |
| publish | `logs/publish/publish.log` | `docker compose logs publish` |
| ssg-worker | `logs/ssg-worker/ssg-worker.log`, shared by the replicas | `docker compose logs ssg-worker` |

The files are not rotated; rotate them on the host. `web` and `rabbitmq` log to stderr only.

```text
[2026-10-31T14:03:12.481920+00:00] app.INFO: Build enqueued {"build_id":"0199c3e2-…","static_site_id":"site-42","slug":"handbook"} {"hostname":"3f9a1c2b7d4e","uid":"a1b2c3d"}
```

`LOG_LEVEL` in `.env` sets the lowest level both services write: `debug`, `info` (default),
`notice`, `warning` or `error`. With `debug`, ssg-worker logs every step of a build. Symfony's own
`request`, `security` and `messenger` lines are written from `warning` up, so health checks leave
no line. Timestamps follow `TZ`.

## Trace a build

publish gives every build a `build_id` and logs it with `Build enqueued`. ssg-worker puts the same
`build_id` on every line it logs while it handles that build. Nextcloud knows the
`static_site_id`, so find the build's id first:

```bash
grep '"static_site_id":"<static_site_id>"' logs/publish/publish.log
```

Then all lines of that build, from both services:

```bash
grep -h '<build_id>' logs/publish/publish.log logs/ssg-worker/ssg-worker.log
```

A good build ends with `Published site` and `Reported build status`. A failed one retries once
(`attempt` `2`), then ends with `Build failed`. `Build started` without either end means the worker
was stopped during the build.

## Log messages

publish, besides Symfony's own lines. Every line carries `hostname`, the container id, and `uid`,
a random id per request:

| Level     | Message                   | Context                                           | Answer |
| --------- | ------------------------- | ------------------------------------------------- | ------ |
| `info`    | `Build enqueued`          | `build_id`, `static_site_id`, `slug`              | `202`  |
| `warning` | `Build request rejected`  | `reason`                                          | `400`  |
| `warning` | `Rejected API token`      | `ip`                                              | `401`  |
| `error`   | `Could not enqueue build` | `build_id`, `static_site_id`, `slug`, `exception` | `503`  |

A request without a token, and the request body, are not logged.

ssg-worker, besides Messenger's and HttpClient's lines. Every line of a build carries `build_id`,
`static_site_id`, `slug`, `attempt` (`1`, or `2` for the retry) and `hostname`:

| Level     | Message                                     | Context                          |
| --------- | ------------------------------------------- | -------------------------------- |
| `info`    | `Build started`                             | `url`, the content download      |
| `debug`   | `Prepared job directory`                    | `dir`                            |
| `debug`   | `Downloaded content archive`                | `file`, `bytes`                  |
| `debug`   | `Extracted content archive`                 | `dir`                            |
| `debug`   | `Rendered site`                             | `pages`, `dir`                   |
| `debug`   | `Moved site into place`                     | `from`, `to`                     |
| `debug`   | `Cleared job directory`                     | `dir`                            |
| `info`    | `Published site`                            | `pages`, `duration_ms`, `url`    |
| `info`    | `Reported build status`                     | `status`, `url`, the callback    |
| `warning` | `Skipped symlink while copying`             | `path`, `from`                   |
| `warning` | `Skipped symlink while setting permissions` | `path`                           |
| `warning` | `Could not remove source after copy`        | `from`, `to`                     |
| `warning` | `Could not remove job directory`            | `dir`                            |
| `error`   | `Build failed`                              | `exception`, with paths          |
| `error`   | `Could not report failed build`             | `exception`                      |

## Sentry

With `PUBLISH_SENTRY_DSN` and `SSG_WORKER_SENTRY_DSN` in `.env`, each service sends its errors to
a Sentry or GlitchTip project. Without them, nothing is sent.

- publish sends `error` lines with an exception and every `500`, with the request's earlier lines
  as breadcrumbs. A `4xx` is not sent.
- ssg-worker sends `Build failed` once, after the last attempt, with that build's earlier lines
  as breadcrumbs, `debug` included, and its `build_id`. A worker that dies is sent too.

## Common failures

| Symptom | Log line | Fix |
| --- | --- | --- |
| Nextcloud gets `401` | publish: `Rejected API token` | Nextcloud's token must equal `PUBLISH_API_TOKEN` |
| Nextcloud gets `503` | publish: `Could not enqueue build`; the exception names the cause | Check `docker compose ps`; for a refused login, hash `RABBITMQ_PASSWORD` into `rabbitmq/definitions.json` again |
| A site is not published | ssg-worker: `Build failed`; the exception names the cause | e.g. Nextcloud refused the download, or it exceeded `MAX_DOWNLOAD_MB` |
| Nextcloud never hears back | ssg-worker: `Could not report failed build` | ssg-worker must reach the `callback_status_url` Nextcloud sends |
| Visitors get `403` on `/pages/` | the host proxy's error log | The proxy's user must be able to read `pages/` and every folder above it |
