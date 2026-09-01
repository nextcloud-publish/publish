<?php

declare(strict_types=1);

namespace App\Message;

/**
 * The message dispatched onto the `q.builds` queue and consumed by ssg-worker.
 *
 * Dispatched by BuildController and routed to the `builds` transport by
 * config/packages/messenger.yaml.
 *
 * Property names are snake_case because Messenger's symfony_serializer uses
 * them verbatim as the JSON keys, which must match the keys the /build
 * endpoint accepts and the names ssg-worker reads.
 */
final class BuildJob
{
    public function __construct(
        /** Per-build id, for tracing a job across services. */
        public readonly string $build_id,
        /** Used for the job's folder under JOB_STORAGE_DIR. */
        public readonly string $static_site_id,
        /** Used for publishing the build page */
        public readonly string $slug,
        /** Download url for the build assets */
        public readonly string $content_download_url,
        /** Callback url to update the build status */
        public readonly string $callback_status_url,
        /** Timestamp when the job was created */
        public readonly string $created_at,
    ) {
    }
}
