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

/**
 * Unit tests for the dispatch path of BuildController.
 *
 * The controller is a plain invokable class (it does not extend
 * AbstractController), so it can be constructed directly with a mocked message
 * bus -- no kernel, no container, no broker. The same path through the kernel,
 * with a real bus and an in-memory transport, is covered by BuildControllerTest.
 */
final class BuildEnqueueTest extends TestCase
{
    /** A complete, valid build request payload. */
    private const PAYLOAD = [
        'static_site_id' => '1234-5678',
        'content_download_url' => 'https://cloud.example.com/collectives/publish/1234-5678',
        'callback_status_url' => 'https://cloud.example.com/collectives/publish/1234-5678',
        'slug' => 'some_collective',
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

    public function testDispatchesBuildJobAndReturns202(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (BuildJob $build): bool {
                // Only allow-listed fields, plus the generated build_id/created_at.
                self::assertSame(self::PAYLOAD['static_site_id'], $build->static_site_id);
                self::assertSame(self::PAYLOAD['slug'], $build->slug);
                self::assertSame(self::PAYLOAD['content_download_url'], $build->content_download_url);
                self::assertSame(self::PAYLOAD['callback_status_url'], $build->callback_status_url);

                // build_id is a random 8-byte value, hex-encoded to 16 chars.
                self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $build->build_id);
                // created_at is a parseable ATOM timestamp.
                self::assertInstanceOf(
                    \DateTimeImmutable::class,
                    \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $build->created_at),
                );

                return true;
            }))
            // The real bus returns the Envelope it dispatched, so the double has
            // to as well -- returning null would not exercise the same contract.
            ->willReturnCallback(static fn (BuildJob $build): Envelope => new Envelope($build));

        $controller = new BuildController($bus);
        $response = $controller(self::request(self::PAYLOAD));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"enqueued"}',
            (string) $response->getContent(),
        );
    }

    public function testReturns503WhenDispatchFails(): void
    {
        // A bus that throws stands in for any failure reaching the broker
        // (connection refused, unset AMQP_DSN, ...). Messenger wraps those in
        // TransportException. The caller must not get a 202.
        // A stub, not a mock: we force behaviour, we do not verify interaction.
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willThrowException(new TransportException('connection refused'));

        $controller = new BuildController($bus);
        $response = $controller(self::request(self::PAYLOAD));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"error","error":"could not enqueue build"}',
            (string) $response->getContent(),
        );
    }

    public function testRejectsIncompletePayloadWithoutDispatching(): void
    {
        // Validation short-circuits before any dispatch, so the bus must never
        // be called for an incomplete payload.
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $payload = self::PAYLOAD;
        unset($payload['slug']);

        $controller = new BuildController($bus);
        $response = $controller(self::request($payload));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"invalid","error":"missing required fields"}',
            (string) $response->getContent(),
        );
    }
}
