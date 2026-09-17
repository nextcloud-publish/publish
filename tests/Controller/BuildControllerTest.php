<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\BuildJob;
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
