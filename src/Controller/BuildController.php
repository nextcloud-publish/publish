<?php

declare(strict_types=1);

namespace App\Controller;

use App\Messaging\BuildQueue;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BuildController
{
    public function __construct(private readonly BuildQueue $buildQueue)
    {
    }

    // For now simply check the payload for completeness (the required fields are present):
    // static_site_id, callback_url, content_download_url, slug
    public function incomingPayloadComplete(array $payload): bool
    {
        return isset($payload['static_site_id'])
            && isset($payload['callback_status_url'])
            && isset($payload['content_download_url'])
            && isset($payload['slug']);
    }

    #[Route('/build', name: 'build', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->toArray();

        if (!$this->incomingPayloadComplete($payload)) {
            return new JsonResponse(
                ['status' => 'invalid', 'error' => 'missing required fields'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // A random opaque id for this build, stamped on the queued message so the
        // job can be traced across services. Deliberately kept out of the response
        // body: there is no status endpoint to poll yet, and the payload carries
        // callback_status_url for the service to report back on.
        $buildId = bin2hex(random_bytes(8));

        // Copy only allow-listed keys, so extra client fields never reach the queue.
        $build = [
            'build_id' => $buildId,
            'static_site_id' => $payload['static_site_id'],
            'slug' => $payload['slug'],
            'content_download_url' => $payload['content_download_url'],
            'callback_status_url' => $payload['callback_status_url'],
            'created_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        ];

        try {
            $this->buildQueue->enqueue($build);
        } catch (\Throwable $e) {
            // Anything went wrong reaching the broker (including an unset AMQP_DSN):
            // report it and do NOT return 202, so the caller knows the build was not
            // enqueued.
            return new JsonResponse(
                ['status' => 'error', 'error' => 'could not enqueue build'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(['status' => 'enqueued'], Response::HTTP_ACCEPTED);
    }
}
