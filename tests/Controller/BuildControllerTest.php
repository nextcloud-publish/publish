<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\BuildJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Covers App\Controller\BuildController through the kernel, including the security firewall.
 * The test kernel swaps the AMQP transport for in-memory://, so sent messages can be inspected.
 */
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
        'title' => 'Some Collective',
        'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
    ];

    public function testBuildEnqueuedOnCompletePayload(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', self::PAYLOAD, self::AUTH);

        // A 202 means every step of the auth chain granted access: header extractor,
        // ApiTokenHandler, api_clients provider, ROLE_API and the access_control rule.
        self::assertResponseStatusCodeSame(202);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"status":"enqueued"}',
            (string) $client->getResponse()->getContent(),
        );

        self::assertEmpty(
            $client->getResponse()->headers->getCookies(),
            'A stateless firewall must not issue a session cookie.',
        );

        // The message went through the real bus and routing config, not a test double.
        $transport = static::getContainer()->get('messenger.transport.builds');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $sent = $transport->getSent();
        self::assertCount(1, $sent);

        $build = $sent[0]->getMessage();
        self::assertInstanceOf(BuildJob::class, $build);
        self::assertTrue(Uuid::isValid($build->build_id));
        self::assertSame('1234-5678', $build->static_site_id);
        self::assertSame('some_collective', $build->slug);

        self::assertSame('Some Collective', $build->title);
    }

    /** The slug allow-list excludes spaces, so a readable heading has to travel as its own field. */
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
     * Provides every unsafe value once for slug and once for static_site_id.
     *
     * @return array<string, array{string, string}> field name and unsafe value, keyed by case name
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

    /** Both fields become directory names in ssg-worker, so they are rejected here with a synchronous 400. */
    #[DataProvider('provideUnsafeDirectoryNames')]
    public function testBuildRejectedOnAnUnsafeDirectoryName(string $field, string $value): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => 'some_collective',
            'title' => 'Some Collective',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            $field => $value,
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString($field, (string) $client->getResponse()->getContent());

        $transport = static::getContainer()->get('messenger.transport.builds');
        self::assertCount(0, $transport->getSent(), 'an unsafe name must never be enqueued');
    }

    /** Without the is_string() check, the TypeError from BuildJob would be caught as a 503. */
    public function testANonStringSlugIsABadRequestNotAServiceError(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => ['not', 'a', 'string'],
            'title' => 'Some Collective',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Provides title values that are present but not usable.
     *
     * @return array<string, array{mixed}> the title value, keyed by case name
     */
    public static function provideInvalidTitles(): array
    {
        return [
            'blank' => ['   '],
            'empty' => [''],
            'non-string' => [['not', 'a', 'string']],
            'too long' => [str_repeat('a', 201)],
        ];
    }

    #[DataProvider('provideInvalidTitles')]
    public function testBuildRejectedOnAnInvalidTitle(mixed $title): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => 'some_collective',
            'title' => $title,
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('title', (string) $client->getResponse()->getContent());
        self::assertCount(0, static::getContainer()->get('messenger.transport.builds')->getSent());
    }

    public function testBuildRejectedOnAMissingTitle(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', [
            'static_site_id' => '1234-5678',
            'slug' => 'some_collective',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/1234-5678',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"missing required fields"}',
            (string) $client->getResponse()->getContent(),
        );
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
     * Provides each way a request can fail to authenticate, with the challenge it gets.
     * HeaderAccessTokenExtractor matches `/^Bearer\s+([\w\-\+~\/\.]+=*)$/` case-sensitively, so the first four yield no token and Symfony's default 401 has no challenge.
     * Only the wrong token reaches ApiTokenHandler, and AccessTokenAuthenticator adds `error="invalid_token"` to that one (RFC 6750 §3).
     *
     * @return iterable<string, array{?string, ?string}> the Authorization header (null for none) and the expected WWW-Authenticate value (null for none), keyed by case name
     */
    public static function unauthenticatedRequests(): iterable
    {
        yield 'no Authorization header' => [null, null];
        yield 'non-bearer scheme' => ['Basic ' . self::TOKEN, null];
        yield 'bare token without scheme' => [self::TOKEN, null];
        yield 'lower-case bearer scheme' => ['bearer ' . self::TOKEN, null];
        yield 'wrong token' => [
            'Bearer ' . str_repeat('x', strlen(self::TOKEN)),
            'Bearer error="invalid_token",error_description="Invalid credentials."',
        ];
    }

    #[DataProvider('unauthenticatedRequests')]
    public function testBuildRejectedWithoutAValidApiToken(?string $authorization, ?string $challenge): void
    {
        $server = $authorization === null ? [] : ['HTTP_AUTHORIZATION' => $authorization];

        // The payload is otherwise valid, so the 401 is what stopped it.
        $client = static::createClient();
        $client->jsonRequest('POST', '/build', self::PAYLOAD, $server);

        self::assertResponseStatusCodeSame(401);
        self::assertSame($challenge, $client->getResponse()->headers->get('WWW-Authenticate'));

        $transport = static::getContainer()->get('messenger.transport.builds');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(0, $transport->getSent());
    }

    public function testMalformedJsonWithoutATokenIsRejectedBeforeItIsParsed(): void
    {
        // toArray() would throw JsonException on this body, so a 401 proves the firewall
        // answered before the controller was resolved.
        $client = static::createClient();
        $client->request(
            'POST',
            '/build',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: 'not json at all',
        );

        self::assertResponseStatusCodeSame(401);
    }
}
