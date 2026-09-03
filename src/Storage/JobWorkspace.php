<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Creates the on-disk workspace for a build job: an input/output folder pair
 * under the job's static_site_id, on the volume shared with ssg-worker.
 *
 * ssg-worker downloads the job's content into input/ and writes the generated
 * site into output/, so both folders have to exist before the job is enqueued.
 */
final class JobWorkspace
{
    /**
     * static_site_id arrives straight from an HTTP payload and is used as a
     * directory name, so it is restricted to characters that cannot escape the
     * base directory. Dots are excluded outright rather than filtered out,
     * which keeps a bare ".." from passing as a valid name.
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    public function __construct(private readonly string $baseDir)
    {
    }

    public static function isValidStaticSiteId(string $staticSiteId): bool
    {
        return preg_match(self::SAFE_ID, $staticSiteId) === 1;
    }

    /**
     * Creates {baseDir}/{staticSiteId}/input and {baseDir}/{staticSiteId}/output.
     *
     * Idempotent: a second build for the same static_site_id reuses the folders
     * that are already there rather than failing.
     *
     * The two failures are separate types because they are separate problems:
     * a bad id is the caller's fault, an uncreatable directory is the
     * environment's (volume not mounted, wrong permissions, disk full).
     */

    public function createJobDirectories(string $staticSiteId): void
    {
        // Defence in depth: the controller already rejects a malformed id with a
        // 400, but this service has to be safe when called from anywhere else.
        if (!self::isValidStaticSiteId($staticSiteId)) {
            throw new \InvalidArgumentException('Unsafe static_site_id.');
        }

        $jobDir = rtrim($this->baseDir, '/') . '/' . $staticSiteId;

        // @throws \RuntimeException if a directory cannot be created
        $this->ensureDir($jobDir . '/input');
        $this->ensureDir($jobDir . '/output');
    }

    /**
     * The second is_dir() covers the race where a concurrent request for the
     * same job created the directory between our check and our mkdir().
     */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $reason = error_get_last()['message'] ?? 'unknown error';

            // Return some meaningful error message to the caller in case things go wrong
            // Could not create job directory /tmp/.../site-42/input: mkdir(): Not a directory
            // Could not create job directory /tmp/.../site-42/input: mkdir(): Permission denied

            throw new \RuntimeException("Could not create job directory {$dir}: {$reason}");
        }
    }
}
