<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BuildControllerTest extends WebTestCase
{
    // The success path (a complete payload that enqueues and returns 202) is
    // covered by App\Tests\Controller\BuildEnqueueTest with a mocked BuildQueue.
    // It cannot be exercised here through the kernel: DI wires the real
    // AmqpBuildQueue, so it would try to reach a real broker and 503.
    // This case only needs to prove validation and routing work over real HTTP.
    public function testBuildRejectedOnMissingPayloadValues(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678'
            ]);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"missing required fields"}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
