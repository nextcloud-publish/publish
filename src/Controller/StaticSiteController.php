<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StaticSite;
use App\Entity\StaticSiteStatus;
use App\Message\BuildJob;
use App\Message\BuildOperation;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Creates, updates and deletes static sites: validates the request, writes the StaticSite and enqueues a BuildJob for publish-worker.
 * The entry is committed before the job is dispatched, so publish-worker never looks for an entry that is not there yet.
 * A failed dispatch undoes the write. The security firewall authenticates every request before this controller is resolved.
 *
 * @param MessageBusInterface $bus The bus that publishes the BuildJob to the `builds` transport.
 * @param LoggerInterface $logger The app logger.
 * @param EntityManagerInterface $entityManager Stores the StaticSite.
 * @param int $pendingTimeoutSeconds How long a site may stay `pending` before a new operation may replace its current one.
 */
final class StaticSiteController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $pendingTimeoutSeconds,
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

    /** The error of the 409 for a site whose last operation is still pending. */
    private const OPERATION_IN_PROGRESS = 'operation in progress';

    // --- routes -------------------------------------------------------------

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
    public function create(Request $request): JsonResponse
    {
        try {
            $fields = $this->validateCreate($this->payload($request));
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($e);
        }

        $build = new BuildJob(
            build_id: $fields['build_id'],
            static_site_id: $fields['static_site_id'],
            operation: BuildOperation::Create,
            slug: $fields['slug'],
            callback_status_url: $fields['callback_status_url'],
            created_at: self::now(),
            title: $fields['title'],
            content_download_url: $fields['content_download_url'],
            // Unchecked: ssg-library validates it, and an invalid value ends the build as failed.
            render_options: $fields['render_options'],
        );
        $logContext = self::logContext($build);

        $siteId = Uuid::fromString($build->static_site_id);
        if ($this->entityManager->find(StaticSite::class, $siteId) !== null) {
            return $this->conflict(self::SITE_EXISTS, $logContext);
        }

        $site = new StaticSite($siteId, $build->slug, StaticSiteStatus::Pending, Uuid::fromString($build->build_id));
        $this->entityManager->persist($site);

        return $this->enqueue($build, $logContext, fn () => $this->entityManager->remove($site));
    }

    /**
     * Rebuilds a stored site from new content, optionally under a new slug, answering 202 once the broker confirms it.
     * Answers 404 for an unknown id, 409 while the site's last operation is pending or when another site holds the slug,
     * and otherwise as create().
     *
     * @param Request $request The PATCH /static-sites/{id} request.
     * @param string $id The site's static_site_id.
     * @return JsonResponse the status of the request
     * @throws JsonException if the body is not valid JSON; Symfony answers it with a 400
     */
    #[Route('/static-sites/{id}', name: 'static_site_update', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $fields = $this->validateUpdate($this->payload($request));
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($e);
        }

        $site = $this->entityManager->find(StaticSite::class, Uuid::fromString($id));
        if ($site === null) {
            return $this->notFound($id);
        }

        $slug = $fields['slug'] ?? $site->slug;
        $build = new BuildJob(
            build_id: $fields['build_id'],
            static_site_id: $site->id->toRfc4122(),
            operation: BuildOperation::Update,
            slug: $slug,
            callback_status_url: $fields['callback_status_url'],
            created_at: self::now(),
            title: $fields['title'],
            content_download_url: $fields['content_download_url'],
            previous_slug: $slug !== $site->slug ? $site->slug : null,
            render_options: $fields['render_options'],
        );

        return $this->startOperation($site, $build);
    }

    /**
     * Removes a stored site through publish-worker, which deletes the entry once the files are gone, answering 202
     * once the broker confirms it. Answers 404 for an unknown id, 409 while the site's last operation is pending,
     * and otherwise as create().
     *
     * @param Request $request The DELETE /static-sites/{id} request.
     * @param string $id The site's static_site_id.
     * @return JsonResponse the status of the request
     * @throws JsonException if the body is not valid JSON; Symfony answers it with a 400
     */
    #[Route('/static-sites/{id}', name: 'static_site_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    public function delete(Request $request, string $id): JsonResponse
    {
        try {
            $fields = $this->validateDelete($this->payload($request));
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($e);
        }

        $site = $this->entityManager->find(StaticSite::class, Uuid::fromString($id));
        if ($site === null) {
            return $this->notFound($id);
        }

        $build = new BuildJob(
            build_id: $fields['build_id'],
            static_site_id: $site->id->toRfc4122(),
            operation: BuildOperation::Delete,
            slug: $site->slug,
            callback_status_url: $fields['callback_status_url'],
            created_at: self::now(),
        );

        return $this->startOperation($site, $build);
    }

    // --- enqueueing --------------------------------------------------------

    /**
     * Marks $site `pending` for $build and enqueues it, unless the site's last operation is still pending.
     * A failed dispatch puts the site back as it was.
     *
     * @param StaticSite $site The stored site.
     * @param BuildJob $build The update or delete to enqueue.
     * @return JsonResponse the status of the request
     */
    private function startOperation(StaticSite $site, BuildJob $build): JsonResponse
    {
        $logContext = self::logContext($build);

        if ($site->isBusy(new \DateTimeImmutable(), $this->pendingTimeoutSeconds)) {
            return $this->conflict(self::OPERATION_IN_PROGRESS, $logContext);
        }

        $slug = $site->slug;
        $status = $site->status;
        $buildId = $site->buildId;
        $site->startOperation(Uuid::fromString($build->build_id), $build->slug);

        return $this->enqueue($build, $logContext, fn () => $site->restore($slug, $status, $buildId));
    }

    /**
     * Commits the pending write, then dispatches $build, answering 202 once the broker confirms it.
     * Answers 409 when the commit violates the id or the slug constraint, and 503 when the commit or the dispatch fails.
     * After a failed dispatch $undo reverts the write, so a 503 leaves the entry as it was before the request.
     *
     * @param BuildJob $build The job to dispatch.
     * @param array<string, string> $logContext The ids of the request.
     * @param \Closure(): void $undo Reverts the pending write on the managed entity.
     * @return JsonResponse the status of the request
     */
    private function enqueue(BuildJob $build, array $logContext, \Closure $undo): JsonResponse
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // The slug belongs to another site, or a parallel request created the same id since find().
            $slugTaken = str_contains($e->getMessage(), StaticSite::SLUG_CONSTRAINT);
            return $this->conflict($slugTaken ? self::SLUG_TAKEN : self::SITE_EXISTS, $logContext);
        } catch (\Throwable $e) {
            return $this->notEnqueued($e, $logContext);
        }

        try {
            // Blocks until the broker confirms the publish (confirm_timeout in config/packages/messenger.yaml).
            $this->bus->dispatch($build);
        } catch (\Throwable $e) {
            // Broader than Messenger's TransportException on purpose: an unset AMQP_DSN or a
            // serialization failure must not produce a 202 either.
            try {
                $undo();
                $this->entityManager->flush();
            } catch (\Throwable $undoError) {
                $this->logger->error('Could not undo the static_site entry', $logContext + ['exception' => $undoError]);
            }

            return $this->notEnqueued($e, $logContext);
        }

        $this->logger->info('Build enqueued', $logContext);
        return new JsonResponse(['status' => 'enqueued'], Response::HTTP_ACCEPTED);
    }

    // --- answers -----------------------------------------------------------

    /**
     * Answers 400 with the message of $e, logging it as a rejected request.
     *
     * @param \InvalidArgumentException $e The validation error.
     * @return JsonResponse the 400
     */
    private function invalid(\InvalidArgumentException $e): JsonResponse
    {
        $this->logger->warning('Build request rejected', ['reason' => $e->getMessage()]);

        return new JsonResponse(['status' => 'invalid', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Answers 404 for an unknown site, logging it as a rejected request.
     *
     * @param string $id The id from the path.
     * @return JsonResponse the 404
     */
    private function notFound(string $id): JsonResponse
    {
        $this->logger->warning('Build request rejected', ['static_site_id' => $id, 'reason' => 'static site not found']);

        return new JsonResponse(['status' => 'not_found', 'error' => 'static site not found'], Response::HTTP_NOT_FOUND);
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
     * Answers 503 for a job that was not enqueued, logging $e.
     *
     * @param \Throwable $e Why the commit or the dispatch failed.
     * @param array<string, string> $logContext The ids of the request.
     * @return JsonResponse the 503
     */
    private function notEnqueued(\Throwable $e, array $logContext): JsonResponse
    {
        $this->logger->error('Could not enqueue build', $logContext + ['exception' => $e]);

        return new JsonResponse(['status' => 'error', 'error' => 'could not enqueue build'], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * Returns the ids of $build for the log; the URLs would repeat them.
     *
     * @param BuildJob $build The job of the request.
     * @return array<string, string> build_id, static_site_id, operation and slug
     */
    private static function logContext(BuildJob $build): array
    {
        return [
            'build_id' => $build->build_id,
            'static_site_id' => $build->static_site_id,
            'operation' => $build->operation->value,
            'slug' => $build->slug,
        ];
    }

    /**
     * Returns the current time for BuildJob's created_at.
     *
     * @return string the time in ATOM format
     */
    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
    }

    // --- validation --------------------------------------------------------

    /**
     * Returns the fields under `data` in the body of $request; anything outside it is ignored.
     *
     * @param Request $request The request.
     * @return array<mixed> the decoded fields
     * @throws JsonException if the body is not valid JSON
     * @throws \InvalidArgumentException if the body has no `data` object
     */
    private function payload(Request $request): array
    {
        // The firewall answers an unauthenticated request with a 401 on kernel.request, so
        // toArray() and its JsonException are never reached without a valid token.
        $payload = $request->toArray()['data'] ?? null;

        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('missing required fields');
        }

        return $payload;
    }

    /**
     * Checks that every field in $fields is set in $payload.
     *
     * @param array<mixed> $payload The decoded fields.
     * @param list<string> $fields The required field names.
     * @return void
     * @throws \InvalidArgumentException if one is missing
     */
    private function requireFields(array $payload, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($payload[$field])) {
                throw new \InvalidArgumentException('missing required fields');
            }
        }
    }

    /**
     * Returns the fields of a create, title trimmed and both ids in lower-case canonical form.
     * Every value must be a string: anything else would reach BuildJob's `string` type as a TypeError instead of a 400.
     *
     * @param array<mixed> $payload The decoded fields.
     * @return array{static_site_id: string, build_id: string, slug: string, title: string, content_download_url: string, callback_status_url: string, render_options: mixed} the checked fields
     * @throws \InvalidArgumentException with the error message for the 400 when $payload is invalid
     */
    private function validateCreate(array $payload): array
    {
        $this->requireFields($payload, ['static_site_id', 'build_id', 'slug', 'title', 'content_download_url', 'callback_status_url']);

        return [
            'static_site_id' => $this->validateUuid($payload['static_site_id'], 'static_site_id'),
            'build_id' => $this->validateUuidV7($payload['build_id'], 'build_id'),
            'slug' => $this->validateSafeId($payload['slug'], 'slug'),
            'content_download_url' => $this->validateUrl($payload['content_download_url'], 'content_download_url'),
            'callback_status_url' => $this->validateUrl($payload['callback_status_url'], 'callback_status_url'),
            'title' => $this->validateTitle($payload['title']),
            'render_options' => $payload['render_options'] ?? null,
        ];
    }

    /**
     * Returns the fields of an update; slug is null when the request keeps the stored one.
     *
     * @param array<mixed> $payload The decoded fields.
     * @return array{build_id: string, slug: ?string, title: string, content_download_url: string, callback_status_url: string, render_options: mixed} the checked fields
     * @throws \InvalidArgumentException with the error message for the 400 when $payload is invalid
     */
    private function validateUpdate(array $payload): array
    {
        $this->requireFields($payload, ['build_id', 'title', 'content_download_url', 'callback_status_url']);

        return [
            'build_id' => $this->validateUuidV7($payload['build_id'], 'build_id'),
            'slug' => isset($payload['slug']) ? $this->validateSafeId($payload['slug'], 'slug') : null,
            'content_download_url' => $this->validateUrl($payload['content_download_url'], 'content_download_url'),
            'callback_status_url' => $this->validateUrl($payload['callback_status_url'], 'callback_status_url'),
            'title' => $this->validateTitle($payload['title']),
            'render_options' => $payload['render_options'] ?? null,
        ];
    }

    /**
     * Returns the fields of a delete.
     *
     * @param array<mixed> $payload The decoded fields.
     * @return array{build_id: string, callback_status_url: string} the checked fields
     * @throws \InvalidArgumentException with the error message for the 400 when $payload is invalid
     */
    private function validateDelete(array $payload): array
    {
        $this->requireFields($payload, ['build_id', 'callback_status_url']);

        return [
            'build_id' => $this->validateUuidV7($payload['build_id'], 'build_id'),
            'callback_status_url' => $this->validateUrl($payload['callback_status_url'], 'callback_status_url'),
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
}
