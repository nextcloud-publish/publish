# Handover: implement Collectives status callback in ssg-worker

## Context

`publish` (the Symfony API) already accepts `callback_status_url` on `POST
/build` and carries it through to `ssg-worker` on the `App\Message\BuildJob`
message (`src/Message/BuildJob.php:27` in ssg-worker declares the property).
Nobody reads it. The worker logs `[INFO] rendered N page(s) into ...` and
Messenger acks the message — Collectives never hears back, success or
failure.

This handover implements that callback. All design decisions below were
already made and cross-checked against the existing code/tests; treat this as
an implementation plan, not an open design question.

## Target payload shapes

On success, POST JSON to `callback_status_url`:

```json
{ "status": "published", "data": { "publish_url": "https://publish.schnubbel.com/publish/<static_site_id>" } }
```

On failure:

```json
{ "status": "failed", "data": { "error_message": "<some error message>" } }
```

## Design decisions

- **New class `App\Job\StatusNotifier`** (`src/Job/StatusNotifier.php`),
  alongside the other single-purpose `Job/` classes (`ContentDownloader`,
  `ArchiveExtractor`, `SiteRenderer`, `JobWorkspace`).
- **`callback_status_url` is untrusted**, exactly like `content_download_url`
  — it arrives from the same HTTP payload via the queue. Reuse
  `ContentDownloader::assertFetchableUrl`'s pattern: only `http`/`https`,
  must have a host. Otherwise this is an SSRF vector (`file://`,
  `gopher://`, internal hosts, etc.) via Collectives-controlled input.
- **The notifier never throws.** By the time it runs, the build has already
  succeeded or failed; there is nowhere left to retry from
  (`retry_strategy.max_retries: 0`, no DLQ). A broken callback (unreachable
  host, non-2xx response, invalid scheme) must not mask or override the
  build's real outcome — it just logs via `error_log()`, matching the rest of
  the pipeline's error reporting.
- **`BuildJobHandler` wraps its whole pipeline in try/catch**, calls
  `notifySuccess()` after the last step, calls `notifyFailure()` with
  `$e->getMessage()` in the catch block, then **rethrows** — existing
  exception types/messages and Messenger's no-retry/log-and-ack behavior are
  unchanged, callers of the handler see identical exceptions.
- **`publish_url` is built from a new required env var `PUBLIC_SITE_BASE_URL`**
  (plain string, no fallback — same "fail loudly" convention as
  `JOB_STORAGE_DIR`/`AMQP_DSN`), concatenated as:
  `rtrim($publicSiteBaseUrl, '/') . '/publish/' . $message->static_site_id`.
  This matches the example in the payload shape above. No existing route
  serves published sites yet in either repo — this is just the string
  Collectives is told to use; whatever serves the output volume over HTTP is
  out of scope here.
  - Because it's a **plain `%env(PUBLIC_SITE_BASE_URL)%`** (no `int:`/`bool:`
    processor), it follows the same precedent as `AMQP_DSN` and
    `JOB_STORAGE_DIR`: those are also required-with-no-fallback but are
    *not* in `.github/workflows/ci.yml`'s top-level `env:` block, and
    `lint:container` still passes — Symfony only needs to actually *resolve*
    an env var at container-compile time when a cast processor is applied to
    it (that's why `MAX_DOWNLOAD_MB` and `AMQP_HEARTBEAT`, both `int:`, are
    the ones listed there). Don't add `PUBLIC_SITE_BASE_URL` to that `env:`
    block unless `php bin/console lint:container` run locally proves
    otherwise.

## File-by-file changes

### 1. New file: `src/Job/StatusNotifier.php`

