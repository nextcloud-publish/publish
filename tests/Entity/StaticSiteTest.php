<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StaticSite;
use App\Entity\StaticSiteStatus;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testASiteReadsBackAsStored(): void
    {
        $id = Uuid::v7();
        $buildId = Uuid::v7();
        $this->entityManager->persist(new StaticSite($id, 'some_collective', StaticSiteStatus::Updated, $buildId));
        $this->entityManager->flush();
        // Drops the identity map, so find() reads the entry back from the database.
        $this->entityManager->clear();

        $site = $this->entityManager->find(StaticSite::class, $id);

        self::assertInstanceOf(StaticSite::class, $site);
        self::assertTrue($id->equals($site->id));
        self::assertSame('some_collective', $site->slug);
        self::assertSame(StaticSiteStatus::Updated, $site->status);
        self::assertNotNull($site->buildId);
        self::assertTrue($buildId->equals($site->buildId));
    }

    public function testABuildIdIsOptional(): void
    {
        $id = Uuid::v7();
        $this->entityManager->persist(new StaticSite($id, 'some_collective', StaticSiteStatus::Created));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $site = $this->entityManager->find(StaticSite::class, $id);

        self::assertInstanceOf(StaticSite::class, $site);
        self::assertNull($site->buildId);
    }

    /** The constraint must exist in the database, not just in the mapping. */
    public function testTwoSitesCannotShareASlug(): void
    {
        $this->entityManager->persist(new StaticSite(Uuid::v7(), 'some_collective', StaticSiteStatus::Created));
        $this->entityManager->flush();

        $this->entityManager->persist(new StaticSite(Uuid::v7(), 'some_collective', StaticSiteStatus::Created));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }
}
