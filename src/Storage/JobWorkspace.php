<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Resolves a build's output folder on the volume shared with ssg-worker.
 * Read-only counterpart to ssg-worker's own App\Job\JobWorkspace, which
 * creates <static_site_id>/input + /output under the same JOB_STORAGE_DIR --
 * this side only ever reads from /output, to serve a published site.
 */
final class JobWorkspace
{
    /** Matches ssg-worker's own allow-list; changing it means updating both repos together. */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    public function __construct(private readonly string $baseDir)
    {
    }

    public static function isValidStaticSiteId(string $staticSiteId): bool
    {
        return preg_match(self::SAFE_ID, $staticSiteId) === 1;
    }

    /**
     * @throws \InvalidArgumentException if the static_site_id is unsafe
     */
    public function outputDir(string $staticSiteId): string
    {
        if (!self::isValidStaticSiteId($staticSiteId)) {
            throw new \InvalidArgumentException('Unsafe static_site_id.');
        }

        return rtrim($this->baseDir, '/') . '/' . $staticSiteId . '/output';
    }
}
