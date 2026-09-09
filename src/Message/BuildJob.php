<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One build job, as it goes onto q.builds.
 *
 * Dispatched by BuildController and routed to the `builds` transport by
 * config/packages/messenger.yaml. There is no handler in this application: the
 * routing entry means the message is sent to the broker rather than handled
 * locally, and ssg-worker consumes it.
 *
 * The properties are deliberately snake_case. Messenger serializes this with
 * messenger.transport.symfony_serializer, whose ObjectNormalizer uses property
 * names verbatim as JSON keys -- so this class IS the wire contract, and the
 * names here are the names a consumer sees. Spelling them out keeps the keys
 * identical to the ones the /build endpoint accepts; the camelCase alternative
 * would need a name converter and hide the contract in configuration.
 */
final class BuildJob
{
    public function __construct(
        /** Opaque per-build id, for tracing the job across services. */
        public readonly string $build_id,
        public readonly string $static_site_id,
        public readonly string $slug,
        public readonly string $content_download_url,
        public readonly string $callback_status_url,
        /**
         * ATOM-formatted, and a string rather than a \DateTimeImmutable on
         * purpose: nothing on either side treats it as a date, so carrying it
         * pre-formatted keeps DateTimeNormalizer out of the wire format.
         */
        public readonly string $created_at,
    ) {
    }
}
