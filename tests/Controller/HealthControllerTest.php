<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Covers App\Controller\HealthController on its public /health and its token-protected /auth-verification route. */
final class HealthControllerTest extends WebTestCase
{
    /** Matches PUBLISH_API_TOKEN in .env.test, which the test kernel boots with. */
    private const TOKEN = 'test-api-token-0123456789';

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

    /** Uptime monitors probe /health with HEAD and no token; Symfony answers HEAD on GET routes and drops the body. */
    public function testHealthAnswersHeadWithoutABody(): void
    {
        $client = static::createClient();
        $client->request('HEAD', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $client->getResponse()->getContent());
    }

    public function testAuthVerificationReturnsOkWithTheApiToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth-verification', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
    }

    /**
     * Fails if /auth-verification ever ends up on a firewall that skips authentication, such as `health`.
     *
     * @return array<string, array{array<string, string>}> the server parameters per case
     */
    public static function provideRejectedCredentials(): array
    {
        return [
            'no token' => [[]],
            'wrong token' => [['HTTP_AUTHORIZATION' => 'Bearer not-the-configured-token']],
        ];
    }

    /**
     * @param array<string, string> $server The server parameters, carrying the Authorization header if any.
     */
    #[DataProvider('provideRejectedCredentials')]
    public function testAuthVerificationRejectsAMissingOrWrongToken(array $server): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth-verification', server: $server);

        self::assertResponseStatusCodeSame(401);
    }
}
