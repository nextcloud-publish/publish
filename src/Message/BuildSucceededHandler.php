<?php

declare(strict_types=1);

namespace App\Message;

use App\Callback\StatusNotifier;
use App\Storage\BuildPromoter;

/**
 * Handles a successful build reported on q.build-results: publishes the
 * rendered site, then tells the client.
 *
 * Registered as a message handler in services.yaml instead of via the
 * #[AsMessageHandler] attribute, matching ssg-worker's BuildJobHandler.
 *
 * ORDER MATTERS. The filesystem work happens first and the callback last,
 * because the callback is the step that fails for reasons outside this system.
 * If it throws, Messenger retries the whole handler -- so BuildPromoter is
 * written to find its work already done and skip it, which makes the replay
 * three stat() calls rather than a second promotion.
 *
 * That ordering is also why there is no separate callback queue: the prototype
 * notes required that "the webhook does not gate the ack", and in Messenger the
 * only way to gate an ack is to throw. Keeping both side effects in one handler
 * and making the first idempotent buys the same property with one queue fewer.
 */
final class BuildSucceededHandler
{
    public function __construct(
        private readonly BuildPromoter $promoter,
        private readonly StatusNotifier $notifier,
    ) {
    }

    public function __invoke(BuildSucceeded $message): void
    {
        $this->promoter->promote($message->static_site_id, $message->build_id);

        error_log(sprintf(
            '[INFO] published %s from build %s (%d page(s))',
            $message->static_site_id,
            $message->build_id,
            $message->pages,
        ));

        $this->notifier->notify(
            callbackStatusUrl: $message->callback_status_url,
            status: StatusNotifier::STATUS_SUCCESS,
            buildId: $message->build_id,
            staticSiteId: $message->static_site_id,
            finishedAt: $message->finished_at,
        );
    }
}
