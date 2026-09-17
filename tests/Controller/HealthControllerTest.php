<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
    }

    public function testHealthIgnoresAnInvalidApiToken(): void
    {
        // /health has its own `security: false` firewall, so no authenticator
        // ever looks at this header. A probe sending a stale token still has to
        // get a 200 -- a health check that can 401 is useless to a load
        // balancer. This is the test that fails if anyone ever "simplifies"
        // /health into a PUBLIC_ACCESS access_control rule instead.
        $client = static::createClient();
        $client->request('GET', '/health', server: [
            'HTTP_AUTHORIZATION' => 'Bearer not-the-configured-token',
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
    }
}
