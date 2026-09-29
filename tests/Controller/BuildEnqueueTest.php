<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BuildController;
use App\Message\BuildJob;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Covers the dispatch path of App\Controller\BuildController without a kernel.
 * The controller does not extend AbstractController, so it is constructed directly with a mocked bus.
 * A directly constructed controller never passes the security firewall, so the 401 cases are in BuildControllerTest.
 */
final class BuildEnqueueTest extends TestCase
{
    /** A complete, valid build request payload. */
    private const PAYLOAD = [
        'static_site_id' => '1234-5678',
        'content_download_url' => 'https://cloud.example.com/collectives/publish/1234-5678',
        'callback_status_url' => 'https://cloud.example.com/collectives/publish/1234-5678',
        'slug' => 'some_collective',
        'title' => 'Some Collective',
    ];

    private static function request(array $payload): Request
    {
        return Request::create(
            '/build',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($payload),
        );
    }

    /**
     * Builds the controller under test.
     *
     * @param MessageBusInterface $bus The bus the controller dispatches to.
     * @return BuildController the controller
     */
    private static function controller(MessageBusInterface $bus): BuildController
    {
        return new BuildController($bus);
    }

    public function testDispatchesBuildJobAndReturns202(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (BuildJob $build): bool {
                self::assertSame(self::PAYLOAD['static_site_id'], $build->static_site_id);
                self::assertSame(self::PAYLOAD['slug'], $build->slug);
                self::assertSame(self::PAYLOAD['content_download_url'], $build->content_download_url);
                self::assertSame(self::PAYLOAD['callback_status_url'], $build->callback_status_url);
                self::assertSame(self::PAYLOAD['title'], $build->title);

                self::assertInstanceOf(UuidV7::class, Uuid::fromString($build->build_id));

                self::assertInstanceOf(
                    \DateTimeImmutable::class,
                    \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $build->created_at),
                );

                return true;
            }))
            // The real bus returns the Envelope it dispatched, so the double does too.
            ->willReturnCallback(static fn (BuildJob $build): Envelope => new Envelope($build));

        $controller = self::controller($bus);
        $response = $controller(self::request(self::PAYLOAD));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"enqueued"}',
            (string) $response->getContent(),
        );
    }

    public function testReturns503WhenDispatchFails(): void
    {
        // Messenger wraps broker failures (connection refused, unset AMQP_DSN) in TransportException.
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willThrowException(new TransportException('connection refused'));

        $controller = self::controller($bus);
        $response = $controller(self::request(self::PAYLOAD));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"error","error":"could not enqueue build"}',
            (string) $response->getContent(),
        );
    }

    public function testRejectsIncompletePayloadWithoutDispatching(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $payload = self::PAYLOAD;
        unset($payload['slug']);

        $controller = self::controller($bus);
        $response = $controller(self::request($payload));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"missing required fields"}',
            (string) $response->getContent(),
        );
    }
}
