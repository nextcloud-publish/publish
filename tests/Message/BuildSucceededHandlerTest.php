<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Callback\StatusNotifier;
use App\Message\BuildSucceeded;
use App\Message\BuildSucceededHandler;
use App\Storage\BuildPromoter;
use App\Storage\JobLayout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Real collaborators over a temp tree, matching ssg-worker's handler tests --
 * the storage classes are final and could not be mocked anyway.
 *
 * The case worth having is the last one: the callback fails, the message is
 * retried, and the promotion must NOT happen twice. That property is the reason
 * this design gets away with one queue instead of two.
 */
final class BuildSucceededHandlerTest extends TestCase
{
    private const SITE = 'site-42';
    private const BUILD = '16ef078ad37fd894';
    private const CALLBACK = 'https://cloud.example.org/collectives/status/1234';

    private string $root;
    private JobLayout $layout;
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/publish-succeeded-test-' . bin2hex(random_bytes(6));
        $this->layout = new JobLayout(
            $this->root . '/build_temp',
            $this->root . '/published',
            $this->root . '/build_failed',
        );

        $this->logFile = sys_get_temp_dir() . '/publish-succeeded-log-' . bin2hex(random_bytes(6)) . '.log';
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

    private function givenBuildOutput(string $contents = '<h1>hello</h1>'): void
    {
        $output = $this->layout->buildOutputDir(self::SITE, self::BUILD);
        mkdir($output, 0o750, true);
        file_put_contents($output . '/index.html', $contents);
    }

    private function handler(MockHttpClient $client): BuildSucceededHandler
    {
        return new BuildSucceededHandler(
            new BuildPromoter($this->layout),
            new StatusNotifier($client),
        );
    }

    private function message(): BuildSucceeded
    {
        return new BuildSucceeded(
            build_id: self::BUILD,
            static_site_id: self::SITE,
            slug: 'some_collective',
            pages: 7,
            callback_status_url: self::CALLBACK,
            finished_at: '2026-09-16T12:00:00+00:00',
        );
    }

    public function testPublishesTheSiteThenReportsSuccess(): void
    {
        $this->givenBuildOutput();
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 200]));

        ($this->handler($client))($this->message());

        self::assertFileExists($this->layout->publishedSiteDir(self::SITE) . '/index.html');
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testTheCallbackRunsAfterTheFilesystemWork(): void
    {
        // Order matters: the callback is the step that fails for reasons
        // outside this system, so it has to be last, with everything before it
        // already durable.
        $this->givenBuildOutput();

        $publishedWhenCalled = null;
        $client = new MockHttpClient(function () use (&$publishedWhenCalled): MockResponse {
            $publishedWhenCalled = is_file($this->layout->publishedSiteDir(self::SITE) . '/index.html');

            return new MockResponse('', ['http_code' => 200]);
        });

        ($this->handler($client))($this->message());

        self::assertTrue($publishedWhenCalled, 'the site must already be live when the callback fires');
    }

    /**
     * The retry case this whole design rests on. A 503 makes the handler throw,
     * Messenger retries the WHOLE handler, and the promotion must be a no-op the
     * second time -- otherwise a client endpoint that is down for two minutes
     * would republish the site five times.
     */
    public function testARetriedCallbackDoesNotRepublishTheSite(): void
    {
        $this->givenBuildOutput();

        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 200]),
        ]);
        $handler = $this->handler($client);

        try {
            $handler($this->message());
            self::fail('Expected the 503 to surface so the transport retries.');
        } catch (\RuntimeException) {
            // Expected: the site is live, the callback is not delivered yet.
        }

        self::assertFileExists($this->layout->publishedSiteDir(self::SITE) . '/index.html');

        // The retry: same message, second delivery.
        $handler($this->message());

        self::assertSame(2, $client->getRequestsCount());
        self::assertSame(
            '<h1>hello</h1>',
            file_get_contents($this->layout->publishedSiteDir(self::SITE) . '/index.html'),
        );
    }

    public function testAFailedPromotionNeverReportsSuccess(): void
    {
        // Nothing to promote: the client must not be told the site is live.
        $client = new MockHttpClient();

        try {
            ($this->handler($client))($this->message());
            self::fail('Expected the missing build output to be refused.');
        } catch (\Throwable) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }
}
