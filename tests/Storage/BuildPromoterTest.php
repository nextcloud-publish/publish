<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\BuildPromoter;
use App\Storage\JobLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * A real temp tree stands in for the volumes.
 *
 * Most of this file puts all three roots in ONE temp directory, so
 * Filesystem::moveDir() takes its rename() fast path. That is not what the dev
 * stack does -- there the build temp tree is a named volume and the published
 * and quarantine trees are bind mounts, i.e. three separate mount points, so
 * every move between them is a copy. The tests under "the cross-mount reality"
 * at the bottom force that path with /dev/shm; everything above them exercises
 * the logic rather than the transfer.
 */
final class BuildPromoterTest extends TestCase
{
    private const SITE = 'site-42';
    private const BUILD = '16ef078ad37fd894';

    private string $root;
    private ?string $publishedOnOtherMount = null;
    private JobLayout $layout;
    private BuildPromoter $promoter;
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/publish-promote-test-' . bin2hex(random_bytes(6));

        $this->layout = new JobLayout(
            $this->root . '/build_temp',
            $this->root . '/published',
            $this->root . '/build_failed',
        );
        $this->promoter = new BuildPromoter($this->layout);

        $this->logFile = sys_get_temp_dir() . '/publish-promote-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);

        foreach ([$this->root, $this->publishedOnOtherMount] as $dir) {
            if ($dir !== null && is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    }

    /**
     * Builds the tree ssg-worker would have left behind, at the same 0750 mode
     * its Helper::ensureDir() uses -- which is exactly what promote() has to
     * correct on the way out.
     *
     * @param array<string, string> $files relative path => contents
     */
    private function givenBuildOutput(array $files = ['index.html' => '<h1>hello</h1>'], string $build = self::BUILD): string
    {
        $output = $this->layout->buildOutputDir(self::SITE, $build);
        mkdir($output, 0o750, true);
        mkdir($this->layout->jobDir(self::SITE, $build) . '/input', 0o750, true);
        file_put_contents($this->layout->jobDir(self::SITE, $build) . '/input/content.tar.gz', 'archive');

        foreach ($files as $path => $contents) {
            $full = $output . '/' . $path;
            if (!is_dir(\dirname($full))) {
                mkdir(\dirname($full), 0o755, true);
            }
            file_put_contents($full, $contents);
        }

        return $output;
    }

    private function publishedSite(): string
    {
        return $this->layout->publishedSiteDir(self::SITE);
    }

    public function testPublishesTheRenderedSite(): void
    {
        $this->givenBuildOutput();

        $this->promoter->promote(self::SITE, self::BUILD);

        self::assertFileExists($this->publishedSite() . '/index.html');
        self::assertSame('<h1>hello</h1>', file_get_contents($this->publishedSite() . '/index.html'));
    }

    public function testRetiresTheWholeJobTreeIncludingTheDownloadedArchive(): void
    {
        // input/ holds the archive and its fully extracted copy, which is the
        // bulkiest thing on the volume; nothing downstream reads it.
        $this->givenBuildOutput();

        $this->promoter->promote(self::SITE, self::BUILD);

        self::assertDirectoryDoesNotExist($this->layout->jobDir(self::SITE, self::BUILD));
        // The site's parent goes too once it holds no other build.
        self::assertDirectoryDoesNotExist($this->layout->siteTempDir(self::SITE));
    }

    /**
     * ssg-worker creates output/ at 0750 as root. Renamed in unchanged, that is
     * a directory whatever serves the site cannot traverse -- a guaranteed 403
     * on every page, and invisible to anything but an end-to-end check.
     */
    public function testThePublishedSiteIsTraversableByOtherUsers(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $this->givenBuildOutput();

        $this->promoter->promote(self::SITE, self::BUILD);

        $mode = fileperms($this->publishedSite()) & 0o777;
        self::assertSame(0o755, $mode, sprintf('published site is mode %o, not 0755', $mode));
    }

    /**
     * The republish case, and the single most likely thing to get wrong:
     * rename() onto an existing NON-EMPTY directory fails with ENOTEMPTY and
     * never merges. A page deleted from the collective must disappear.
     */
    public function testARepublishReplacesTheSiteRatherThanMergingIntoIt(): void
    {
        $this->givenBuildOutput([
            'index.html' => 'first build',
            'removed-later.html' => 'this page goes away',
        ]);
        $this->promoter->promote(self::SITE, self::BUILD);

        $secondBuild = 'bbbbbbbbbbbbbbbb';
        $this->givenBuildOutput(['index.html' => 'second build'], build: $secondBuild);
        $this->promoter->promote(self::SITE, $secondBuild);

        self::assertSame('second build', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertFileDoesNotExist($this->publishedSite() . '/removed-later.html');
    }

    /**
     * The callback runs after this in the same handler, so a failed callback
     * replays the promotion. It has to find its work done and skip.
     */
    public function testAReplayIsANoOpAndDoesNotThrow(): void
    {
        $this->givenBuildOutput();
        $this->promoter->promote(self::SITE, self::BUILD);

        $this->promoter->promote(self::SITE, self::BUILD);

        self::assertSame('<h1>hello</h1>', file_get_contents($this->publishedSite() . '/index.html'));
    }

    /**
     * A COMPLETE staged release means the expensive cross-mount copy already
     * finished and the attempt then died -- either between the two renames of
     * the swap, or after it but before the temp tree was cleared. Either way
     * the copy must not be redone, and the missing build output must not be
     * read as "nothing to promote": that would park the message and leave the
     * site down permanently.
     */
    public function testFinishesASwapThatCrashedBetweenTheTwoRenames(): void
    {
        $staging = $this->layout->stagingDir(self::BUILD);
        mkdir($staging, 0o755, true);
        file_put_contents($staging . '/index.html', 'staged by a crashed attempt');

        $this->promoter->promote(self::SITE, self::BUILD);

        self::assertFileExists($this->publishedSite() . '/index.html');
        self::assertSame('staged by a crashed attempt', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertDirectoryDoesNotExist($staging);
    }

    /**
     * The swap removes the live site, so publishing an empty build would take a
     * working site down and replace it with nothing -- strictly worse than
     * failing the message.
     */
    public function testRefusesToPublishAnEmptyBuild(): void
    {
        $this->givenBuildOutput([]);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('is empty');

        $this->promoter->promote(self::SITE, self::BUILD);
    }

    public function testAnEmptyBuildLeavesTheLiveSiteAlone(): void
    {
        $this->givenBuildOutput(['index.html' => 'the good site']);
        $this->promoter->promote(self::SITE, self::BUILD);

        $this->givenBuildOutput([], build: 'cccccccccccccccc');

        try {
            $this->promoter->promote(self::SITE, 'cccccccccccccccc');
            self::fail('Expected the empty build to be refused.');
        } catch (UnrecoverableMessageHandlingException) {
            self::assertSame('the good site', file_get_contents($this->publishedSite() . '/index.html'));
        }
    }

    /**
     * Nothing to promote and nothing promoted: no filesystem state any retry
     * could reach, so it must park rather than spend the whole budget.
     */
    public function testParksWhenThereIsNothingToPromoteAndNothingPublished(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Nothing to promote');

        $this->promoter->promote(self::SITE, self::BUILD);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnsafeIds(): array
    {
        return [
            'parent traversal' => ['../escape'],
            'nested traversal' => ['../../etc/cron.d'],
            'bare dotdot' => ['..'],
            'single dot' => ['.'],
            'absolute path' => ['/etc/cron.d'],
            'contains slash' => ['site/nested'],
            'null byte' => ["site\0"],
            'empty' => [''],
            'too long' => [str_repeat('a', 129)],
        ];
    }

    #[DataProvider('provideUnsafeIds')]
    public function testParksOnAnUnsafeStaticSiteId(string $unsafeId): void
    {
        // A success for an unsafe id is impossible from a real build --
        // ssg-worker would have thrown before rendering -- so the message is
        // forged or corrupt and no retry changes that.
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->promoter->promote($unsafeId, self::BUILD);
    }

    #[DataProvider('provideUnsafeIds')]
    public function testParksOnAnUnsafeBuildId(string $unsafeId): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->promoter->promote(self::SITE, $unsafeId);
    }

    public function testATraversalIdWritesNothingOutsideTheRoots(): void
    {
        $escapee = \dirname($this->root) . '/publish-escaped-' . bin2hex(random_bytes(6));

        try {
            $this->promoter->promote('../' . basename($escapee), self::BUILD);
            self::fail('Expected a traversal id to be refused.');
        } catch (UnrecoverableMessageHandlingException) {
            self::assertDirectoryDoesNotExist($escapee);
        }
    }

    // --- the cross-mount reality of the dev stack -------------------------

    /**
     * Everything above shares one temp directory, so rename() succeeds and the
     * copy path never runs. In the real stack the build temp tree is a named
     * volume and the published tree is a bind mount, which is a different mount
     * point -- so every promotion goes through the copy instead. /dev/shm is
     * the only way to reproduce that here.
     */
    private function promoterAcrossMounts(): BuildPromoter
    {
        if (!is_dir('/dev/shm') || !is_writable('/dev/shm')) {
            self::markTestSkipped('no second mount point available to force EXDEV');
        }

        $this->publishedOnOtherMount = '/dev/shm/publish-promote-' . bin2hex(random_bytes(6));
        mkdir($this->publishedOnOtherMount, 0o755, true);

        if (stat($this->root)['dev'] === stat($this->publishedOnOtherMount)['dev']) {
            self::markTestSkipped('/dev/shm shares a device with the temp dir');
        }

        $this->layout = new JobLayout(
            $this->root . '/build_temp',
            $this->publishedOnOtherMount,
            $this->root . '/build_failed',
        );

        return new BuildPromoter($this->layout);
    }

    public function testPublishesAcrossAMountBoundary(): void
    {
        $promoter = $this->promoterAcrossMounts();
        $this->givenBuildOutput(['index.html' => 'copied across mounts']);

        $promoter->promote(self::SITE, self::BUILD);

        self::assertSame('copied across mounts', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertDirectoryDoesNotExist($this->layout->jobDir(self::SITE, self::BUILD));
    }

    public function testARepublishAcrossAMountBoundaryStillReplaces(): void
    {
        $promoter = $this->promoterAcrossMounts();

        $this->givenBuildOutput(['index.html' => 'first', 'gone-later.html' => 'x']);
        $promoter->promote(self::SITE, self::BUILD);

        $second = 'bbbbbbbbbbbbbbbb';
        $this->givenBuildOutput(['index.html' => 'second'], build: $second);
        $promoter->promote(self::SITE, $second);

        self::assertSame('second', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertFileDoesNotExist($this->publishedSite() . '/gone-later.html');
    }

    /**
     * The copy is not atomic, so a crash mid-copy leaves a partial tree. It
     * must never reach the live site -- which is why the copy lands on a
     * scratch name and is renamed to the staging path only once complete.
     */
    public function testAHalfCopiedStagingTreeIsNeverPublished(): void
    {
        $partial = $this->layout->partialStagingDir(self::BUILD);
        mkdir($partial, 0o755, true);
        file_put_contents($partial . '/index.html', 'HALF COPIED, MUST NOT SHIP');

        try {
            $this->promoter->promote(self::SITE, self::BUILD);
            self::fail('Expected the promotion to be refused: there is no build output.');
        } catch (UnrecoverableMessageHandlingException) {
            // The partial tree is not a release, so nothing was published.
            self::assertDirectoryDoesNotExist($this->publishedSite());
        }
    }

    public function testAStalePartialFromACrashedAttemptIsDiscardedNotMerged(): void
    {
        // A previous attempt died mid-copy and left a page that is no longer in
        // the build. Merging into it would publish a mix of two builds.
        $partial = $this->layout->partialStagingDir(self::BUILD);
        mkdir($partial, 0o755, true);
        file_put_contents($partial . '/stale.html', 'from a crashed attempt');

        $this->givenBuildOutput(['index.html' => 'the real build']);

        $this->promoter->promote(self::SITE, self::BUILD);

        self::assertSame('the real build', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertFileDoesNotExist($this->publishedSite() . '/stale.html');
    }

}
