<?php

declare(strict_types=1);

namespace App\Message;

use App\Callback\StatusNotifier;
use App\Storage\BuildQuarantine;

/**
 * Handles a failed build reported on q.build-failures: moves the wreckage aside
 * so it can be inspected, then tells the client.
 *
 * Registered as a message handler in services.yaml instead of via the
 * #[AsMessageHandler] attribute, matching ssg-worker's BuildJobHandler.
 *
 * Quarantining is best effort and never blocks the callback -- BuildQuarantine
 * returns false rather than throwing when there is nothing to move. A build
 * that failed because its static_site_id was unsafe never got a directory in
 * the first place, and that is exactly the case where the client most needs to
 * hear what happened.
 */
final class BuildFailedHandler
{
    public function __construct(
        private readonly BuildQuarantine $quarantine,
        private readonly StatusNotifier $notifier,
    ) {
    }

    public function __invoke(BuildFailed $message): void
    {
        $this->quarantine->quarantine($message->static_site_id, $message->build_id);

        error_log(sprintf(
            '[INFO] build %s of %s failed: %s',
            $message->build_id,
            $message->static_site_id,
            $message->error,
        ));

        $this->notifier->notify(
            callbackStatusUrl: $message->callback_status_url,
            status: StatusNotifier::STATUS_FAILED,
            buildId: $message->build_id,
            staticSiteId: $message->static_site_id,
            finishedAt: $message->failed_at,
            error: $message->error,
        );
    }
}
