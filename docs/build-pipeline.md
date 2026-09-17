# The build pipeline

How a build request becomes a published site, what changed in this repo to make that
happen, and the handful of things that will bite you if you touch it.

`ssg-worker` has a companion document, `docs/build-pipeline.md`, with the same system
overview and its own repo-specific half.

## What it looked like before

The pipeline had no return path. `publish` accepted `POST /build` and enqueued a
`BuildJob`; `ssg-worker` rendered the site into `$JOB_STORAGE_DIR/<static_site_id>/output`
and stopped there.

- `BuildJobHandler` reported only through `error_log()`. On failure it threw, and with
  `retry_strategy: max_retries: 0` and no dead-letter queue the message was acknowledged
  and the job vanished.
- `callback_status_url` was required by the API, validated by nobody, carried end to end
  on the message, and read by nothing. The worker's own test passed `''` for it.
- There was no success queue, no failure queue, no result consumer, and no destination for
  a finished build.
- Nothing was runnable end to end: no committed compose file mounted a shared volume,
  `JOB_STORAGE_DIR` and `MAX_DOWNLOAD_MB` were set nowhere, and both repos referenced an
  `integration-test/docker-compose.yml` that had never existed.

## What it looks like now

```
POST /build ──► q.builds ──► ssg-worker renders the site
                              ├─ ok   ──► BuildSucceeded ──► q.build-results ─┐
                              └─ fail ──► BuildFailed    ──► q.build-failures ┤
                                                                              ▼
                                        publish result-worker (messenger:consume)
                                          success: promote the site,   then POST success
                                          failure: quarantine the job, then POST failed
                                                                              │
                                              retries exhausted ──► x.build-dead (fanout)
                                                                          ──► q.build-dead
```

Four processes: the API, the broker, the build worker, the result worker. The API and the
result worker are the **same image** with a different command — that is the whole of the
placement decision below.

### Queues and exchanges

| Object | Kind | Written by | Drained by |
| --- | --- | --- | --- |
| `q.builds` | quorum queue | the API | `ssg-worker` |
| `q.build-results` | quorum queue | `ssg-worker`, on success | the result worker |
| `q.build-failures` | quorum queue | `ssg-worker`, on failure | the result worker |
| `q.build-dead` | quorum queue | Messenger, once retries are spent | nobody — a parking lot |
| `delays` | direct exchange | Messenger's retry machinery | — |
| `x.build-dead` | fanout exchange | the failure transport | bound to `q.build-dead` |

All of it is declared in `docker/rabbitmq-config/definitions.json`, which the broker
imports at boot. Adding these was **additive**, so it needed a `docker compose restart
rabbitmq` rather than `down -v`; changing an existing queue's arguments still requires
recreating the broker.

### Directories

```
/opt/ssg/build_temp/<static_site_id>/<build_id>/{input,output}   JOB_STORAGE_DIR
/opt/ssg/published/<static_site_id>/                             PUBLISHED_DIR
/opt/ssg/published/.staging/<build_id>[.partial]                 staging for the swap
/opt/ssg/build_failed/<build_id>/                                FAILED_DIR
```

The job tree is keyed on **both** `static_site_id` and `build_id`. That was a re-key, and
it was not cosmetic — see the `ssg-worker` document for the two independent reasons.

In the dev stack `build_temp` is a named volume while `published` and `build_failed` are
bind mounts under `docker/dev/`, so a finished site or a failed build can be read straight
off the host. They are therefore **different mount points**, which changes how a build is
moved — see *Mount points, not filesystems* below.

## Decisions taken

Each of these was argued out at length in a comparison document while it was still open.
Those documents are gone now that the answers are in the code; what survives here is the
decision and the reason it went that way.

**The result worker lives in `publish`, as a second container off the same image.**
`publish` owns the client relationship: `callback_status_url` arrives through its API and
the callback payload is a published contract. The prototype notes say the REST API
"cannot" consume result queues — that rules out the **HTTP request/response process**, not
the repo and not the image. A `messenger:consume` container is a separate process.

The alternative was `ssg-worker`, which already had the HTTP client, the filesystem layout
and a consume-shaped image. The cost of not choosing it is two more hand-synced message
classes across repos; the mitigation is `MessageContractTest`, described below.

A single container running both processes under supervisor was considered and rejected.
Symfony's docs do recommend supervisor for `messenger:consume`, but that assumes a
non-container deploy — under compose the orchestrator already is that supervisor. Separate
containers also get correct `SIGTERM` handling, independent scaling, and per-role logs and
health.

**Outcomes are explicit messages, not broker dead-lettering.** `BuildSucceeded` and
`BuildFailed` carry the real cause in a readable JSON body. RabbitMQ's
`x-first-death-reason` only ever says `rejected`, `delivery_limit` or `expired` — never
`tar: unexpected EOF`. Explicit messages also port unchanged to a Doctrine/Postgres
transport, which broker-level dead-lettering does not: the Doctrine transport has no
dead-letter concept at all, so that half of the design would silently disappear on a swap.

