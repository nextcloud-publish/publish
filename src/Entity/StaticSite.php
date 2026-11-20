<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A static site publish knows of, one entry per site.
 * The slug is unique across all sites; the constraint is named so code handling a violation can refer to it.
 * updated_at is set on every write publish makes; publish-worker's SQL does not change it.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'static_site')]
#[ORM\UniqueConstraint(name: self::SLUG_CONSTRAINT, columns: ['slug'])]
final class StaticSite
{
    /** Name of the unique constraint on slug; Postgres names it in the message of a violation. */
    public const SLUG_CONSTRAINT = 'uniq_static_site_slug';

    /**
     * @param Uuid $id Identifies the site; chosen by the client, which calls it static_site_id.
     * @param string $slug The site's unique name, at most as long as StaticSiteController's SAFE_ID allows.
     * @param StaticSiteStatus $status The site's state.
     * @param ?Uuid $buildId The most recently enqueued build, null before the first one.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        public private(set) Uuid $id,
        #[ORM\Column(length: 128)]
        public private(set) string $slug,
        #[ORM\Column(length: 16, enumType: StaticSiteStatus::class)]
        public private(set) StaticSiteStatus $status,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public private(set) ?Uuid $buildId = null,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** When publish last wrote the entry; for a `pending` entry, when its operation was enqueued. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    /**
     * Sets updatedAt to now before each insert and update.
     *
     * @return void
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Marks the site `pending` for a new operation with $buildId, under $slug.
     *
     * @param Uuid $buildId The build of the new operation.
     * @param string $slug The slug the site has after the operation.
     * @return void
     */
    public function startOperation(Uuid $buildId, string $slug): void
    {
        $this->status = StaticSiteStatus::Pending;
        $this->buildId = $buildId;
        $this->slug = $slug;
    }

    /**
     * Puts back the values startOperation() replaced, for an operation that could not be enqueued.
     *
     * @param string $slug The slug before the operation.
     * @param StaticSiteStatus $status The status before the operation.
     * @param ?Uuid $buildId The build before the operation.
     * @return void
     */
    public function restore(string $slug, StaticSiteStatus $status, ?Uuid $buildId): void
    {
        $this->slug = $slug;
        $this->status = $status;
        $this->buildId = $buildId;
    }

    /**
     * Checks whether an operation is still running: the entry is `pending` and was written less than $timeoutSeconds ago.
     * A `pending` entry older than that counts as stuck, so a new operation may replace it.
     *
     * @param \DateTimeImmutable $now The current time.
     * @param int $timeoutSeconds How long an operation may stay pending.
     * @return bool true when an operation is running, false when the site is free for a new one
     */
    public function isBusy(\DateTimeImmutable $now, int $timeoutSeconds): bool
    {
        return $this->status === StaticSiteStatus::Pending
            && $this->updatedAt > $now->modify(sprintf('-%d seconds', $timeoutSeconds));
    }
}
