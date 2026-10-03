<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Covers App\Controller\HealthController. */
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

    /**
     * /health has its own `security: false` firewall, so no authenticator reads the header and a probe with a stale token still gets a 200.
     * A PUBLIC_ACCESS access_control rule would still run the authenticator and answer 401, so this test fails if /health is changed to one.
     */
    public function testHealthIgnoresAnInvalidApiToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health', server: [
            'HTTP_AUTHORIZATION' => 'Bearer not-the-configured-token',
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
    }
}
