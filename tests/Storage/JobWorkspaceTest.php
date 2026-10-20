<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\JobWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobWorkspaceTest extends TestCase
{
    public function testOutputDirIsUnderTheStaticSiteIdsFolder(): void
    {
        $workspace = new JobWorkspace('/srv/jobs');

        self::assertSame('/srv/jobs/abc-123/output', $workspace->outputDir('abc-123'));
    }

    public function testStripsATrailingSlashOnTheBaseDir(): void
    {
        $workspace = new JobWorkspace('/srv/jobs/');

        self::assertSame('/srv/jobs/abc-123/output', $workspace->outputDir('abc-123'));
    }

    public function testRejectsAnUnsafeStaticSiteId(): void
    {
        $workspace = new JobWorkspace('/srv/jobs');

        $this->expectException(\InvalidArgumentException::class);

        $workspace->outputDir('../escape');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function staticSiteIds(): iterable
    {
        yield 'alphanumeric' => ['abc123', true];
        yield 'with dash and underscore' => ['abc-123_xyz', true];
        yield 'empty' => ['', false];
        yield 'traversal' => ['../etc', false];
        yield 'contains a dot' => ['abc.123', false];
        yield 'contains a slash' => ['abc/123', false];
    }

    #[DataProvider('staticSiteIds')]
    public function testIsValidStaticSiteId(string $id, bool $expected): void
    {
        self::assertSame($expected, JobWorkspace::isValidStaticSiteId($id));
    }
}
