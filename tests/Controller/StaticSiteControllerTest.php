<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\StaticSiteController;
use App\Entity\StaticSite;
use App\Entity\StaticSiteStatus;
use App\Message\BuildJob;
use App\Message\BuildOperation;
use App\Tests\ResetsStaticSites;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Covers App\Controller\StaticSiteController through the kernel, including the security firewall and the database.
 * The test kernel swaps the AMQP transport for in-memory://, so sent messages can be inspected.
 * The client reboots the kernel before each further request, so the transport and the log only hold the last request's entries.
 */
final class StaticSiteControllerTest extends WebTestCase
{
    use ResetsStaticSites;

    /** Matches PUBLISH_API_TOKEN in .env.test, which the test kernel boots with. */
    private const TOKEN = 'test-api-token-0123456789';

    /** Server parameters carrying the bearer token POST /static-sites requires. */
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN];

    /** A complete, valid build request payload. */
    private const PAYLOAD = [
        'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        'build_id' => '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b',
        'slug' => 'some_collective',
        'title' => 'Some Collective',
        'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
    ];

    /**
     * Returns the envelopes sent to the builds transport, which when@test makes in-memory.
     *
     * @return Envelope[]
     */
    private static function sentToBuilds(): array
    {
        $transport = static::getContainer()->get('messenger.transport.builds');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport->getSent();
    }

    /**
     * Returns every record logged during the request, which when@test keeps in memory.
     *
     * @return LogRecord[]
     */
    private static function logged(): array
    {
        $handler = static::getContainer()->get('monolog.handler.test');
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler->getRecords();
    }

    /**
     * Returns the records our code logged to the app channel.
     *
     * @return LogRecord[]
     */
    private static function appLogged(): array
    {
        return array_values(array_filter(self::logged(), static fn (LogRecord $record): bool => $record->channel === 'app'));
    }

    /**
     * POSTs $fields to /static-sites under "data", as Collectives sends them.
     *
     * @param KernelBrowser $client The test client.
     * @param array<mixed> $fields The fields of the static site.
     * @param array<string, string> $server The server parameters, such as the Authorization header.
     */
    private static function postSite(KernelBrowser $client, array $fields, array $server = []): void
    {
        $client->jsonRequest('POST', '/static-sites', ['data' => $fields], $server);
    }

    public function testBuildEnqueuedOnCompletePayload(): void
    {
        $client = static::createClient();
        self::postSite($client, self::PAYLOAD, self::AUTH);

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
        $sent = self::sentToBuilds();
        self::assertCount(1, $sent);

        $build = $sent[0]->getMessage();
        self::assertInstanceOf(BuildJob::class, $build);
        self::assertSame('0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b', $build->build_id, 'the build_id the client sent, not a new one');
        self::assertSame('0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70', $build->static_site_id);
        self::assertSame('some_collective', $build->slug);

        self::assertSame('Some Collective', $build->title);

        $logged = self::appLogged();
        self::assertCount(1, $logged);
        self::assertSame(Level::Info, $logged[0]->level);
        self::assertSame('Build enqueued', $logged[0]->message);
        self::assertSame(
            ['build_id' => $build->build_id, 'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70', 'operation' => 'create', 'slug' => 'some_collective'],
            $logged[0]->context,
        );
    }

    /**
     * Returns the stored site with $id as the database holds it, or null.
     *
     * @param string $id The site's UUID.
     * @return ?StaticSite the site
     */
    private static function storedSite(string $id): ?StaticSite
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        // Drops what the identity map remembers, so find() reads the entry.
        $entityManager->clear();

        return $entityManager->find(StaticSite::class, Uuid::fromString($id));
    }

    /**
     * Returns the BuildJob of the last request, failing unless exactly one was sent.
     *
     * @return BuildJob the job
     */
    private static function onlySentBuild(): BuildJob
    {
        $sent = self::sentToBuilds();
        self::assertCount(1, $sent);
        $build = $sent[0]->getMessage();
        self::assertInstanceOf(BuildJob::class, $build);

        return $build;
    }

    /**
     * Fails unless the last answer is the 409 with $error and nothing was enqueued for it.
     *
     * @param KernelBrowser $client The test client.
     * @param string $error The expected error.
     * @return void
     */
    private static function assertConflict(KernelBrowser $client, string $error): void
    {
        self::assertResponseStatusCodeSame(409);
        self::assertJsonStringEqualsJsonString(
            (string) json_encode(['status' => 'conflict', 'error' => $error]),
            (string) $client->getResponse()->getContent(),
        );
        self::assertCount(0, self::sentToBuilds(), 'a rejected request must not be enqueued');

        $logged = self::appLogged();
        self::assertCount(1, $logged);
        self::assertSame(Level::Warning, $logged[0]->level);
        self::assertSame('Build request rejected', $logged[0]->message);
        self::assertSame($error, $logged[0]->context['reason']);
    }

    public function testACreatedSiteIsStoredWithItsFirstBuild(): void
    {
        $client = static::createClient();
        self::postSite($client, self::PAYLOAD, self::AUTH);

        self::assertResponseStatusCodeSame(202);
        $build = self::onlySentBuild();

        $site = self::storedSite(self::PAYLOAD['static_site_id']);
        self::assertNotNull($site);
        self::assertSame('some_collective', $site->slug);
        self::assertSame(StaticSiteStatus::Pending, $site->status);
        self::assertNotNull($site->buildId);
        self::assertSame($build->build_id, $site->buildId->toRfc4122());
    }

    /** The id is a primary key and a directory name, so one site must not arrive in two spellings. */
    public function testAnUpperCaseIdIsStoredAndEnqueuedInLowerCase(): void
    {
        $client = static::createClient();
        self::postSite($client, [
            ...self::PAYLOAD,
            'static_site_id' => strtoupper(self::PAYLOAD['static_site_id']),
        ], self::AUTH);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(self::PAYLOAD['static_site_id'], self::onlySentBuild()->static_site_id);
        self::assertNotNull(self::storedSite(self::PAYLOAD['static_site_id']));
    }

    public function testANonUuidIdIsABadRequest(): void
    {
        $client = static::createClient();
        self::postSite($client, [...self::PAYLOAD, 'static_site_id' => '1234-5678'], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"static_site_id must be a UUID"}',
            (string) $client->getResponse()->getContent(),
        );
        self::assertCount(0, self::sentToBuilds());
    }

    /**
     * Provides build_id values that are not a UUIDv7, the version Collectives generates.
     *
     * @return array<string, array{string, mixed}> field name and invalid value, keyed by case name
     */
    public static function provideInvalidBuildIds(): array
    {
        return [
            'build_id: not a UUID' => ['build_id', 'build-1'],
            'build_id: a UUIDv4' => ['build_id', '4b6c1e2a-9d3f-4a7b-8c5e-2f1d0a9b8c7d'],
            'build_id: not a string' => ['build_id', 42],
        ];
    }

    #[DataProvider('provideInvalidBuildIds')]
    public function testABuildIdThatIsNotAUuidV7IsABadRequest(string $field, mixed $value): void
    {
        $client = static::createClient();
        self::postSite($client, [...self::PAYLOAD, $field => $value], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"build_id must be a UUIDv7"}',
            (string) $client->getResponse()->getContent(),
        );
        self::assertCount(0, self::sentToBuilds());
    }

    /** Collectives generates the build id; publish takes it as sent, in lower case like the site id. */
    public function testTheClientsBuildIdIsStoredAndEnqueuedInLowerCase(): void
    {
        $client = static::createClient();
        self::postSite($client, [...self::PAYLOAD, 'build_id' => strtoupper(self::PAYLOAD['build_id'])], self::AUTH);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(self::PAYLOAD['build_id'], self::onlySentBuild()->build_id);
        $site = self::storedSite(self::PAYLOAD['static_site_id']);
        self::assertNotNull($site);
        self::assertSame(self::PAYLOAD['build_id'], $site->buildId?->toRfc4122());
    }

    /**
     * Provides bodies that do not carry the fields under "data".
     *
     * @return array<string, array{array<mixed>}> the request body, keyed by case name
     */
    public static function provideBodiesWithoutData(): array
    {
        return [
            'fields at the top level' => [self::PAYLOAD],
            'data is a string' => [['data' => 'not an object']],
            'data is null' => [['data' => null]],
        ];
    }

    /**
     * @param array<mixed> $body The whole request body.
     */
    #[DataProvider('provideBodiesWithoutData')]
    public function testFieldsOutsideDataAreMissing(array $body): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/static-sites', $body, self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"missing required fields"}',
            (string) $client->getResponse()->getContent(),
        );
        self::assertCount(0, self::sentToBuilds());
    }

    /** POST only creates: a second request for the same id changes nothing. */
    public function testAnExistingIdIsAConflict(): void
    {
        $client = static::createClient();
        self::postSite($client, self::PAYLOAD, self::AUTH);
        self::assertResponseStatusCodeSame(202);
        $firstBuild = self::onlySentBuild();

        self::postSite($client, [...self::PAYLOAD, 'slug' => 'another_slug'], self::AUTH);

        self::assertConflict($client, 'static site already exists');
        $site = self::storedSite(self::PAYLOAD['static_site_id']);
        self::assertNotNull($site);
        self::assertSame('some_collective', $site->slug);
        self::assertNotNull($site->buildId);
        self::assertSame($firstBuild->build_id, $site->buildId->toRfc4122());
    }

    public function testASlugAnotherSiteHoldsIsAConflict(): void
    {
        $otherId = '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c71';

        $client = static::createClient();
        self::postSite($client, self::PAYLOAD, self::AUTH);
        self::assertResponseStatusCodeSame(202);

        self::postSite($client, [...self::PAYLOAD, 'static_site_id' => $otherId], self::AUTH);

        self::assertConflict($client, 'slug already taken');
        self::assertNull(self::storedSite($otherId));
    }

    /**
     * The broker cannot fail in the test kernel, so the controller gets a bus that does, and the real entity manager.
     * The insert must be rolled back: an entry left behind would answer the client's retry with a 409.
     */
    public function testAFailedDispatchLeavesNoRow(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('connection refused'));

        $request = Request::create(
            '/static-sites',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['data' => self::PAYLOAD], JSON_THROW_ON_ERROR),
        );
        $response = (new StaticSiteController($bus, new NullLogger(), $entityManager, 600))->create($request);

        self::assertSame(503, $response->getStatusCode());
        // Through the connection: a failed transaction closes the entity manager.
        self::assertFalse($entityManager->getConnection()->fetchOne('SELECT 1 FROM static_site'));
    }

    public function testNoLogRecordCarriesARequestUrl(): void
    {
        $client = static::createClient();
        self::postSite($client, self::PAYLOAD, self::AUTH);

        self::assertResponseStatusCodeSame(202);

        // Every channel logs at debug in tests, so framework lines are covered too.
        foreach (self::logged() as $record) {
            $line = (string) json_encode($record->toArray(), JSON_UNESCAPED_SLASHES);
            self::assertStringNotContainsString('/collectives/publish/', $line);
        }
    }

    public function testARejectedRequestLogsTheReason(): void
    {
        $client = static::createClient();
        self::postSite($client, ['slug' => '../etc'] + self::PAYLOAD, self::AUTH);

        self::assertResponseStatusCodeSame(400);

        $logged = self::appLogged();
        self::assertCount(1, $logged);
        self::assertSame(Level::Warning, $logged[0]->level);
        self::assertSame('Build request rejected', $logged[0]->message);
        self::assertSame(['reason' => 'slug must match [A-Za-z0-9_-]{1,128}'], $logged[0]->context);
    }

    /** The slug allow-list excludes spaces, so a readable heading has to travel as its own field. */
    public function testATitleTravelsSeparatelyFromTheSlug(): void
    {
        $client = static::createClient();
        self::postSite($client, [
            'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'build_id' => '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b',
            'slug' => 'my-team-handbook',
            'title' => 'My Team Handbook',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(202);

        $build = self::sentToBuilds()[0]->getMessage();
        self::assertInstanceOf(BuildJob::class, $build);
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

    /** Both fields become directory names in publish-worker, so they are rejected here with a synchronous 400. */
    #[DataProvider('provideUnsafeDirectoryNames')]
    public function testBuildRejectedOnAnUnsafeDirectoryName(string $field, string $value): void
    {
        $client = static::createClient();
        self::postSite($client, [
            'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'build_id' => '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b',
            'slug' => 'some_collective',
            'title' => 'Some Collective',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            $field => $value,
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString($field, (string) $client->getResponse()->getContent());

        self::assertCount(0, self::sentToBuilds(), 'an unsafe name must never be enqueued');
    }

    /** Without the is_string() check, the TypeError from BuildJob would be caught as a 503. */
    public function testANonStringSlugIsABadRequestNotAServiceError(): void
    {
        $client = static::createClient();
        self::postSite($client, [
            'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'build_id' => '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b',
            'slug' => ['not', 'a', 'string'],
            'title' => 'Some Collective',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Provides every invalid value once for content_download_url and once for callback_status_url.
     *
     * @return array<string, array{string, mixed}> field name and invalid value, keyed by case name
     */
    public static function provideInvalidUrls(): array
    {
        $cases = [
            'file scheme' => 'file:///etc/passwd',
            'ftp scheme' => 'ftp://example.org/content.tar.gz',
            'no scheme' => 'example.org/content.tar.gz',
            'no host' => 'https:///content.tar.gz',
            'empty' => '',
            'integer' => 42,
            'array' => ['not', 'a', 'string'],
        ];

        $out = [];
        foreach ($cases as $name => $value) {
            $out['content_download_url: ' . $name] = ['content_download_url', $value];
            $out['callback_status_url: ' . $name] = ['callback_status_url', $value];
        }

        return $out;
    }

    /** publish-worker fetches and calls both URLs without checking them again, so they are rejected here with a synchronous 400. */
    #[DataProvider('provideInvalidUrls')]
    public function testBuildRejectedOnAnInvalidUrl(string $field, mixed $value): void
    {
        $client = static::createClient();
        self::postSite($client, [...self::PAYLOAD, $field => $value], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString($field, (string) $client->getResponse()->getContent());
        self::assertCount(0, self::sentToBuilds());
    }

    public function testAPlainHttpUrlIsAccepted(): void
    {
        $client = static::createClient();
        self::postSite($client, [
            ...self::PAYLOAD,
            'content_download_url' => 'http://example.org/content.tar.gz',
            'callback_status_url' => 'http://example.org/status',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(202);
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
        self::postSite($client, [
            'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'build_id' => '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b',
            'slug' => 'some_collective',
            'title' => $title,
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        ], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('title', (string) $client->getResponse()->getContent());
        self::assertCount(0, self::sentToBuilds());
    }

    public function testBuildRejectedOnAMissingTitle(): void
    {
        $client = static::createClient();
        self::postSite($client, [
            'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'build_id' => '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b',
            'slug' => 'some_collective',
            'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
            'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
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
        self::postSite($client, $payload, self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"missing required fields"}',
            (string) $client->getResponse()->getContent(),
        );

        self::assertCount(0, self::sentToBuilds());
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
        self::postSite($client, self::PAYLOAD, $server);

        self::assertResponseStatusCodeSame(401);
        self::assertSame($challenge, $client->getResponse()->headers->get('WWW-Authenticate'));

        self::assertCount(0, self::sentToBuilds());
    }

    public function testAWrongTokenIsLoggedWithTheClientAddress(): void
    {
        $client = static::createClient();
        self::postSite($client, self::PAYLOAD, [
            'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('x', strlen(self::TOKEN)),
            'REMOTE_ADDR' => '203.0.113.7',
        ]);

        self::assertResponseStatusCodeSame(401);

        $logged = self::appLogged();
        self::assertCount(1, $logged);
        self::assertSame(Level::Warning, $logged[0]->level);
        self::assertSame('Rejected API token', $logged[0]->message);
        self::assertSame(['ip' => '203.0.113.7'], $logged[0]->context);
    }

    public function testARequestWithoutATokenIsNotLogged(): void
    {
        // No token reaches an authenticator, so there is no failure to log.
        $client = static::createClient();
        self::postSite($client, self::PAYLOAD);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], self::appLogged());
    }

    public function testMalformedJsonWithoutATokenIsRejectedBeforeItIsParsed(): void
    {
        // toArray() would throw JsonException on this body, so a 401 proves the firewall
        // answered before the controller was resolved.
        $client = static::createClient();
        $client->request(
            'POST',
            '/static-sites',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: 'not json at all',
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testMalformedJsonWithATokenIsABadRequest(): void
    {
        // toArray() throws HttpFoundation's JsonException, a RequestExceptionInterface, which the kernel answers with 400.
        $client = static::createClient();
        $client->request(
            'POST',
            '/static-sites',
            server: ['CONTENT_TYPE' => 'application/json', ...self::AUTH],
            content: 'not json at all',
        );

        self::assertResponseStatusCodeSame(400);
    }

    // --- update and delete --------------------------------------------------

    /** The build of an update or delete, a UUIDv7 other than PAYLOAD's. */
    private const NEXT_BUILD_ID = '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5c';

    /** A complete, valid update payload. */
    private const UPDATE = [
        'build_id' => self::NEXT_BUILD_ID,
        'title' => 'Some Collective, revised',
        'content_download_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
    ];

    /** A complete, valid delete payload. */
    private const DELETE = [
        'build_id' => self::NEXT_BUILD_ID,
        'callback_status_url' => 'https://cloud.somenextcloud.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
    ];

    /** The path of PAYLOAD's site. */
    private const SITE_PATH = '/static-sites/' . self::PAYLOAD['static_site_id'];

    /**
     * Creates PAYLOAD's site and sets $status on it, as publish-worker would after its first build.
     *
     * @param KernelBrowser $client The test client.
     * @param StaticSiteStatus $status The status to leave the site in.
     * @return void
     */
    private static function givenAStoredSite(KernelBrowser $client, StaticSiteStatus $status = StaticSiteStatus::Published): void
    {
        self::postSite($client, self::PAYLOAD, self::AUTH);
        self::assertResponseStatusCodeSame(202);

        self::connection()->executeStatement('UPDATE static_site SET status = ?', [$status->value]);
    }

    /**
     * Returns the test database connection.
     *
     * @return \Doctrine\DBAL\Connection the connection
     */
    private static function connection(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    public function testAnUpdateRebuildsTheSite(): void
    {
        $client = static::createClient();
        self::givenAStoredSite($client);

        $client->jsonRequest('PATCH', self::SITE_PATH, ['data' => self::UPDATE], self::AUTH);

        self::assertResponseStatusCodeSame(202);
        $build = self::onlySentBuild();
        self::assertSame(BuildOperation::Update, $build->operation);
        self::assertSame(self::PAYLOAD['static_site_id'], $build->static_site_id);
        self::assertSame(self::NEXT_BUILD_ID, $build->build_id);
        self::assertSame('some_collective', $build->slug);
        self::assertSame('Some Collective, revised', $build->title);
        self::assertNull($build->previous_slug);

        $site = self::storedSite(self::PAYLOAD['static_site_id']);
        self::assertNotNull($site);
        self::assertSame(StaticSiteStatus::Pending, $site->status);
        self::assertSame(self::NEXT_BUILD_ID, $site->buildId?->toRfc4122());
    }

    public function testAnUpdateWithANewSlugStoresItAndSendsThePreviousOne(): void
    {
        $client = static::createClient();
        self::givenAStoredSite($client);

        $client->jsonRequest('PATCH', self::SITE_PATH, ['data' => [...self::UPDATE, 'slug' => 'renamed']], self::AUTH);

        self::assertResponseStatusCodeSame(202);
        $build = self::onlySentBuild();
        self::assertSame('renamed', $build->slug);
        self::assertSame('some_collective', $build->previous_slug);
        self::assertSame('renamed', self::storedSite(self::PAYLOAD['static_site_id'])?->slug);
    }

    public function testAnUpdateToItsOwnSlugSendsNoPreviousSlug(): void
    {
        $client = static::createClient();
        self::givenAStoredSite($client);

        $client->jsonRequest('PATCH', self::SITE_PATH, ['data' => [...self::UPDATE, 'slug' => 'some_collective']], self::AUTH);

        self::assertResponseStatusCodeSame(202);
        self::assertNull(self::onlySentBuild()->previous_slug);
    }

    public function testAnUpdateToASlugAnotherSiteHoldsIsAConflict(): void
    {
        $client = static::createClient();
        self::postSite($client, [...self::PAYLOAD, 'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c71', 'slug' => 'taken'], self::AUTH);
        self::givenAStoredSite($client);

        $client->jsonRequest('PATCH', self::SITE_PATH, ['data' => [...self::UPDATE, 'slug' => 'taken']], self::AUTH);

        self::assertConflict($client, 'slug already taken');
        self::assertSame('some_collective', self::storedSite(self::PAYLOAD['static_site_id'])?->slug);
    }

    /**
     * Provides an update and a delete request for the cases both answer alike.
     *
     * @return array<string, array{string, array<string, string>}> method and payload, keyed by operation
     */
    public static function operations(): array
    {
        return [
            'update' => ['PATCH', self::UPDATE],
            'delete' => ['DELETE', self::DELETE],
        ];
    }

    /** @param array<string, string> $payload The request fields. */
    #[DataProvider('operations')]
    public function testAnUnknownSiteIsNotFound(string $method, array $payload): void
    {
        $client = static::createClient();

        $client->jsonRequest($method, self::SITE_PATH, ['data' => $payload], self::AUTH);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString(
            '{"status":"not_found","error":"static site not found"}',
            (string) $client->getResponse()->getContent(),
        );
        self::assertCount(0, self::sentToBuilds());
    }

    /** @param array<string, string> $payload The request fields. */
    #[DataProvider('operations')]
    public function testAPathIdThatIsNotAUuidIsNotFound(string $method, array $payload): void
    {
        $client = static::createClient();

        $client->jsonRequest($method, '/static-sites/not-a-uuid', ['data' => $payload], self::AUTH);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * One operation per site: a second one while the first is pending is refused.
     *
     * @param array<string, string> $payload The request fields.
     */
    #[DataProvider('operations')]
    public function testASitePendingAnotherOperationIsAConflict(string $method, array $payload): void
    {
        $client = static::createClient();
        self::givenAStoredSite($client, StaticSiteStatus::Pending);

        $client->jsonRequest($method, self::SITE_PATH, ['data' => $payload], self::AUTH);

        self::assertConflict($client, 'operation in progress');
    }

    /**
     * A site pending for longer than PENDING_TIMEOUT_SECONDS counts as stuck, so a new operation may replace it.
     *
     * @param array<string, string> $payload The request fields.
     */
    #[DataProvider('operations')]
    public function testASiteStuckInPendingAcceptsANewOperation(string $method, array $payload): void
    {
        $client = static::createClient();
        self::givenAStoredSite($client, StaticSiteStatus::Pending);
        self::connection()->executeStatement("UPDATE static_site SET updated_at = now() - interval '1 day'");

        $client->jsonRequest($method, self::SITE_PATH, ['data' => $payload], self::AUTH);

        self::assertResponseStatusCodeSame(202);
    }

    /**
     * A site whose last operation failed takes a new one.
     *
     * @param array<string, string> $payload The request fields.
     */
    #[DataProvider('operations')]
    public function testAFailedSiteAcceptsANewOperation(string $method, array $payload): void
    {
        $client = static::createClient();
        self::givenAStoredSite($client, StaticSiteStatus::Failed);

        $client->jsonRequest($method, self::SITE_PATH, ['data' => $payload], self::AUTH);

        self::assertResponseStatusCodeSame(202);
    }

    /**
     * Provides an update or a delete missing one required field.
     *
     * @return array<string, array{string, array<string, string>}> method and payload, keyed by the missing field
     */
    public static function incompleteOperations(): array
    {
        $cases = [];
        foreach (['update' => ['PATCH', self::UPDATE], 'delete' => ['DELETE', self::DELETE]] as $name => [$method, $payload]) {
            foreach (array_keys($payload) as $field) {
                $incomplete = $payload;
                unset($incomplete[$field]);
                $cases[$name . ' without ' . $field] = [$method, $incomplete];
            }
        }

        return $cases;
    }

    /** @param array<string, string> $payload The request fields. */
    #[DataProvider('incompleteOperations')]
    public function testAnOperationMissingAFieldIsABadRequest(string $method, array $payload): void
    {
        $client = static::createClient();
        self::givenAStoredSite($client);

        $client->jsonRequest($method, self::SITE_PATH, ['data' => $payload], self::AUTH);

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, self::sentToBuilds());
    }

    public function testADeleteMarksTheSitePendingAndSendsNoContent(): void
    {
        $client = static::createClient();
        self::givenAStoredSite($client);

        $client->jsonRequest('DELETE', self::SITE_PATH, ['data' => self::DELETE], self::AUTH);

        self::assertResponseStatusCodeSame(202);
        $build = self::onlySentBuild();
        self::assertSame(BuildOperation::Delete, $build->operation);
        self::assertSame('some_collective', $build->slug);
        self::assertNull($build->title);
        self::assertNull($build->content_download_url);

        $site = self::storedSite(self::PAYLOAD['static_site_id']);
        self::assertNotNull($site, 'publish-worker deletes the entry, not publish');
        self::assertSame(StaticSiteStatus::Pending, $site->status);
        self::assertSame(self::NEXT_BUILD_ID, $site->buildId?->toRfc4122());
    }

    /**
     * A failed dispatch puts the site back as it was, so it is not left pending for a job nobody runs.
     *
     * @param array<string, string> $payload The request fields.
     */
    #[DataProvider('operations')]
    public function testAFailedDispatchLeavesTheSiteAsItWas(string $method, array $payload): void
    {
        $client = static::createClient();
        self::givenAStoredSite($client);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('connection refused'));
        $controller = new StaticSiteController($bus, new NullLogger(), $entityManager, 600);

        $request = Request::create(
            self::SITE_PATH,
            $method,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['data' => [...$payload, 'slug' => 'renamed']], JSON_THROW_ON_ERROR),
        );
        $id = self::PAYLOAD['static_site_id'];
        $response = $method === 'PATCH' ? $controller->update($request, $id) : $controller->delete($request, $id);

        self::assertSame(503, $response->getStatusCode());
        $row = self::connection()->fetchAssociative('SELECT slug, status, build_id FROM static_site');
        self::assertSame(['slug' => 'some_collective', 'status' => 'published', 'build_id' => self::PAYLOAD['build_id']], $row);
    }

    public function testAnUpdateWithoutATokenIsUnauthorized(): void
    {
        $client = static::createClient();

        $client->jsonRequest('PATCH', self::SITE_PATH, ['data' => self::UPDATE]);

        self::assertResponseStatusCodeSame(401);
    }
}
