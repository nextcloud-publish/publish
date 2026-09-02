<?php

declare(strict_types=1);

namespace App\Messaging;

/**
 * Enqueues an assembled build job for a worker to pick up.
 *
 * The controller owns HTTP concerns (validation, status codes) and assembles the
 * build job; this interface is the seam to the transport. There is exactly one
 * implementation, so Symfony auto-aliases the interface to it and no service
 * configuration is needed.
 */
interface BuildQueue
{
    /**
     * @param array<string,mixed> $build
     *
     * @throws \Throwable if the build could not be enqueued
     */
    public function enqueue(array $build): void;
}
