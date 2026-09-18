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
        private readonly ApiTokenCheck $apiTokenCheck,
    ) {
    }

    /**
     * static_site_id and slug both become DIRECTORY NAMES in ssg-worker -- the
     * job folder under JOB_STORAGE_DIR and the published site under
     * PUBLISHED_DIR. This is where that stops being the worker's problem: an id
     * it would have to refuse is a 400 here, before a build is enqueued, so the
     * caller gets a synchronous answer rather than a failure callback ~75
     * seconds later.
     *
     * Same expression as ssg-worker's JobLayout::SAFE_ID, so changing it means
     * changing both repos together. Dots are excluded outright, which keeps a
     * bare ".." from passing.
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    /** Rendered into every page's header; escaped downstream, but not unbounded. */
    private const MAX_TITLE_LENGTH = 200;

    // Checks the payload for the required fields:
    // static_site_id, callback_status_url, content_download_url, slug
    private function incomingPayloadComplete(array $payload): bool
    {
        return isset($payload['static_site_id'])
            && isset($payload['callback_status_url'])
            && isset($payload['content_download_url'])
            && isset($payload['slug']);
    }

    /**
     * The name of the first field that is not a safe directory name, or null.
     *
     * is_string() is part of the check, not a formality: a non-string would
     * otherwise reach BuildJob's `string` type, throw a TypeError, and be
     * caught by the blanket handler below as a 503 "could not enqueue build" --
     * making a malformed payload indistinguishable from a broker outage.
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

    #[Route('/build', name: 'build', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // First, before toArray(): that throws JsonException on a malformed
        // body, so checking the token here keeps an unauthenticated caller from
        // reaching it. A missing token and a wrong one give the same answer, so
        // the response cannot be used to tell which half was right.
        if (!$this->apiTokenCheck->authenticates($request)) {
            return new JsonResponse(
                ['status' => 'unauthorized', 'error' => 'missing or invalid api token'],
                Response::HTTP_UNAUTHORIZED,
                // RFC 7235 requires the challenge on a 401.
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

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

        // Optional: a caller that sends no title gets its slug used as one.
        $title = \is_string($payload['title'] ?? null) ? trim($payload['title']) : '';
        if ($title === '') {
            $title = $payload['slug'];
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return new JsonResponse(
                ['status' => 'invalid', 'error' => sprintf('title must be at most %d characters', self::MAX_TITLE_LENGTH)],
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
            title: $title,
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
