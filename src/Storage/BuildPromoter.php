<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Moves a finished build out of the temp tree and makes it the live site.
 *
 * Two properties this has to have, because the status callback runs in the same
 * handler and a failed callback replays the whole thing:
 *
 *  - ATOMIC. The live site is swapped with rename(), never assembled in place.
 *    A reader sees the old site or the new one, never half of either.
 *  - IDEMPOTENT. A replay finds the work already done and skips it, so the
 *    retry costs three stat() calls before it reaches the callback again.
 *
 * Directory modes matter here and are easy to get wrong: ssg-worker creates
 * output/ through its Helper::ensureDir(), which hardcodes 0750, and the
 * container runs as root. Renaming that in as-is gives a root-owned 0750
 * directory that whatever serves the site cannot traverse -- a guaranteed 403 on
 * every page, invisible to every unit test. promote() chmods the site root to
 * 0755 for that reason. Everything below it is already fine: SiteBuilder mkdirs
 * its subdirectories at 0755 and writes files at 0644.
 */
final class BuildPromoter
{
    private const PUBLISHED_DIR_MODE = 0o755;

    public function __construct(private readonly JobLayout $layout)
    {
    }

    /**
     * Publishes {buildTempDir}/{site}/{build}/output as the live site, then
     * clears the build's temp tree.
     *
     * @throws UnrecoverableMessageHandlingException when no retry could help
     * @throws \RuntimeException                     on a filesystem failure worth retrying
     */
    public function promote(string $staticSiteId, string $buildId): void
    {
        try {
            JobLayout::assertSafeIds($staticSiteId, $buildId);
        } catch (\InvalidArgumentException $e) {
            // A success message for an unsafe id is impossible from a real
            // build -- ssg-worker's JobWorkspace would have thrown long before
            // rendering. So this is a forged or corrupted message, and no
            // amount of retrying changes that.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        $output = $this->layout->buildOutputDir($staticSiteId, $buildId);
        $published = $this->layout->publishedSiteDir($staticSiteId);
        $staging = $this->layout->stagingDir($buildId);

        // Checked FIRST, before the build output. A complete staged release
        // means a previous attempt already finished the expensive cross-mount
        // copy and then died -- either before the swap, or after it but before
        // clearing the temp tree. Either way the copy never needs redoing.
        if (is_dir($staging)) {
            $this->swap($staging, $published, $buildId);
            $this->clearJobDir($staticSiteId, $buildId);

            return;
        }

        if (is_dir($output)) {
            $this->publish($output, $published, $staging, $buildId);
            $this->clearJobDir($staticSiteId, $buildId);

            return;
        }

        if (is_dir($published)) {
            // Already promoted. The normal replay: a previous attempt published
            // the site and then failed to deliver the callback.
            return;
        }

        // Nothing staged, nothing published, nothing to promote. There is no
        // filesystem state any retry could reach, so park it rather than spend
        // the retry budget. A build is only reported as succeeded after
        // SiteRenderer returns, so this means the tree was swept or the message
        // does not belong to any build that ran.
        throw new UnrecoverableMessageHandlingException(sprintf(
            'Nothing to promote for build %s of site %s: no build output, no staged release and no published site.',
            $buildId,
            $staticSiteId,
        ));
    }

    /**
     * Stages the rendered output and swaps it in.
     *
     * @throws UnrecoverableMessageHandlingException if the build produced nothing
     */
    private function publish(string $output, string $published, string $staging, string $buildId): void
    {
        // Refuse to publish an empty build. The swap below removes the live
        // site, so without this check a build that rendered nothing would take
        // a working site down and replace it with a 404 -- worse than failing.
        if (Filesystem::isEmptyDir($output)) {
            throw new UnrecoverableMessageHandlingException(
                sprintf('Refusing to publish build %s: %s is empty.', $buildId, $output),
            );
        }

        Filesystem::ensureDir($this->layout->stagingRoot(), self::PUBLISHED_DIR_MODE);

        $partial = $this->layout->partialStagingDir($buildId);

        // The build temp tree and the published tree are separate mounts, so
        // this is a copy rather than a rename and therefore NOT atomic. It
        // lands on a scratch name for that reason: a crash mid-copy leaves
        // `<build>.partial`, which nothing publishes, instead of a half-built
        // tree sitting at the staging path the swap trusts.
        //
        // Anything left by an earlier crashed attempt is cleared first, or the
        // copy would merge into it and publish a mix of two builds.
        Filesystem::removeDir($partial);
        Filesystem::moveDir($output, $partial);

        // See the class docblock: output/ arrives as 0750 and would 403.
        @chmod($partial, self::PUBLISHED_DIR_MODE);

        // Same mount, so atomic: this is the instant the copy becomes a
        // release the swap is allowed to publish.
        Filesystem::rename($partial, $staging);

        $this->swap($staging, $published, $buildId);
    }

    /**
     * Replaces $published with $staging using two renames.
     *
     * Not a single rename: rename() of a directory onto an existing NON-EMPTY
     * directory fails with ENOTEMPTY, and it never merges, so a plain rename
     * would work for a site's first build and break on every rebuild after it.
     *
     * Not rm -rf then rename either: that leaves the site 404 for however long
     * a recursive delete takes, and a crash mid-delete leaves it deleted. Here
     * the live site is renamed aside atomically, the new one renamed in
     * atomically, and only then is the old tree deleted -- so the gap is two
     * syscalls and a crash inside it leaves both trees intact and recoverable.
     */
    private function swap(string $staging, string $published, string $buildId): void
    {
        $retiring = $this->layout->retiringDir($buildId);

        // A retiring directory left by a crashed attempt would make the rename
        // below fail with ENOTEMPTY, so clear it first.
        Filesystem::removeDir($retiring);

        if (is_dir($published)) {
            Filesystem::rename($published, $retiring);
        }

        Filesystem::rename($staging, $published);

        // Best effort: the site is already live and correct. Failing here would
        // replay a completed promotion for the sake of a scratch directory.
        if (!Filesystem::removeDir($retiring)) {
            error_log(sprintf('[WARN] could not remove the retired site at %s', $retiring));
        }
    }

    /**
     * Drops the build's whole temp tree, which is what finally retires input/ --
     * the downloaded archive plus its fully extracted copy, and by far the
     * bulkiest thing on the volume.
     *
     * Best effort throughout, for the same reason as above.
     */
    private function clearJobDir(string $staticSiteId, string $buildId): void
    {
        $jobDir = $this->layout->jobDir($staticSiteId, $buildId);

        if (!Filesystem::removeDir($jobDir)) {
            error_log(sprintf('[WARN] could not remove the job directory at %s', $jobDir));
        }

        // Only when this was the site's last build; another build of the same
        // site may be in flight.
        $siteDir = $this->layout->siteTempDir($staticSiteId);
        if (Filesystem::isEmptyDir($siteDir)) {
            @rmdir($siteDir);
        }
    }
}
