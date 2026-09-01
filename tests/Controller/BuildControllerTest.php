<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BuildControllerTest extends WebTestCase
{
    public function testBuildAcceptedOnCompletePayload(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'slug' => 'some_collective',
            ]);

        self::assertResponseStatusCodeSame(202);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"status":"enqueued"}',
            (string) $client->getResponse()->getContent(),
        );
    }

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