```php
<?php

declare(strict_types=1);

namespace App\Job;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reports a build job's outcome back to Collectives, via the
 * callback_status_url carried on the job itself.
 *
 * That URL travels from the original build request through the queue, same
 * as content_download_url, so it is treated as untrusted the same way: only
 * http/https are called. Unlike ContentDownloader, a failed notification
 * never throws -- by the time this runs the build has already succeeded or
 * failed, there is nowhere left to retry from, and a broken callback must not
 * be able to mask or override that outcome.
 */
final class StatusNotifier
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxRedirects = 3,
    ) {
    }

    public function notifySuccess(string $callbackUrl, string $publishUrl): void
    {
        $this->send($callbackUrl, [
            'status' => 'published',
            'data' => ['publish_url' => $publishUrl],
        ]);
    }

    public function notifyFailure(string $callbackUrl, string $errorMessage): void
    {
        $this->send($callbackUrl, [
            'status' => 'failed',
            'data' => ['error_message' => $errorMessage],
        ]);
    }

    private function send(string $callbackUrl, array $payload): void
    {
        if (!$this->isFetchableUrl($callbackUrl)) {
            error_log(sprintf('[ERROR] refusing to notify %s: only http and https are allowed', $callbackUrl));

            return;
        }

        try {
            $response = $this->httpClient->request('POST', $callbackUrl, [
                'json' => $payload,
                'timeout' => $this->timeoutSeconds,
                'max_redirects' => $this->maxRedirects,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                error_log(sprintf('[ERROR] status callback to %s returned HTTP %d', $callbackUrl, $status));
            }
        } catch (HttpClientException $e) {
            error_log(sprintf('[ERROR] status callback to %s failed: %s', $callbackUrl, $e->getMessage()));
        }
    }

    // SECURITY
    private function isFetchableUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return \in_array($scheme, self::ALLOWED_SCHEMES, true) && parse_url($url, PHP_URL_HOST) !== null;
    }
}
```

### 2. `src/Message/BuildJobHandler.php` — full replacement

```php
<?php

declare(strict_types=1);

namespace App\Message;

use App\Job\ArchiveExtractor;
use App\Job\ContentDownloader;
use App\Job\JobWorkspace;
use App\Job\SiteRenderer;
use App\Job\StatusNotifier;

/**
 * Handles build jobs messages reveived via q.builds into html:
 *  - creates the job's folders
 *  - downloads and extracts the content archive
 *  - renders the pages into html
 *  - reports the outcome back to Collectives via callback_status_url
 *
 * Registered as a message handler in services.yaml instead of via
 * #[AsMessageHandler] attribute.
 */
final class BuildJobHandler
{
    /** Folder where the archive is unpacked, relative to the job's input/ folder. */
    public const UNARCHIVED_DIR = 'content_unarchived';

    public function __construct(
        private readonly ContentDownloader $contentDownloader,
        private readonly ArchiveExtractor $archiveExtractor,
        private readonly SiteRenderer $siteRenderer,
        private readonly JobWorkspace $jobWorkspace,
        private readonly StatusNotifier $statusNotifier,
        private readonly string $publicSiteBaseUrl,
    ) {
    }

    /**
     * @throws \InvalidArgumentException if the static_site_id is unsafe
     * @throws \RuntimeException         if any step fails
     */
    public function __invoke(BuildJob $message): void
    {
        try {
            // Creates the job's input/output folders.
            $jobDir = $this->jobWorkspace->createJobDirectories($message->static_site_id);

            error_log(sprintf('[INFO] prepared job workdir %s', $jobDir));

            $url = $message->content_download_url;
            $inputDir = $jobDir . '/' . JobWorkspace::INPUT_DIR;
            $file = $this->contentDownloader->download($url, $inputDir);

            error_log(sprintf(
                '[INFO] downloaded %s to %s (%d bytes)',
                $url,
                $file,
                (int) @filesize($file),
            ));

            // Extracted content assets
            $unarchivedDir = $inputDir . '/' . self::UNARCHIVED_DIR;
            $this->archiveExtractor->extract($file, $unarchivedDir);

            error_log(sprintf('[INFO] extracted %s into %s', $file, $unarchivedDir));

            // slug is used as the site header on every page.
            $outputDir = $jobDir . '/' . JobWorkspace::OUTPUT_DIR;
            $pages = $this->siteRenderer->render($unarchivedDir, $outputDir, $message->slug);

            error_log(sprintf('[INFO] rendered %d page(s) into %s', $pages, $outputDir));

            $publishUrl = rtrim($this->publicSiteBaseUrl, '/') . '/publish/' . $message->static_site_id;
            $this->statusNotifier->notifySuccess($message->callback_status_url, $publishUrl);
        } catch (\Throwable $e) {
            $this->statusNotifier->notifyFailure($message->callback_status_url, $e->getMessage());

            throw $e;
        }
    }
}
```

### 3. `config/services.yaml` — add arguments to the existing `BuildJobHandler` entry

```yaml
    App\Message\BuildJobHandler:
        tags:
            - { name: messenger.message_handler, handles: App\Message\BuildJob, from_transport: builds }
        arguments:
            # Base URL the rendered site is served from; combined with
            # static_site_id to build the publish_url sent in the success
            # callback. No default: guessing this would silently send
            # Collectives a broken link.
            $publicSiteBaseUrl: '%env(PUBLIC_SITE_BASE_URL)%'
```