Messenger's `failure_transport` **is** the dead-letter pattern, lifted from the broker into
the framework, and it is used — but only as the safety net on the result queues, where a
callback can genuinely exhaust its retries.

**One handler does the filesystem work and the callback.** The prototype ruled that the
webhook must not gate the acknowledgement. In Messenger, gating an acknowledgement means
throwing — there is no manual ack — so the only way to honour that rule is to change the
unit of work. Rather than add a third queue, the filesystem work is idempotent enough that
a callback retry replaying it costs three `stat()` calls.

## What changed in this repo

### New — the result worker

| File | What it does |
| --- | --- |
| `src/Message/BuildSucceeded.php`, `BuildFailed.php` | the outcome messages, hand-synced with `ssg-worker` |
| `src/Message/BuildSucceededHandler.php`, `BuildFailedHandler.php` | promote-or-quarantine, then call back |
| `src/Storage/JobLayout.php` | every path in one place, plus the id allow-list |
| `src/Storage/BuildPromoter.php` | atomic, idempotent publish |
| `src/Storage/BuildQuarantine.php` | move a failed build aside |
| `src/Storage/Filesystem.php` | primitives that fail with the operating system's own reason |
| `src/Callback/StatusNotifier.php` | the outbound POST, and the retryable/permanent split |

