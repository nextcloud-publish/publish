<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StaticSite;
use App\Entity\StaticSiteStatus;
use App\Message\BuildJob;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Validates a request for a new static site, stores it as a StaticSite and enqueues its first build as a BuildJob for publish-worker.
 * The security firewall authenticates the request before this controller is resolved.
 *
 * @param MessageBusInterface $bus The bus that publishes the BuildJob to the `builds` transport.
 * @param LoggerInterface $logger The app logger.
 * @param EntityManagerInterface $entityManager Stores the StaticSite.
 */
final class StaticSiteController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Allow-list for slug, which publish-worker uses as a directory name without checking it again.
     * Dots are excluded, so `..` cannot pass. static_site_id, also a directory name, must be a UUID instead.
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    /** Rendered into every page's header; escaped downstream, but not unbounded. */
    private const MAX_TITLE_LENGTH = 200;

    /** Schemes accepted for content_download_url and callback_status_url. */
    private const ALLOWED_URL_SCHEMES = ['http', 'https'];

    /** The error of the 409 for an id that is already stored. */
    private const SITE_EXISTS = 'static site already exists';

    /** The error of the 409 for a slug another site holds. */
    private const SLUG_TAKEN = 'slug already taken';

    /**
     * Returns the build fields of $payload, title trimmed and both ids in lower-case canonical form.
     * Every value must be a string: anything else would reach BuildJob's `string` type as a TypeError instead of a 400.
     *
     * @param array<mixed> $payload The decoded request body.
     * @return array{static_site_id: string, build_id: string, slug: string, title: string, content_download_url: string, callback_status_url: string} the checked fields
     * @throws \InvalidArgumentException with the error message for the 400 when $payload is invalid
     */
    private function validatePayload(array $payload): array
    {
        foreach (['static_site_id', 'build_id', 'slug', 'title', 'content_download_url', 'callback_status_url'] as $field) {
            if (!isset($payload[$field])) {
                throw new \InvalidArgumentException('missing required fields');
            }
        }

        return [
            'static_site_id' => $this->validateUuid($payload['static_site_id'], 'static_site_id'),
            'build_id' => $this->validateUuidV7($payload['build_id'], 'build_id'),
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
     * Returns $value in lower-case canonical form when it is a UUID string.
     * It is the primary key and a directory name in publish-worker, so one site must not arrive in two spellings.
     *
     * @param mixed $value The value to check.
     * @param string $field The field name for the error message.
     * @return string the UUID, lower case
     * @throws \InvalidArgumentException if $value is not a UUID
     */
    private function validateUuid(mixed $value, string $field): string
    {
        if (!\is_string($value) || !Uuid::isValid($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a UUID', $field));
        }

        return Uuid::fromString($value)->toRfc4122();
    }

    /**
     * Returns $value in lower-case canonical form when it is a version 7 UUID string.
     * The client generates build ids as UUIDv7, which sort by creation time; publish-worker names directories after them.
     *
     * @param mixed $value The value to check.
     * @param string $field The field name for the error message.
     * @return string the UUID, lower case
     * @throws \InvalidArgumentException if $value is not a version 7 UUID
     */
    private function validateUuidV7(mixed $value, string $field): string
    {
        if (!\is_string($value) || !Uuid::isValid($value) || !Uuid::fromString($value) instanceof UuidV7) {
            throw new \InvalidArgumentException(sprintf('%s must be a UUIDv7', $field));
        }

        return Uuid::fromString($value)->toRfc4122();
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
                sprintf('title must be a non-empty string of at most %d characters', self::MAX_TITLE_LENGTH)
            );
        }

        return $title;
    }

    /**
     * Answers 409 with $error, logging it as a rejected request.
     *
     * @param string $error SITE_EXISTS or SLUG_TAKEN.
     * @param array<string, string> $logContext The ids of the request.
     * @return JsonResponse the 409
     */
    private function conflict(string $error, array $logContext): JsonResponse
    {
        $this->logger->warning('Build request rejected', $logContext + ['reason' => $error]);

        return new JsonResponse(['status' => 'conflict', 'error' => $error], Response::HTTP_CONFLICT);
    }

    /**
     * Creates a static site and enqueues its first build, answering 202 once the broker confirms it.
     * Answers 401 for a missing or wrong token, 400 for an invalid payload, 409 when the id or the slug is
     * already stored, and 503 when the build could not be enqueued. Only a 202 leaves an entry behind.
     *
     * @param Request $request The POST /static-sites request.
     * @return JsonResponse the status of the request
     * @throws JsonException if the body is not valid JSON; Symfony answers it with a 400
     */
    #[Route('/static-sites', name: 'static_site_create', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // The firewall answers an unauthenticated request with a 401 on kernel.request, so
        // toArray() and its JsonException are never reached without a valid token.
        // The fields travel under "data"; anything outside it is ignored.
        $payload = $request->toArray()['data'] ?? null;

        try {
            if (!\is_array($payload)) {
                throw new \InvalidArgumentException('missing required fields');
            }
            $fields = $this->validatePayload($payload);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Build request rejected', ['reason' => $e->getMessage()]);
            return new JsonResponse(['status' => 'invalid', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $build = new BuildJob(
            build_id: $fields['build_id'],
            static_site_id: $fields['static_site_id'],
            slug: $fields['slug'],
            title: $fields['title'],
            content_download_url: $fields['content_download_url'],
            callback_status_url: $fields['callback_status_url'],
            created_at: (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            // Unchecked: ssg-library validates it, and an invalid value ends the build as failed.
            render_options: $payload['render_options'] ?? null,
        );

        // The ids identify the build; the URLs would repeat them.
        $logContext = [
            'build_id' => $build->build_id,
            'static_site_id' => $build->static_site_id,
            'slug' => $build->slug,
        ];

        // POST only creates; an existing site is changed through its own resource.
        $siteId = Uuid::fromString($build->static_site_id);
        if ($this->entityManager->find(StaticSite::class, $siteId) !== null) {
            return $this->conflict(self::SITE_EXISTS, $logContext);
        }

        try {
            // The entry and the job stand or fall together: a failed dispatch rolls the insert back,
            // so a 503 leaves no entry behind and the client's retry is not answered with a 409.
            $this->entityManager->wrapInTransaction(function () use ($siteId, $build): void {
                $this->entityManager->persist(new StaticSite(
                    $siteId,
                    $build->slug,
                    StaticSiteStatus::Pending,
                    Uuid::fromString($build->build_id),
                ));
                $this->entityManager->flush();

                // Blocks until the broker confirms the publish (confirm_timeout in config/packages/messenger.yaml).
                $this->bus->dispatch($build);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The slug belongs to another site, or a parallel request created the same id since find().
            $slugTaken = str_contains($e->getMessage(), StaticSite::SLUG_CONSTRAINT);
            return $this->conflict($slugTaken ? self::SLUG_TAKEN : self::SITE_EXISTS, $logContext);
        } catch (\Throwable $e) {
            // Broader than Messenger's TransportException on purpose: an unset AMQP_DSN or a
            // serialization failure must not produce a 202 either.
            $this->logger->error('Could not enqueue build', $logContext + ['exception' => $e]);
            return new JsonResponse(
                ['status' => 'error', 'error' => 'could not enqueue build'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $this->logger->info('Build enqueued', $logContext);
        return new JsonResponse(['status' => 'enqueued'], Response::HTTP_ACCEPTED);
    }
}
