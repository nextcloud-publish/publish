<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\BuildJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class BuildControllerTest extends WebTestCase
{
    /** Matches PUBLISH_API_TOKEN in .env.test, which the test kernel boots with. */
    private const TOKEN = 'test-api-token-0123456789';

    /** Server parameters carrying the bearer token POST /build requires. */
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN];

    public function testBuildEnqueuedOnCompletePayload(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => 'some_collective',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(202);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"status":"enqueued"}',
            (string) $client->getResponse()->getContent(),
        );

        // config/packages/messenger.yaml swaps the AMQP transport for
        // in-memory:// under when@test, so the message really went through the
        // bus and the routing configuration -- not just past a test double.
        $transport = static::getContainer()->get('messenger.transport.builds');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $sent = $transport->getSent();
        self::assertCount(1, $sent);

        $build = $sent[0]->getMessage();
        self::assertInstanceOf(BuildJob::class, $build);
        self::assertSame('1234-5678', $build->static_site_id);
        self::assertSame('some_collective', $build->slug);

        // No title was sent, so the slug stands in for one.
        self::assertSame('some_collective', $build->title);
    }

    /**
     * The slug names a directory in ssg-worker now, so it cannot also carry a
     * human-readable heading: the allow-list that makes it path-safe excludes
     * spaces. The two travel separately.
     */
    public function testATitleTravelsSeparatelyFromTheSlug(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => 'my-team-handbook',
            'title' => 'My Team Handbook',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(202);

        $build = static::getContainer()->get('messenger.transport.builds')->getSent()[0]->getMessage();
        self::assertSame('my-team-handbook', $build->slug);
        self::assertSame('My Team Handbook', $build->title);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideUnsafeDirectoryNames(): array
    {
        $cases = [
            'parent traversal' => '../escape',
            'nested traversal' => '../../etc/cron.d',
            'bare dotdot' => '..',
            'absolute path' => '/etc/cron.d',
            'contains slash' => 'site/nested',
            'contains a space' => 'My Team Handbook',
            'contains a dot' => 'site.name',
            'empty' => '',
            'too long' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ];

        $out = [];
        foreach ($cases as $name => $value) {
            $out['slug: ' . $name] = ['slug', $value];
            $out['static_site_id: ' . $name] = ['static_site_id', $value];
        }

        return $out;
    }

    /**
     * Both fields become directory names in ssg-worker. Rejecting them here is
     * what turns a filesystem problem into a synchronous 400, instead of a
     * failure callback ~75 seconds and two build attempts later.
     */
    #[DataProvider('provideUnsafeDirectoryNames')]
    public function testBuildRejectedOnAnUnsafeDirectoryName(string $field, string $value): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => 'some_collective',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            $field => $value,
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString($field, (string) $client->getResponse()->getContent());

        $transport = static::getContainer()->get('messenger.transport.builds');
        self::assertCount(0, $transport->getSent(), 'an unsafe name must never be enqueued');
    }

    /**
     * A non-string would otherwise reach BuildJob's `string` type, throw a
     * TypeError, and be caught by the dispatch handler as a 503 -- making a
     * malformed payload look like a broker outage.
     */
    public function testANonStringSlugIsABadRequestNotAServiceError(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => ['not', 'a', 'string'],
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
    }

    public function testBuildRejectedOnAnOverlongTitle(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => 'some_collective',
            'title' => str_repeat('a', 201),
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('title', (string) $client->getResponse()->getContent());
    }

    public function testBuildRejectedOnMissingPayloadValues(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678'
            ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"missing required fields"}',
            (string) $client->getResponse()->getContent(),
        );

        $transport = static::getContainer()->get('messenger.transport.builds');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(0, $transport->getSent());
    }

    public function testBuildRejectedWithoutApiToken(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => 'some_collective',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertJsonStringEqualsJsonString(
            '{"status":"unauthorized","error":"missing or invalid api token"}',
            (string) $client->getResponse()->getContent(),
        );

        // A complete, otherwise-valid payload: the 401 has to be what stopped
        // it, and nothing may have reached the transport on the way.
        $transport = static::getContainer()->get('messenger.transport.builds');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(0, $transport->getSent());
    }
}
