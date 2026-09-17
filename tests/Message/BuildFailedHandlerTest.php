<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Callback\StatusNotifier;
use App\Message\BuildFailed;
use App\Message\BuildFailedHandler;
use App\Storage\BuildQuarantine;
use App\Storage\JobLayout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The theme here is that nothing about the filesystem is allowed to suppress
 * the callback. This path exists to tell a client their build failed; the
 * quarantine is housekeeping alongside it, not a precondition for it.
 */
final class BuildFailedHandlerTest extends TestCase
{
    private const SITE = 'site-42';
    private const BUILD = '16ef078ad37fd894';
    private const CALLBACK = 'https://cloud.example.org/collectives/status/1234';
    private const REASON = 'Extracting content.tar.gz failed (exit 2): not in gzip format';

    private string $root;
    private JobLayout $layout;
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/publish-failed-test-' . bin2hex(random_bytes(6));
        $this->layout = new JobLayout(
            $this->root . '/build_temp',
            $this->root . '/published',
            $this->root . '/build_failed',
        );

        $this->logFile = sys_get_temp_dir() . '/publish-failed-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);

        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    private function givenAFailedJob(): void
    {
        $jobDir = $this->layout->jobDir(self::SITE, self::BUILD);
        mkdir($jobDir . '/input', 0o750, true);
        file_put_contents($jobDir . '/input/content.tar.gz', 'half a download');
    }

    private function handler(MockHttpClient $client): BuildFailedHandler
    {
        return new BuildFailedHandler(
            new BuildQuarantine($this->layout),
            new StatusNotifier($client),
        );
    }

    private function message(string $site = self::SITE): BuildFailed
    {
        return new BuildFailed(
            build_id: self::BUILD,
            static_site_id: $site,
            callback_status_url: self::CALLBACK,
            error: self::REASON,
            failed_at: '2026-09-16T12:00:00+00:00',
        );
    }

    public function testQuarantinesTheJobThenReportsFailure(): void
    {
        $this->givenAFailedJob();

        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = json_decode($options['body'], true);

            return new MockResponse('', ['http_code' => 200]);
        });

        ($this->handler($client))($this->message());

        self::assertDirectoryExists($this->layout->failedJobDir(self::BUILD));
        self::assertDirectoryDoesNotExist($this->layout->jobDir(self::SITE, self::BUILD));

        self::assertSame('failed', $seen['status']);
        self::assertSame(self::REASON, $seen['error']);
        self::assertSame(self::BUILD, $seen['build_id']);
    }

    public function testNothingIsEverPublishedOnTheFailurePath(): void
    {
        $this->givenAFailedJob();

        ($this->handler(new MockHttpClient(new MockResponse('', ['http_code' => 200]))))($this->message());

        self::assertDirectoryDoesNotExist($this->layout->publishedSiteDir(self::SITE));
    }

    /**
     * The case that makes quarantine best-effort: an unsafe static_site_id is
     * one of the things ssg-worker fails a build FOR, so there is no directory
     * to move -- and it is exactly when the client most needs to hear why.
     */
    public function testStillReportsWhenThereIsNothingToQuarantine(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 200]));

        ($this->handler($client))($this->message(site: '../escape'));

        self::assertSame(1, $client->getRequestsCount());
    }

    public function testARetriedCallbackDoesNotMoveAnythingTwice(): void
    {
        $this->givenAFailedJob();

        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 200]),
        ]);
        $handler = $this->handler($client);

        try {
            $handler($this->message());
            self::fail('Expected the 503 to surface so the transport retries.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $handler($this->message());

        self::assertSame(2, $client->getRequestsCount());
        self::assertFileExists($this->layout->failedJobDir(self::BUILD) . '/input/content.tar.gz');
    }
}
