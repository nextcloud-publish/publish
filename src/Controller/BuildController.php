<?php

declare(strict_types=1);

namespace App\Controller;

use App\Messaging\BuildQueue;
use App\Storage\JobWorkspace;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BuildController
{
    public function __construct(
        private readonly BuildQueue $buildQueue,
        private readonly JobWorkspace $jobWorkspace,
    ) {
    }

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

        // INPUT VALIDATION - COMPLETENESS CHECK
        if (!$this->incomingPayloadComplete($payload)) {
            return new JsonResponse(
                ['status' => 'invalid', 'error' => 'missing required fields'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // INPUT VALIDATION - STATIC_SITE_ID
        // Prevent path traversal
        // TODO: clearify REJECTION vs SANITIZATION
        if (!\is_string($payload['static_site_id'])
            || !JobWorkspace::isValidStaticSiteId($payload['static_site_id'])) {
            return new JsonResponse(
                ['status' => 'invalid', 'error' => 'invalid static_site_id'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // SAFE PARAMS
        // DISCUSS: Do we want an internal build_id here that gets passed to the worker?
        //          or not? Could also build job folders scoped under build_id
        $build = [
            'static_site_id' => $payload['static_site_id'],
            'slug' => $payload['slug'],
            'content_download_url' => $payload['content_download_url'],
            'callback_status_url' => $payload['callback_status_url'],
            'created_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        ];

        // CREATE FOLDERS FOR THE JOB
        try {
            $this->jobWorkspace->createJobDirectories($payload['static_site_id']);
        } catch (\InvalidArgumentException $e) {
            // Unreachable while the check above runs first, but the id is the
            // caller's mistake either way -- never report it as our outage.
            return new JsonResponse(
                ['status' => 'invalid', 'error' => 'invalid static_site_id'],
                Response::HTTP_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['status' => 'error', 'error' => 'could not prepare job storage'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        // ENQUEUE JOB
        try {
            $this->buildQueue->enqueue($build);
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['status' => 'error', 'error' => 'could not enqueue build'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(['status' => 'enqueued'], Response::HTTP_ACCEPTED);
    }
}
