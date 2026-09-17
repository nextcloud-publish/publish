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

    /** A complete, valid build request payload. */
    private const PAYLOAD = [
        'static_site_id' => '1234-5678',
        'slug' => 'some_collective',
        'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
    ];

    public function testBuildEnqueuedOnCompletePayload(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', self::PAYLOAD, self::AUTH);

        self::assertResponseStatusCodeSame(202);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"status":"enqueued"}',
            (string) $client->getResponse()->getContent(),
        );

        // Reaching the controller at all proves the whole chain granted:
        // header extractor -> ApiTokenHandler -> api_clients provider ->
        // ROLE_API -> the access_control rule.
        self::assertEmpty(
            $client->getResponse()->headers->getCookies(),
            'A stateless firewall must not issue a session cookie.',
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
        $payload = self::PAYLOAD;
        unset($payload['slug']);

        $client = static::createClient();
        $client->jsonRequest('POST', '/build', $payload, self::AUTH);

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

    /**
     * Every way a caller can fail to prove who it is, and the challenge each
     * one earns.
     *
     * The first four never produce a token at all: Symfony's
     * HeaderAccessTokenExtractor matches `/^Bearer\s+([\w\-\+~\/\.]+=*)$/`
     * without the `i` flag, so anything else is simply not credentials, the
     * request is denied by access_control, and BearerEntryPoint answers with
     * the bare challenge. Only the last one reaches ApiTokenHandler, and RFC
     * 6750 §3 is what puts `error="invalid_token"` on that one alone.
     *
     * The lower-case case is stricter than the old hand-rolled check, which
     * accepted it. That narrows what authenticates, never widens it.
     */
    public static function unauthenticatedRequests(): iterable
    {
        yield 'no Authorization header' => [null, 'Bearer'];
        yield 'non-bearer scheme' => ['Basic ' . self::TOKEN, 'Bearer'];
        yield 'bare token without scheme' => [self::TOKEN, 'Bearer'];
        yield 'lower-case bearer scheme' => ['bearer ' . self::TOKEN, 'Bearer'];
        yield 'wrong token' => [
            'Bearer ' . str_repeat('x', strlen(self::TOKEN)),
            'Bearer error="invalid_token",error_description="Invalid credentials."',
        ];
    }

    #[DataProvider('unauthenticatedRequests')]
    public function testBuildRejectedWithoutAValidApiToken(?string $authorization, string $challenge): void
    {
        $server = $authorization === null ? [] : ['HTTP_AUTHORIZATION' => $authorization];

        // A complete, otherwise-valid payload: the 401 has to be what stopped
        // it, and nothing may have reached the transport on the way.
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', self::PAYLOAD, $server);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', $challenge);
        self::assertSame('', (string) $client->getResponse()->getContent());

        $transport = static::getContainer()->get('messenger.transport.builds');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(0, $transport->getSent());
    }

    public function testMalformedJsonWithoutATokenIsRejectedBeforeItIsParsed(): void
    {
        // BuildController::__invoke() calls toArray(), which throws
        // JsonException on a body like this. The firewall answers on
        // kernel.request, so the controller is never resolved and the caller
        // gets a 401 rather than a 500. Nothing else covers this.
        $client = static::createClient();
        $client->request(
            'POST',
            '/build',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: 'not json at all',
        );

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
    }
}
