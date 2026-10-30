# Logging

Draft for discussion: which lines `publish` logs, at which level, and what a request looks like in
the log. Nothing here is implemented yet. ssg-worker's `docs/logging.md` covers the worker side.

## Output

Line format, to `var/log/publish.log` and to stderr, from `LOG_LEVEL` up: `info` in prod, `debug`
in dev.

```text
[datetime] channel.LEVEL: message {context} {extra}
```

| `extra` key | Value |
|---|---|
| `uid` | a random id per request; links Symfony's lines and ours for one request |
| `hostname` | the container id |

## Lines

| Level | Message | Context | When |
|---|---|---|---|
| `info` | `Build enqueued` | `build_id`, `static_site_id`, `slug` | 202 |
| `notice` | `Build request rejected` | `reason` | 400, invalid fields |
| `notice` | `Rejected API token` | `ip` | 401 |
| `error` | `Could not enqueue build` | `build_id`, `static_site_id`, `slug`, `exception` | 503 |

- `build_id` is the id ssg-worker puts on every line of the build, so `grep <build_id>` covers both
  services.
- `reason` is the error text of the 400. The request body is not logged.
- `ip` is the client address. Behind a reverse proxy it is only right when `trusted_proxies` is
  set.
- `content_download_url` and `callback_status_url` are not logged. Their ids are what keeps them
  private, but the log already carries the same ids for tracing.

Symfony logs the other answers itself: a body that is not JSON as `request.ERROR` (400), an
uncaught exception as `request.CRITICAL` (500).

## Channels

| Channel | Who | dev | prod |
|---|---|---|---|
| `app` | our code | `debug` | `LOG_LEVEL` |
| `php` | PHP warnings, notices and uncaught errors | `debug` | `LOG_LEVEL` |
| `request` | routing and uncaught exceptions | `debug` | `warning` |
| `security` | authentication | `debug` | `warning` |
| `messenger` | Messenger | `debug` | `warning` |
| `deprecation` | deprecated calls | `debug` | off |
| `event` | a line per event listener call | off | off |

`request` at `info` logs `Matched route` for every health check. `security` and `messenger` at
`info` repeat what `Rejected API token` and `Build enqueued` already say.

## Examples

Prod:

```text
[…14:03:12] app.INFO: Build enqueued {"build_id":"0199c3e2-…","static_site_id":"site-42","slug":"handbook"} {"uid":"a1b2c3d","hostname":"7c2e9f1a0b3d"}
[…14:05:40] app.NOTICE: Build request rejected {"reason":"slug must match [A-Za-z0-9_-]{1,128}"} {"uid":"e4f5a6b",…}
[…14:06:02] app.NOTICE: Rejected API token {"ip":"203.0.113.7"} {"uid":"c7d8e9f",…}
[…14:07:15] app.ERROR: Could not enqueue build {"build_id":"0199c3e5-…",…,"exception":"[object] (Symfony\\Component\\Messenger\\Exception\\TransportException(code: 0): Could not connect to the AMQP server …)"} {"uid":"0a1b2c3",…}
```

## Sentry

Errors go to [GlitchTip](https://glitchtip.com/), which speaks Sentry's protocol, through
`sentry/sentry-symfony`. Sentry is one more Monolog handler: what reaches it is decided in
`monolog.yaml`, like the file and stderr. The bundle is registered in prod only, and without
`SENTRY_DSN` it sends nothing.

| Variable     | Required | Default | Description                                     |
| ------------ | -------- | ------- | ----------------------------------------------- |
| `SENTRY_DSN` | no       | none    | GlitchTip project DSN; unset sends no events    |

| Log line | In Sentry |
|---|---|
| `error` and up with an `exception`, except `request`, `security`, `messenger` | an event |
| `request.CRITICAL`, an uncaught exception (500) | an event |
| `request.ERROR`, a 4xx such as a 404 or a body that is not JSON | nothing |
| `error` without an `exception` | nothing |
| everything below `error`, also `debug` whatever `LOG_LEVEL` is | a breadcrumb on the next event of the request |

In practice that is `Could not enqueue build` and the 500s.
The bundle's own error listener and error handler are off, so an exception arrives once.
Tracing is off.

An event carries:

- the exception with its stack trace,
- the log line's context and `extra` as `monolog.context` and `monolog.extra`: `build_id`,
  `static_site_id`, `slug`, `hostname`, `uid`,
- the earlier lines of the request as breadcrumbs, with their context,
- the request: URL, headers and JSON body, so also `content_download_url` and
  `callback_status_url`.

The ids go to GlitchTip like they go to the log file; whoever reads GlitchTip can read the URLs.
The SDK drops the `Authorization` header, so the bearer token is not sent, and it drops cookies
and the client address. A `Rejected API token` breadcrumb still carries its `ip`.

`release` is the Composer version of the app (`dev-main@<commit>` in a checkout) unless
`SENTRY_RELEASE` is set; `environment` is `prod`.
