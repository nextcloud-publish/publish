<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\BuildJob;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class BuildController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    // Checks the payload for the required fields:
    // static_site_id, callback_status_url, content_download_url, slug
    private function incomingPayloadComplete(array $payload): bool
    {
        return isset($payload['static_site_id'])
            && isset($payload['callback_status_url'])
            && isset($payload['content_download_url'])
            && isset($payload['slug']);
    }

    #[Route('/build', name: 'build', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Authentication: config/packages/security.yaml denies everything
        // outside /health, and the firewall answers on kernel.request -- before
        // the controller is resolved. That is also what keeps toArray() below
        // out of reach of an unauthenticated caller: it throws JsonException on
        // a malformed body, so a bad token plus a bad body is still a 401 and
        // never a 500.

        $payload = $request->toArray();

        if (!$this->incomingPayloadComplete($payload)) {
            return new JsonResponse(
                ['status' => 'invalid', 'error' => 'missing required fields'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // A random opaque id for this build, stamped on the queued message so the
        // job can be traced across services.
        $buildId = bin2hex(random_bytes(8));

        $build = new BuildJob(
            build_id: $buildId,
            static_site_id: $payload['static_site_id'],
            slug: $payload['slug'],
            content_download_url: $payload['content_download_url'],
            callback_status_url: $payload['callback_status_url'],
            created_at: (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        );

        try {
            // BuildJob is routed to the `builds` transport, so this publishes to
            // the broker and blocks until it confirms (see confirm_timeout in
            // config/packages/messenger.yaml)
            $this->bus->dispatch($build);
        } catch (\Throwable $e) {
            // Something went wrong reaching the broker (including an unset AMQP_DSN):
            // report it and do NOT return 202, so the caller knows the build was not
            // enqueued. Deliberately broader than Messenger's TransportException so
            // that a missing env var or a serialization failure cannot become a 202
            // either.
            return new JsonResponse(
                ['status' => 'error', 'error' => 'could not enqueue build'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(['status' => 'enqueued'], Response::HTTP_ACCEPTED);
    }
}
