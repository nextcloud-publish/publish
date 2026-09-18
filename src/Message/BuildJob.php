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
        /**
         * ssg-worker uses this to name the published site directory under
         * PUBLISHED_DIR, so it's restricted to [A-Za-z0-9_-]{1,128} -- the
         * same allow-list as the two ids. BuildController rejects anything
         * else with a 400, so the caller gets a synchronous answer instead of
         * a failure callback a minute later.
         *
         * Nothing enforces slug uniqueness: this service has no store to do
         * it with, so two sites claiming one slug take the published tree
         * from each other. That check belongs here and arrives with the
         * database.
         */
        public readonly string $slug,
        /** Download url for the build assets */
        public readonly string $content_download_url,
        /** Callback url to update the build status */
        public readonly string $callback_status_url,
        /** Timestamp when the job was created */
        public readonly string $created_at,
        /**
         * Human-readable site title, rendered as the header link on every
         * page of the built site. Separate from slug because the slug is a
         * directory name and its allow-list excludes spaces.
         *
         * No getters on this class: this side normalizes it, and
         * ObjectNormalizer would turn a getter into an extra JSON key.
         */
        public readonly string $title,
    ) {
    }
}
