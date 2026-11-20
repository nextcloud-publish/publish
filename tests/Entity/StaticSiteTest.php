<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StaticSite;
use App\Entity\StaticSiteStatus;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Covers the StaticSite mapping against the migrated test database, so a mapping that drifts
 * from the migration fails here rather than in a deployment.
 */
final class StaticSiteTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE static_site');
    }

    /**
     * Provides every status, so a new one that does not fit the column fails here.
     *
     * @return array<string, array{StaticSiteStatus}> the status, keyed by its value
     */
    public static function provideStatuses(): array
    {
        $statuses = [];
        foreach (StaticSiteStatus::cases() as $status) {
            $statuses[$status->value] = [$status];
        }

        return $statuses;
    }

    #[DataProvider('provideStatuses')]
    public function testASiteReadsBackAsStored(StaticSiteStatus $status): void
    {
        $id = Uuid::v7();
        $buildId = Uuid::v7();
        $this->entityManager->persist(new StaticSite($id, 'some_collective', $status, $buildId));
        $this->entityManager->flush();
        // Drops the identity map, so find() reads the entry back from the database.
        $this->entityManager->clear();

        $site = $this->entityManager->find(StaticSite::class, $id);

        self::assertInstanceOf(StaticSite::class, $site);
        self::assertTrue($id->equals($site->id));
        self::assertSame('some_collective', $site->slug);
        self::assertSame($status, $site->status);
        self::assertNotNull($site->buildId);
        self::assertTrue($buildId->equals($site->buildId));
    }

    public function testABuildIdIsOptional(): void
    {
        $id = Uuid::v7();
        $this->entityManager->persist(new StaticSite($id, 'some_collective', StaticSiteStatus::Pending));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $site = $this->entityManager->find(StaticSite::class, $id);

        self::assertInstanceOf(StaticSite::class, $site);
        self::assertNull($site->buildId);
    }

    /** The constraint must exist in the database, not just in the mapping. */
    public function testTwoSitesCannotShareASlug(): void
    {
        $this->entityManager->persist(new StaticSite(Uuid::v7(), 'some_collective', StaticSiteStatus::Pending));
        $this->entityManager->flush();

        $this->entityManager->persist(new StaticSite(Uuid::v7(), 'some_collective', StaticSiteStatus::Pending));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testUpdatedAtIsSetOnInsertAndOnUpdate(): void
    {
        $site = new StaticSite(Uuid::v7(), 'some_collective', StaticSiteStatus::Published, Uuid::v7());
        $this->entityManager->persist($site);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement("UPDATE static_site SET updated_at = now() - interval '1 day'");
        $this->entityManager->refresh($site);
        $before = $site->updatedAt;

        $site->startOperation(Uuid::v7());
        $this->entityManager->flush();

        self::assertGreaterThan($before, $site->updatedAt);
    }

    public function testStartOperationWithoutABuildIdKeepsTheStoredOne(): void
    {
        $buildId = Uuid::v7();
        $site = new StaticSite(Uuid::v7(), 'some_collective', StaticSiteStatus::Published, $buildId);

        $site->startOperation();

        self::assertSame(StaticSiteStatus::Pending, $site->status);
        self::assertSame($buildId, $site->buildId);
    }

    public function testUndoOperationPutsBackWhatStartOperationReplaced(): void
    {
        $buildId = Uuid::v7();
        $site = new StaticSite(Uuid::v7(), 'some_collective', StaticSiteStatus::Published, $buildId);

        $site->startOperation(Uuid::v7());
        $site->undoOperation();

        self::assertSame(StaticSiteStatus::Published, $site->status);
        self::assertSame($buildId, $site->buildId);
    }

    public function testUndoOperationWithoutAnOperationIsALogicError(): void
    {
        $site = new StaticSite(Uuid::v7(), 'some_collective', StaticSiteStatus::Published);

        $this->expectException(\LogicException::class);
        $site->undoOperation();
    }

    public function testOnlyARecentlyWrittenPendingSiteIsBusy(): void
    {
        $now = new \DateTimeImmutable();
        $pending = new StaticSite(Uuid::v7(), 'some_collective', StaticSiteStatus::Pending);
        $published = new StaticSite(Uuid::v7(), 'other_collective', StaticSiteStatus::Published);

        self::assertTrue($pending->isBusy($now, 600));
        self::assertFalse($pending->isBusy($now->modify('+601 seconds'), 600), 'pending longer than the timeout counts as stuck');
        self::assertFalse($published->isBusy($now, 600));
    }
}