`StatusNotifier` itself needs no explicit wiring — `HttpClientInterface`
autowires to the default client same as `ContentDownloader`, and
`timeoutSeconds`/`maxRedirects` keep their defaults.

### 4. Tests

**New file `tests/Job/StatusNotifierTest.php`** — use `MockHttpClient` with a
closure callback (not a single `MockResponse`) so the request body can be
captured and asserted on. Needed cases:

- `notifySuccess()` POSTs to the exact URL with
  `{"status":"published","data":{"publish_url":...}}` as the JSON body.
- `notifyFailure()` POSTs `{"status":"failed","data":{"error_message":...}}`.
- A non-`http(s)` scheme (e.g. `file:///etc/passwd`) makes **zero** requests
  and logs `refusing to notify`.
- A transport-level failure (`new MockResponse('', ['error' => 'Connection
  refused'])`) does not throw, and logs `status callback ... failed`.
- A non-2xx response (`new MockResponse('', ['http_code' => 500])`) does not
  throw, and logs `returned HTTP 500`.

Redirect the error log via `ini_set('error_log', $tmpFile)` in
`setUp()`/restore in `tearDown()`, same pattern as
`BuildJobHandlerTest::setUp()`.

**Update `tests/Message/BuildJobHandlerTest.php`:**

- Add `use App\Job\StatusNotifier;`.
- Add a second `MockHttpClient` (`$this->statusClient`), separate from the
  download client (`$this->client`) — they must stay separate or the
  existing `assertSame(0, $this->client->getRequestsCount())` assertions in
  the empty/unsafe-id tests would break once the notifier also makes
  requests. Capture requests into an array via the closure form, same
  reasoning as above.
- Add `self::PUBLIC_SITE_BASE_URL = 'https://publish.example.com'` and change
  `job()`'s `callback_status_url` from `''` to a fixture constant (e.g.
  `self::CALLBACK_URL = 'https://cloud.example.org/collectives/status/1234'`)
  — the blank placeholder was fine when nothing read the field, but now it's
  needed to exercise real callback assertions.
- Update the two inline `new BuildJobHandler(...)` construction sites
  (`handler()` and `testFailsWhenTheDownloadIsNotAValidArchive`) to pass
  `new StatusNotifier($this->statusClient)` and
  `self::PUBLIC_SITE_BASE_URL`.
- Add `testNotifiesCollectivesOfASuccessfulBuild()`: run the handler, assert
  exactly one request was captured, with the right URL and a JSON body
  decoding to `{"status":"published","data":{"publish_url":"https://publish.example.com/publish/<SITE_ID>"}}`.
- Add `testNotifiesCollectivesOfAFailedBuild()`: reuse the
  not-a-valid-archive setup, catch the rethrown `RuntimeException`, assert
  one captured request with `status: "failed"` and `data.error_message`
  containing `"Extracting"`.

### 5. Docs — `README.md`

- Fields table (currently lists `static_site_id`, `content_download_url`,
  `slug`): add a row for `callback_status_url` — "notified with the build
  outcome after the pipeline finishes, success or failure (see Status
  callback below)".
- Environment variables table: add
  `PUBLIC_SITE_BASE_URL | yes | none | Base URL the rendered site is served from; combined with static_site_id to build the publish_url sent in the success callback`.
- New `## Status callback` section describing: POST to
  `callback_status_url`, the two JSON shapes (success/failure), that only
  `http`/`https` are called (SSRF guard, same as `content_download_url`),
  and that a broken callback is logged via `error_log()` and never retried —
  it cannot turn a successful build into a failed job or vice versa.
- Update the "A message whose handler throws..." paragraph: failure now also
  triggers a callback POST before the no-retry log-and-ack.
- Layout section: add `StatusNotifier: posts the build outcome to callback_status_url` under `Job/`.

### 6. Cross-repo follow-up (separate repo: `publish`)

`integration-test/docker-compose.yml` (in `publish`, not `ssg-worker`) sets
the `worker` service's env block (`AMQP_DSN`, `AMQP_HEARTBEAT`,
`JOB_STORAGE_DIR`, `MAX_DOWNLOAD_MB`). Add `PUBLIC_SITE_BASE_URL` there too
(e.g. `http://localhost:8080`, matching the `api` service's own port) or the
integration stack's worker container will fail to boot once this lands. Not
required for `ssg-worker`'s own CI/tests to pass, only for the end-to-end
stack.

## Verification

```bash
php bin/console lint:yaml config
php bin/console lint:container      # confirm PUBLIC_SITE_BASE_URL doesn't need adding to ci.yml's env: block
php bin/phpunit
```
