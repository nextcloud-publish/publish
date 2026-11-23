<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StaticSite;
use App\Entity\StaticSiteStatus;
use App\Message\BuildJob;
use App\Message\BuildOperation;
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
 * Also answers whether a slug is free, so the client can offer one for a create.
 * The entry is committed before the job is dispatched, so publish-worker never looks for an entry that is not there yet.
 * A failed dispatch undoes the write. The security firewall authenticates every request before this controller is resolved.
 *
 * @param MessageBusInterface $messageBus The bus that publishes the BuildJob to the `builds` transport.
 * @param LoggerInterface $logger The app logger.
 * @param EntityManagerInterface $entityManager Stores the StaticSite.
 * @param int $pendingTimeoutSeconds How long a site may stay `pending` before a new operation may replace its current one.
 */
final class StaticSiteController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $pendingTimeoutSeconds,
    ) {
    }

    /**
     * Allow-list for slug, which publish-worker uses as a directory name without checking it again.
     * Dots are excluded, so `..` cannot pass. static_site_id, also a directory name, must be a UUIDv7 instead.
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

    /** The error of the 409 for an update whose build_id does not sort after the stored one. */
    private const BUILD_NOT_NEWER = 'build_id not newer';

    // --- routes -------------------------------------------------------------

    /**
     * Creates a static site and enqueues its first build, answering 202 once the broker confirms it.
     * Answers 401 for a missing or wrong token, 400 for an invalid payload, 409 when the id or the slug is
     * already stored, and 503 when the build could not be enqueued. Only a 202 leaves an entry behind.
     *
     * @param Request $request The POST /static-sites request.
     * @return Response an empty 202, or the error
     */
    #[Route('/static-sites', name: 'static_site_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        try {
            $fields = $this->validateCreate($this->payload($request));
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Build request rejected', ['reason' => $e->getMessage()]);
            return new JsonResponse(['data' => ['error_message' => $e->getMessage()]], Response::HTTP_BAD_REQUEST);
        }

        $logContext = self::logContext(BuildOperation::Create, $fields['static_site_id'], $fields['build_id'], $fields['slug']);

        $siteId = Uuid::fromString($fields['static_site_id']);
        if ($this->entityManager->find(StaticSite::class, $siteId) !== null) {
            $this->logger->warning('Build request rejected', $logContext + ['reason' => self::SITE_EXISTS]);
            return new JsonResponse(['data' => ['error_message' => self::SITE_EXISTS]], Response::HTTP_CONFLICT);
        }

        if ($this->entityManager->getRepository(StaticSite::class)->findOneBy(['slug' => $fields['slug']]) !== null) {
            $this->logger->warning('Build request rejected', $logContext + ['reason' => self::SLUG_TAKEN]);
            return new JsonResponse(['data' => ['error_message' => self::SLUG_TAKEN]], Response::HTTP_CONFLICT);
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

        $site = new StaticSite($siteId, $fields['slug'], StaticSiteStatus::Pending, Uuid::fromString($fields['build_id']));
        $this->entityManager->persist($site);

        return $this->enqueue($build, $site, $logContext);
    }

    /**
     * Rebuilds a stored site from new content under its slug, answering 202 once the broker confirms it.
     * Answers 404 for an unknown id, 409 while the site's last operation is pending or when the build_id is not newer
     * than the stored one, and otherwise as create().
     *
     * @param Request $request The PATCH /static-sites/{id} request.
     * @param string $id The site's static_site_id.
     * @return Response an empty 202, or the error
     */
    #[Route('/static-sites/{id}', name: 'static_site_update', methods: ['PATCH'])]
    public function update(Request $request, string $id): Response
    {
        try {
            $fields = $this->validateUpdate($this->payload($request));
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Build request rejected', ['reason' => $e->getMessage()]);
            return new JsonResponse(['data' => ['error_message' => $e->getMessage()]], Response::HTTP_BAD_REQUEST);
        }

        $logContext = self::logContext(BuildOperation::Update, $id, $fields['build_id']);

        // The route takes any id, so a malformed one gets the same 404 as an unknown one.
        if (preg_match('{^' . Requirement::UUID_V7 . '$}', $id) !== 1) {
            $this->logger->warning('Build request rejected', $logContext + ['reason' => 'static site not found']);
            return new JsonResponse(['data' => ['error_message' => 'static site not found']], Response::HTTP_NOT_FOUND);
        }

        $site = $this->entityManager->find(StaticSite::class, Uuid::fromString($id));
        if ($site === null) {
            $this->logger->warning('Build request rejected', $logContext + ['reason' => 'static site not found']);
            return new JsonResponse(['data' => ['error_message' => 'static site not found']], Response::HTTP_NOT_FOUND);
        }

        $logContext['slug'] = $site->slug;

        // UUIDv7 sorts by creation time, so a build_id that is not greater is a stale or repeated request.
        if ($site->buildId !== null && Uuid::fromString($fields['build_id'])->compare($site->buildId) <= 0) {
            $this->logger->warning('Build request rejected', $logContext + ['reason' => self::BUILD_NOT_NEWER]);
            return new JsonResponse(['data' => ['error_message' => self::BUILD_NOT_NEWER]], Response::HTTP_CONFLICT);
        }

        if ($site->isBusy(new \DateTimeImmutable(), $this->pendingTimeoutSeconds)) {
            $this->logger->warning('Build request rejected', $logContext + ['reason' => self::OPERATION_IN_PROGRESS]);
            return new JsonResponse(['data' => ['error_message' => self::OPERATION_IN_PROGRESS]], Response::HTTP_CONFLICT);
        }

        $build = new BuildJob(
            build_id: $fields['build_id'],
            static_site_id: $id,
            operation: BuildOperation::Update,
            slug: $site->slug,
            callback_status_url: $fields['callback_status_url'],
            created_at: self::now(),
            title: $fields['title'],
            content_download_url: $fields['content_download_url'],
            render_options: $fields['render_options'],
        );

        $site->startOperation(Uuid::fromString($fields['build_id']));

        return $this->enqueue($build, $site, $logContext);
    }

    /**
     * Removes a stored site through publish-worker, which deletes the entry once the files are gone, answering 202
     * once the broker confirms it. Answers 404 for an unknown id, 409 while the site's last operation is pending,
     * and otherwise as create().
     *
     * @param Request $request The DELETE /static-sites/{id} request.
     * @param string $id The site's static_site_id.
     * @return Response an empty 202, or the error
     */
    #[Route('/static-sites/{id}', name: 'static_site_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id): Response
    {
        try {
            $fields = $this->validateDelete($this->payload($request));
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Build request rejected', ['reason' => $e->getMessage()]);
            return new JsonResponse(['data' => ['error_message' => $e->getMessage()]], Response::HTTP_BAD_REQUEST);
        }

        $logContext = self::logContext(BuildOperation::Delete, $id);

        // The route takes any id, so a malformed one gets the same 404 as an unknown one.
        if (preg_match('{^' . Requirement::UUID_V7 . '$}', $id) !== 1) {
            $this->logger->warning('Build request rejected', $logContext + ['reason' => 'static site not found']);
            return new JsonResponse(['data' => ['error_message' => 'static site not found']], Response::HTTP_NOT_FOUND);
        }

        $site = $this->entityManager->find(StaticSite::class, Uuid::fromString($id));
        if ($site === null) {
            $this->logger->warning('Build request rejected', $logContext + ['reason' => 'static site not found']);
            return new JsonResponse(['data' => ['error_message' => 'static site not found']], Response::HTTP_NOT_FOUND);
        }

        $logContext['slug'] = $site->slug;

        if ($site->isBusy(new \DateTimeImmutable(), $this->pendingTimeoutSeconds)) {
            $this->logger->warning('Build request rejected', $logContext + ['reason' => self::OPERATION_IN_PROGRESS]);
            return new JsonResponse(['data' => ['error_message' => self::OPERATION_IN_PROGRESS]], Response::HTTP_CONFLICT);
        }

        $build = new BuildJob(
            build_id: null,
            static_site_id: $id,
            operation: BuildOperation::Delete,
            slug: $site->slug,
            callback_status_url: $fields['callback_status_url'],
            created_at: self::now(),
        );

        $site->startOperation();

        return $this->enqueue($build, $site, $logContext);
    }

    /**
     * Answers whether no stored site holds a slug, whatever that site's status.
     * The answer can be outdated by the time of a create, which then answers 409.
     * Answers 400 for an invalid slug and 503 when the database cannot be reached.
     *
     * @param Request $request The POST /static-sites/slug-available request.
     * @return JsonResponse `{"data": {"available": bool}}`, or the error
     */
    #[Route('/static-sites/slug-available', name: 'static_site_slug_available', methods: ['POST'])]
    public function slugAvailable(Request $request): JsonResponse
    {
        try {
            $payload = $this->payload($request);
            $this->requireFields($payload, ['slug']);
            $slug = $this->validateSafeId($payload['slug'], 'slug');
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Slug check rejected', ['reason' => $e->getMessage()]);
            return new JsonResponse(['data' => ['error_message' => $e->getMessage()]], Response::HTTP_BAD_REQUEST);
        }

        try {
            $site = $this->entityManager->getRepository(StaticSite::class)->findOneBy(['slug' => $slug]);
        } catch (\Throwable $e) {
            $this->logger->error('Could not check slug', ['slug' => $slug, 'exception' => $e]);
            return new JsonResponse(['data' => ['error_message' => 'could not check slug']], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['data' => ['available' => $site === null]]);
    }

    // --- enqueueing --------------------------------------------------------

    /**
     * Commits the pending write, then dispatches $build, answering 202 once the broker confirms it.
     * Answers 503 when the commit or the dispatch fails.
     * The callers check the id and the slug beforehand, so a unique constraint only fails for a parallel request, also with 503.
     * After a failed dispatch the write is reverted, so a 503 leaves the entry as it was before the request:
     * a create removes $site, an update or delete undoes its startOperation().
     *
     * @param BuildJob $build The job to dispatch.
     * @param StaticSite $site The managed site $build was written to.
     * @param array<string, string> $logContext The ids of the request.
     * @return Response an empty 202, or the error
     */
    private function enqueue(BuildJob $build, StaticSite $site, array $logContext): Response
    {
        try {
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Could not enqueue build', $logContext + ['exception' => $e]);
            return new JsonResponse(['data' => ['error_message' => 'could not enqueue build']], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            // Blocks until the broker confirms the publish (confirm_timeout in config/packages/messenger.yaml).
            $this->messageBus->dispatch($build);
        } catch (\Throwable $e) {
            // Broader than Messenger's TransportException on purpose: an unset AMQP_DSN or a
            // serialization failure must not produce a 202 either.
            try {
                match ($build->operation) {
                    BuildOperation::Create => $this->entityManager->remove($site),
                    BuildOperation::Update, BuildOperation::Delete => $site->undoOperation(),
                };
                $this->entityManager->flush();
            } catch (\Throwable $undoError) {
                $this->logger->error('Could not undo the static_site entry', $logContext + ['exception' => $undoError]);
            }

            $this->logger->error('Could not enqueue build', $logContext + ['exception' => $e]);
            return new JsonResponse(['data' => ['error_message' => 'could not enqueue build']], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $this->logger->info('Build enqueued', $logContext);
        return new Response(null, Response::HTTP_ACCEPTED);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * Returns the ids of a request for the log, leaving out those that are null; the URLs would repeat them.
     *
     * @param BuildOperation $operation The operation of the request.
     * @param string $staticSiteId The site's static_site_id.
     * @param ?string $buildId The request's build_id.
     * @param ?string $slug The site's slug.
     * @return array<string, string> build_id, static_site_id, operation and slug
     */
    private static function logContext(BuildOperation $operation, string $staticSiteId, ?string $buildId = null, ?string $slug = null): array
    {
        return array_filter([
            'build_id' => $buildId,
            'static_site_id' => $staticSiteId,
            'operation' => $operation->value,
            'slug' => $slug,
        ], static fn (?string $value): bool => $value !== null);
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
     * @throws \InvalidArgumentException if the body is not valid JSON or has no `data` object
     */
    private function payload(Request $request): array
    {
        // The firewall answers an unauthenticated request with a 401 on kernel.request, so
        // toArray() is never reached without a valid token.
        try {
            $payload = $request->toArray()['data'] ?? null;
        } catch (JsonException) {
            throw new \InvalidArgumentException('body is not valid JSON');
        }

        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('data must be an object');
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
            'static_site_id' => $this->validateUuidV7($payload['static_site_id'], 'static_site_id'),
            'build_id' => $this->validateUuidV7($payload['build_id'], 'build_id'),
            'slug' => $this->validateSafeId($payload['slug'], 'slug'),
            'content_download_url' => $this->validateUrl($payload['content_download_url'], 'content_download_url'),
            'callback_status_url' => $this->validateUrl($payload['callback_status_url'], 'callback_status_url'),
            'title' => $this->validateTitle($payload['title']),
            'render_options' => $payload['render_options'] ?? null,
        ];
    }

    /**
     * Returns the fields of an update.
     *
     * @param array<mixed> $payload The decoded fields.
     * @return array{build_id: string, title: string, content_download_url: string, callback_status_url: string, render_options: mixed} the checked fields
     * @throws \InvalidArgumentException with the error message for the 400 when $payload is invalid
     */
    private function validateUpdate(array $payload): array
    {
        $this->requireFields($payload, ['build_id', 'title', 'content_download_url', 'callback_status_url']);

        return [
            'build_id' => $this->validateUuidV7($payload['build_id'], 'build_id'),
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
     * @return array{callback_status_url: string} the checked fields
     * @throws \InvalidArgumentException with the error message for the 400 when $payload is invalid
     */
    private function validateDelete(array $payload): array
    {
        $this->requireFields($payload, ['callback_status_url']);

        return [
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
     * Returns $value in lower-case canonical form when it is a version 7 UUID string.
     * The client generates both ids as UUIDv7, which sort by creation time.
     * static_site_id is the primary key and both are directory names in publish-worker, so one id must not arrive in two spellings.
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
