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
     * Returns the build fields of $payload, title trimmed.
     * Every value must be a string: anything else would reach BuildJob's `string` type as a TypeError instead of a 400.
     *
     * @param array<mixed> $payload The decoded request body.
     * @return array{static_site_id: string, slug: string, title: string, content_download_url: string, callback_status_url: string} the checked fields
     * @throws \InvalidArgumentException with the error message for the 400 when $payload is invalid
     */
    private function validatePayload(array $payload): array
    {
        foreach (['static_site_id', 'slug', 'title', 'content_download_url', 'callback_status_url'] as $field) {
            if (!isset($payload[$field])) {
                throw new \InvalidArgumentException('missing required fields');
            }
        }

        return [
            'static_site_id' => $this->validateSafeId($payload['static_site_id'], 'static_site_id'),
            'slug' => $this->validateSafeId($payload['slug'], 'slug'),
            'content_download_url' => $this->validateUrl($payload['content_download_url'], 'content_download_url'),
            'callback_status_url' => $this->validateUrl($payload['callback_status_url'], 'callback_status_url'),
            'title' => $this->validateTitle($payload['title']),
        ];
    }

    /**
     * Returns $value when it is a string matching SAFE_ID.
     *
     * @param mixed $value The value to check.
     * @param string $field The field name for the error message.
     * @return string $value
     * @throws \InvalidArgumentException if $value is not a safe id
     */
    private function validateSafeId(mixed $value, string $field): string
    {
        if (!\is_string($value) || preg_match(self::SAFE_ID, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s must match [A-Za-z0-9_-]{1,128}', $field));
        }

        return $value;
    }

    /**
     * Returns $value when it is an http or https URL with a host.
     *
     * @param mixed $value The value to check.
     * @param string $field The field name for the error message.
     * @return string $value
     * @throws \InvalidArgumentException if $value is not such a URL
     */
    private function validateUrl(mixed $value, string $field): string
    {
        // parse_url() yields null for a missing part and false for a URL it cannot parse at all.
        $scheme = \is_string($value) ? strtolower((string) parse_url($value, PHP_URL_SCHEME)) : '';
        $host = \is_string($value) ? parse_url($value, PHP_URL_HOST) : null;

        if (!\is_string($value) || !\in_array($scheme, self::ALLOWED_URL_SCHEMES, true) || !\is_string($host) || $host === '') {
            throw new \InvalidArgumentException(sprintf('%s must be an http or https URL with a host', $field));
        }

        return $value;
    }

    /**
     * Returns $value trimmed when it is a string of 1 to MAX_TITLE_LENGTH characters after trimming.
     *
     * @param mixed $value The value to check.
     * @return string the trimmed title
     * @throws \InvalidArgumentException if $value is not such a string
     */
    private function validateTitle(mixed $value): string
    {
        $title = \is_string($value) ? trim($value) : '';
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('title must be a non-empty string of at most %d characters', self::MAX_TITLE_LENGTH));
        }

        return $title;
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

        try {
            $fields = $this->validatePayload($payload);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['status' => 'invalid', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $build = new BuildJob(
            build_id: Uuid::v7()->toRfc4122(),
            static_site_id: $fields['static_site_id'],
            slug: $fields['slug'],
            title: $fields['title'],
            content_download_url: $fields['content_download_url'],
            callback_status_url: $fields['callback_status_url'],
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
