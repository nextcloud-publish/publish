<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\JobWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobWorkspaceTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/publish-workspace-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            exec('rm -rf ' . escapeshellarg($this->baseDir));
        }
    }

    public function testCreatesInputAndOutputDirectories(): void
    {
        (new JobWorkspace($this->baseDir))->createJobDirectories('site-42');

        self::assertDirectoryExists($this->baseDir . '/site-42/input');
        self::assertDirectoryExists($this->baseDir . '/site-42/output');
    }

    public function testCreatesDirectoriesThatAreWritable(): void
    {
        // ssg-worker downloads into input/ and writes the built site into
        // output/, so both have to be writable, not merely present.
        (new JobWorkspace($this->baseDir))->createJobDirectories('site-42');

        self::assertDirectoryIsWritable($this->baseDir . '/site-42/input');
        self::assertDirectoryIsWritable($this->baseDir . '/site-42/output');
    }

    public function testIsIdempotentForTheSameStaticSiteId(): void
    {
        // A second build for the same site must reuse the existing folders --
        // and leave whatever is already in them alone.
        $workspace = new JobWorkspace($this->baseDir);
        $workspace->createJobDirectories('site-42');
        file_put_contents($this->baseDir . '/site-42/input/keep.md', '# keep');

        $workspace->createJobDirectories('site-42');

        self::assertDirectoryExists($this->baseDir . '/site-42/output');
        self::assertFileExists($this->baseDir . '/site-42/input/keep.md');
    }

    public function testAcceptsAUuidStaticSiteId(): void
    {
        // The id integration-test/trigger-build.sh posts by default.
        $uuid = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';

        (new JobWorkspace($this->baseDir))->createJobDirectories($uuid);

        self::assertDirectoryExists($this->baseDir . '/' . $uuid . '/input');
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
    public function testRejectsUnsafeStaticSiteId(string $unsafeId): void
    {
        self::assertFalse(JobWorkspace::isValidStaticSiteId($unsafeId));

        // InvalidArgumentException, not RuntimeException: a bad id is the
        // caller's mistake, which the controller has to answer with a 400
        // rather than the 503 it gives an unwritable volume.
        $this->expectException(\InvalidArgumentException::class);
        (new JobWorkspace($this->baseDir))->createJobDirectories($unsafeId);
    }

    public function testTraversalIdCreatesNothingOutsideTheBaseDirectory(): void
    {
        $escapee = dirname($this->baseDir) . '/publish-escaped-' . bin2hex(random_bytes(6));

        try {
            (new JobWorkspace($this->baseDir))->createJobDirectories('../' . basename($escapee));
            self::fail('Expected an InvalidArgumentException for a traversal id.');
        } catch (\InvalidArgumentException) {
            self::assertDirectoryDoesNotExist($escapee);
        }
    }

    public function testThrowsWhenTheBaseDirectoryCannotBeCreated(): void
    {
        // A regular file where the base directory should be: mkdir cannot
        // create anything underneath it.
        $blocked = sys_get_temp_dir() . '/publish-blocked-' . bin2hex(random_bytes(6));
        file_put_contents($blocked, 'not a directory');

        try {
            (new JobWorkspace($blocked))->createJobDirectories('site-42');
            self::fail('Expected a RuntimeException for an uncreatable directory.');
        } catch (\RuntimeException $e) {
            // The reason is the actionable half of the message: without it, a
            // full disk, an unmounted volume and a typo in JOB_STORAGE_DIR all
            // read identically to whoever is on call.
            self::assertStringContainsString('Not a directory', $e->getMessage());
            self::assertStringNotContainsString('unknown error', $e->getMessage());
            // And the path, so it is clear which directory failed.
            self::assertStringContainsString($blocked, $e->getMessage());
        } finally {
            unlink($blocked);
        }
    }

    public function testFailureReasonIsTheRealCauseNotAnEarlierWarning(): void
    {
        // error_get_last() is global, so it could just as well hand back an
        // unrelated warning from earlier in the request. What keeps it honest
        // is that a failing mkdir() always raises its own warning first.
        @file_get_contents('/definitely/not/here');

        $blocked = sys_get_temp_dir() . '/publish-blocked-' . bin2hex(random_bytes(6));
        file_put_contents($blocked, 'not a directory');

        try {
            (new JobWorkspace($blocked))->createJobDirectories('site-42');
            self::fail('Expected a RuntimeException for an uncreatable directory.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Not a directory', $e->getMessage());
            self::assertStringNotContainsString('file_get_contents', $e->getMessage());
        } finally {
            unlink($blocked);
        }
    }
}
