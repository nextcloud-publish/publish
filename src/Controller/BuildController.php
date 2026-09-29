<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\BuildJob;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Validates a build request and enqueues it as a BuildJob for ssg-worker.
 * The security firewall authenticates the request before this controller is resolved.
 *
 * @param MessageBusInterface $bus The bus that publishes the BuildJob to the `builds` transport.
 */
final class BuildController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Allow-list for static_site_id and slug, which ssg-worker uses as directory names.
     * Rejecting here gives the caller a synchronous 400 instead of a failure callback after the worker's retry.
     * Same expression as ssg-worker's JobWorkspace::SAFE_ID, so change both repos together.
     * Dots are excluded, so `..` cannot pass.
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    /** Rendered into every page's header; escaped downstream, but not unbounded. */
    private const MAX_TITLE_LENGTH = 200;

    /**
     * Checks $payload for the required fields static_site_id, callback_status_url, content_download_url, slug and title.
     *
     * @param array $payload The decoded request body.
     * @return bool true when all required fields are set, false when any is missing
     */
    private function incomingPayloadComplete(array $payload): bool
    {
        return isset($payload['static_site_id'])
            && isset($payload['callback_status_url'])
            && isset($payload['content_download_url'])
            && isset($payload['slug'])
            && isset($payload['title']);
    }

    /**
     * Finds the first of static_site_id and slug that is not a safe directory name.
     * The is_string() check matters: a non-string would reach BuildJob's `string` type as a TypeError and be answered with a 503 instead of a 400.
     *
     * @param array $payload The decoded request body, already checked by incomingPayloadComplete().
     * @return ?string the name of the first unsafe field, or null when both are safe
     */
    private function firstUnsafeField(array $payload): ?string
    {
        foreach (['static_site_id', 'slug'] as $field) {
            if (!\is_string($payload[$field]) || preg_match(self::SAFE_ID, $payload[$field]) !== 1) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Enqueues a build and answers 202 once the broker confirms it.
     * Answers 401 for a missing or wrong token, 400 for an invalid payload and 503 when the build could not be enqueued.
     *
     * @param Request $request The POST /build request.
     * @return JsonResponse the status of the build request
     * @throws JsonException if the body is not valid JSON; Symfony answers it with a 400
     */
    #[Route('/build', name: 'build', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // The firewall answers an unauthenticated request with a 401 on kernel.request, so
        // toArray() and its JsonException are never reached without a valid token.
        $payload = $request->toArray();

        if (!$this->incomingPayloadComplete($payload)) {
            return new JsonResponse(
                ['status' => 'invalid', 'error' => 'missing required fields'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (($unsafe = $this->firstUnsafeField($payload)) !== null) {
            return new JsonResponse(
                ['status' => 'invalid', 'error' => sprintf('%s must match [A-Za-z0-9_-]{1,128}', $unsafe)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // is_string() for the same reason as in firstUnsafeField(): a non-string would become a 503.
        $title = \is_string($payload['title']) ? trim($payload['title']) : '';
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return new JsonResponse(
                ['status' => 'invalid', 'error' => sprintf('title must be a non-empty string of at most %d characters', self::MAX_TITLE_LENGTH)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $build = new BuildJob(
            build_id: Uuid::v7()->toRfc4122(),
            static_site_id: $payload['static_site_id'],
            slug: $payload['slug'],
            title: $title,
            content_download_url: $payload['content_download_url'],
            callback_status_url: $payload['callback_status_url'],
            created_at: (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        );

        try {
            // Blocks until the broker confirms the publish (confirm_timeout in config/packages/messenger.yaml).
            $this->bus->dispatch($build);
        } catch (\Throwable $e) {
            // Broader than Messenger's TransportException on purpose: an unset AMQP_DSN or a
            // serialization failure must not produce a 202 either.
            return new JsonResponse(
                ['status' => 'error', 'error' => 'could not enqueue build'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(['status' => 'enqueued'], Response::HTTP_ACCEPTED);
    }
}
