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
     * Allow-list for static_site_id and slug, which ssg-worker uses as directory names without checking them again.
     * Dots are excluded, so `..` cannot pass.
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    /** Rendered into every page's header; escaped downstream, but not unbounded. */
    private const MAX_TITLE_LENGTH = 200;

    /** Schemes accepted for content_download_url and callback_status_url. */
    private const ALLOWED_URL_SCHEMES = ['http', 'https'];

    /**
     * Checks $payload and returns why it is invalid, or null when it is valid.
     * Every value must be a string: anything else would reach BuildJob's `string` type as a TypeError instead of a 400.
     *
     * @param array $payload The decoded request body.
     * @return ?string the error message for the 400, or null when $payload is valid
     */
    private function validatePayload(array $payload): ?string
    {
        foreach (['static_site_id', 'slug', 'title', 'content_download_url', 'callback_status_url'] as $field) {
            if (!isset($payload[$field])) {
                return 'missing required fields';
            }
        }

        foreach (['static_site_id', 'slug'] as $field) {
            if (!\is_string($payload[$field]) || preg_match(self::SAFE_ID, $payload[$field]) !== 1) {
                return sprintf('%s must match [A-Za-z0-9_-]{1,128}', $field);
            }
        }

        foreach (['content_download_url', 'callback_status_url'] as $field) {
            $url = $payload[$field];

            // parse_url() yields null for a missing part and false for a URL it cannot parse at all.
            $scheme = \is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
            $host = \is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            if (!\in_array($scheme, self::ALLOWED_URL_SCHEMES, true) || !\is_string($host) || $host === '') {
                return sprintf('%s must be an http or https URL with a host', $field);
            }
        }

        $title = \is_string($payload['title']) ? trim($payload['title']) : '';
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return sprintf('title must be a non-empty string of at most %d characters', self::MAX_TITLE_LENGTH);
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

        if (($error = $this->validatePayload($payload)) !== null) {
            return new JsonResponse(['status' => 'invalid', 'error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $build = new BuildJob(
            build_id: Uuid::v7()->toRfc4122(),
            static_site_id: $payload['static_site_id'],
            slug: $payload['slug'],
            title: trim($payload['title']),
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