`App\Storage\` is deliberate: `ssg-worker`'s `JobWorkspace` docblock had claimed for a long
time to mirror a `publish\App\Storage\JobWorkspace` that never existed. That stale comment
is the best evidence in the repo that hand-synced contracts drift, and it is now true.

### How a site is published

1. move `output/` to `PUBLISHED_DIR/.staging/<build_id>` — a rename when the two share a
   mount, a copy via a `<build_id>.partial` scratch name when they do not
2. `chmod` it to `0755` — `ssg-worker` creates `output/` at `0750`, which nothing serving
   the site could traverse
3. `rename()` any live site aside, then `rename()` the new one into place
4. delete the retired site and the build's whole temp tree, `input/` included
5. `POST` the callback

Step 3 is two renames rather than one because `rename()` onto an existing **non-empty**
directory fails with `ENOTEMPTY` and never merges — a single rename works for a site's
first build and breaks on every rebuild after it. Two renames also keep the window where
the site is absent two syscalls wide instead of however long a recursive delete takes, and
a crash inside it leaves both trees on disk.

The guard before any of that is a four-way check, and the order matters:

| staging | build output | published | meaning | action |
| --- | --- | --- | --- | --- |
| complete | any | any | the copy already finished | swap it in |
| — | exists | any | a fresh build | full promotion |
| — | — | exists | already promoted (the normal replay) | skip, just call back |
| — | — | — | nothing to promote | park the message |

An empty `output/` is refused outright: the swap removes the live site, so publishing
nothing would take a working site down and replace it with a 404.

### Mount points, not filesystems

`rename(2)` rejects `old_path.mnt != new_path.mnt` with `EXDEV` **before** it looks at the
superblock, and PHP has no directory fallback — verified, it fails outright rather than
degrading to a copy. Two bind mounts of one host directory are still two mount points.

Because the dev stack deliberately splits the three roots, every move between them goes
through `Filesystem::moveDir()`, which tries `rename()` and copies when that fails. Two
consequences:

- **The copy is not atomic**, so it lands on a `.partial` scratch name and is renamed into
  place only once complete. A crash mid-copy leaves the scratch name, which nothing
  publishes. What stays atomic is the swap — staging and retiring both live inside
  `PUBLISHED_DIR`, so a reader sees the old site or the new one, never half of either.
- **The copy is not instant**, and the message is unacknowledged throughout. A large
  enough site could in principle run past the broker's `consumer_timeout` (30s dev, 300s
  prod) and be redelivered.

Point all three at one mount and every copy becomes a rename again, with no code change.

### The callback contract

`POST` with `{build_id, static_site_id, status, finished_at}`, plus `error` on failure.
Delivery is **at-least-once** and cannot be made otherwise, so the receiver must dedupe on
`(build_id, status)`. Retryable: `5xx`, `408`, `429`, timeouts. Permanent, parked at once:
other `4xx`, and `3xx` because redirects are not followed.

`callback_status_url` is attacker-controlled and validated nowhere upstream, and this is an
outbound POST *with a body* — a better SSRF primitive than the inbound download. Only
`http`/`https` are called and the client is wrapped in `NoPrivateNetworkHttpClient`.

Still open: nothing signs the callback, so a receiver cannot verify it came from us.

### Infrastructure

- `docker/Dockerfile` now installs `vendor/` at build time. Without it a
  `messenger:consume` container starts, fails to autoload and exits — `php -S` only
  survived because the bind mount supplied `vendor/`. Both services therefore carry an
  anonymous `/app/vendor` volume, so a host-side `composer require` needs a rebuild.
- It also installs `ext-pcntl`. Symfony only registers its `SIGTERM` handler when
  `function_exists('pcntl_signal')`, and the official images do not enable it. Without it
  `docker stop` hard-kills the worker mid-message and the callback is replayed on every
  redeploy. Both extensions are built in **one** `RUN`, because the build toolchain is
  removed at the end of it.
- `docker/dev/` replaces the never-written `integration-test/`, and supersedes
  `docker/compose.dev.yaml`. It runs the whole system plus `collectives-mock`, an nginx
  standing in for the Nextcloud Collectives content API. It serves a `.tar.gz` built at
  image build time from committed markdown — editable as text rather than an opaque blob —
  and a deliberately invalid archive, so one URL swap exercises the whole failure path. Its
  second server block serves `PUBLISHED_DIR` read-only, which is the only way to check the
  directory permissions actually work.
- `symfony/http-client` is a new dependency.

## Things that will bite you

All verified against the vendored Symfony 8.1, not recalled.

**The failure transport must publish through a named fanout.** On a failure send,
`AmqpSender` rebuilds an `AmqpStamp` from the received AMQP envelope; because
`SentToFailureTransportStamp` is set it passes a null retry routing key, and
`AmqpStamp::createFromAmqpEnvelope()` falls back to the **original** routing key.
Published to the default exchange, a dead-lettered message goes straight back onto the
queue it just failed out of, forever. `x.build-dead` is a fanout to break that loop.

**`messenger:failed:show` does not work on AMQP.** `AmqpReceiver` implements
`QueueReceiverInterface` and `MessageCountAwareInterface` but not
`ListableReceiverInterface`, and the command throws without it — as does
`messenger:failed:remove`. Inspect `q.build-dead` in the management UI. Replay with an
interactive `messenger:failed:retry`, or `messenger:consume build_dead` to drain it back
through the handlers. That second route is why both handlers carry a `from_transport:
build_dead` tag as well as their own: `HandlersLocator` matches on the received transport
name, so a replay would otherwise find no handler at all.

**Every `queues:` entry needs `arguments: { x-queue-type: quorum }`.**
`countMessagesInQueues()` calls `declareQueue()`, so `messenger:stats` and
`messenger:failed:*` redeclare the queue as classic and get `PRECONDITION_FAILED` (406).
Inert on the normal consume path, which never declares.

**A consuming transport with `max_retries > 0` needs `exchange: { name: '' }`.** Retries
republish through the transport's own sender; with no exchange block the name is derived
from the DSN path and falls back to a literal `messages` exchange that does not exist. The
404 is raised inside `Worker::ack()` where nothing catches it, so the worker dies and the
message sits unacknowledged until `consumer_timeout`.

**`retry_strategy.delay > 0` requires the `delays` exchange.** With `auto_setup: false`,
`setupDelay()` skips declaring it but still runs `declareQueue()` and `bind()`. Messenger
also declares its own `delay_*_retry` queues at runtime — the one place the application
owns topology, and worth knowing before someone treats it as a bug.

**`jitter` must stay 0.** The jittered value is embedded in the delay *queue name*, so any
jitter creates a fresh classic queue per message per attempt. They self-expire, so it is
churn rather than a leak, but it makes the broker unreadable.

**`messenger:consume a b` is strict priority, not round-robin.** The worker breaks out of
the receiver loop on the first envelope and restarts from the first receiver, so a busy
first queue starves the second. Failures are listed first for that reason.

**Never add `--keepalive`.** No transport ships an implementation of
`KeepaliveReceiverInterface`, and `Worker::keepalive()` throws for receivers that lack one.

**A handler cannot see the envelope.** `HandleMessageMiddleware` passes `[$message]` and
nothing else, so anything stamp-borne needs middleware or a worker-event subscriber. This
is why `failure_transport` alone could not carry a build's failure reason.

## What the test suite cannot catch

`InMemoryTransportFactory::createTransport()` **discards its options entirely**, so every
AMQP detail above is invisible offline: wrong queue names, a queue or exchange missing from
`definitions.json`, the `exchange:` block whose absence kills a retrying worker, the delay
exchange, the quorum redeclare, the routing-key loop, strict-priority starvation, `EXDEV`
across mounts, and the directory permissions nginx needs. Passing tests say nothing about
whether `messenger:consume` can connect. `docker/dev/` is the only thing that exercises any
of it.

What the suite *does* pin is the cross-repo contract. `tests/Message/MessageContractTest.php`
asserts the exact `type` header and JSON body of all three messages against hard-coded
fixtures, and **the same file with the same fixtures exists in `ssg-worker`**. Those
classes are duplicated by hand; renaming a property on one side is otherwise a
`MessageDecodingFailedException` in production that nothing catches first. If that test
needs editing, the other repo needs the same edit in the same commit.
