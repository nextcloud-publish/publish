<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\StaticSiteController;
use App\Message\BuildJob;
use App\Message\BuildOperation;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Covers the dispatch path of App\Controller\StaticSiteController without a kernel or a database.
 * The controller does not extend AbstractController, so it is constructed directly with a mocked bus.
 * A directly constructed controller never passes the security firewall, so the 401 cases are in StaticSiteControllerTest.
 */
final class StaticSiteEnqueueTest extends TestCase
{
    /** A complete, valid build request payload. */
    private const PAYLOAD = [
        'static_site_id' => '0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        'build_id' => '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b',
        'content_download_url' => 'https://cloud.example.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        'callback_status_url' => 'https://cloud.example.com/collectives/publish/0192f3c4-7b1e-7a3e-9f4c-2d5e8a1b6c70',
        'slug' => 'some_collective',
        'title' => 'Some Collective',
    ];

    /** Records what the controller under test logs. */
    private TestHandler $logged;

    protected function setUp(): void
    {
        $this->logged = new TestHandler();
    }

    /**
     * Builds a POST /static-sites request with $payload as its JSON body.
     *
     * @param array<string, mixed> $payload The request body.
     * @return Request the request
     */
    private static function request(array $payload): Request
    {
        return Request::create(
            '/static-sites',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['data' => $payload]),
        );
    }

    /**
     * Builds the controller under test.
     *
     * @param MessageBusInterface $bus The bus the controller dispatches to.
     * @return StaticSiteController the controller, logging into $this->logged
     */
    private function controller(MessageBusInterface $bus): StaticSiteController
    {
        return new StaticSiteController($bus, new Logger('app', [$this->logged]), self::emptyEntityManager(), 600);
    }

    /**
     * Returns an entity manager that finds no site and stores nothing.
     * The database part is covered through the kernel in StaticSiteControllerTest.
     *
     * @return EntityManagerInterface the stub
     */
    public static function emptyEntityManager(): EntityManagerInterface
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        return $entityManager;
    }

    public function testDispatchesBuildJobAndReturns202(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (BuildJob $build): bool {
                self::assertSame(self::PAYLOAD['static_site_id'], $build->static_site_id);
                self::assertSame(BuildOperation::Create, $build->operation);
                self::assertSame(self::PAYLOAD['slug'], $build->slug);
                self::assertSame(self::PAYLOAD['content_download_url'], $build->content_download_url);
                self::assertSame(self::PAYLOAD['callback_status_url'], $build->callback_status_url);
                self::assertSame(self::PAYLOAD['title'], $build->title);
                self::assertNull($build->render_options);

                self::assertSame(self::PAYLOAD['build_id'], $build->build_id);
                self::assertInstanceOf(UuidV7::class, Uuid::fromString($build->build_id));

                self::assertInstanceOf(
                    \DateTimeImmutable::class,
                    \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $build->created_at),
                );

                return true;
            }))
            // The real bus returns the Envelope it dispatched, so the double does too.
            ->willReturnCallback(static fn (BuildJob $build): Envelope => new Envelope($build));

        $controller = $this->controller($bus);
        $response = $controller->create(self::request(self::PAYLOAD));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"enqueued"}',
            (string) $response->getContent(),
        );
        self::assertTrue($this->logged->hasInfoThatContains('Build enqueued'));
    }

    public function testPassesRenderOptionsThroughUnchecked(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (BuildJob $build): bool {
                self::assertSame(['theme' => 'colorful', 'further_key' => 'further value'], $build->render_options);

                return true;
            }))
            ->willReturnCallback(static fn (BuildJob $build): Envelope => new Envelope($build));

        $payload = [
            ...self::PAYLOAD,
            'render_options' => ['theme' => 'colorful', 'further_key' => 'further value'],
        ];

        $controller = self::controller($bus);
        $response = $controller->create(self::request($payload));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonObjectRenderOptions(): array
    {
        return [
            'list' => [['colorful', 'minimal']],
            'string' => ['colorful'],
        ];
    }

    /** ssg-library validates render_options, so publish enqueues any value unchanged. */
    #[DataProvider('nonObjectRenderOptions')]
    public function testEnqueuesANonObjectRenderOptionsUnchanged(mixed $renderOptions): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (BuildJob $build) use ($renderOptions): bool {
                self::assertSame($renderOptions, $build->render_options);

                return true;
            }))
            ->willReturnCallback(static fn (BuildJob $build): Envelope => new Envelope($build));

        $controller = self::controller($bus);
        $response = $controller->create(self::request([...self::PAYLOAD, 'render_options' => $renderOptions]));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
    }

    /** An empty JSON object decodes to [], which ssg-library takes as "no options". */
    public function testEnqueuesAnEmptyRenderOptionsObjectAsEmptyArray(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (BuildJob $build): bool {
                self::assertSame([], $build->render_options);

                return true;
            }))
            ->willReturnCallback(static fn (BuildJob $build): Envelope => new Envelope($build));

        $controller = self::controller($bus);
        $response = $controller->create(self::request([...self::PAYLOAD, 'render_options' => new \stdClass()]));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
    }

    public function testReturns503WhenDispatchFails(): void
    {
        // Messenger wraps broker failures (connection refused, unset AMQP_DSN) in TransportException.
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willThrowException(new TransportException('connection refused'));

        $controller = $this->controller($bus);
        $response = $controller->create(self::request(self::PAYLOAD));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"error","error":"could not enqueue build"}',
            (string) $response->getContent(),
        );

        // The 503 body names no cause; only the log does.
        $records = $this->logged->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame('Could not enqueue build', $records[0]->message);
        self::assertSame(self::PAYLOAD['slug'], $records[0]->context['slug']);
        self::assertInstanceOf(TransportException::class, $records[0]->context['exception']);
    }

    public function testRejectsIncompletePayloadWithoutDispatching(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $payload = self::PAYLOAD;
        unset($payload['slug']);

        $controller = $this->controller($bus);
        $response = $controller->create(self::request($payload));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"missing required fields"}',
            (string) $response->getContent(),
        );
        self::assertTrue($this->logged->hasWarningThatContains('Build request rejected'));
    }
}
