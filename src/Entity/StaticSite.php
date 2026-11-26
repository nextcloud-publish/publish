<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A static site publish knows of, one entry per site.
 * The slug is unique across all sites.
 * updated_at is set on every write publish makes; publish-worker's SQL does not change it.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'static_site')]
#[ORM\UniqueConstraint(name: 'uniq_static_site_slug', columns: ['slug'])]
final class StaticSite
{
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
     * The values startOperation() replaced, kept in memory only for undoOperation().
     *
     * @var array{status: StaticSiteStatus, buildId: ?Uuid}|null
     */
    private ?array $beforeOperation = null;

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
     * Marks the site `pending` for a new operation with $buildId.
     * A delete leaves out $buildId and keeps the stored one, since it builds nothing.
     *
     * @param ?Uuid $buildId The build of the new operation.
     * @return void
     */
    public function startOperation(?Uuid $buildId = null): void
    {
        $this->beforeOperation = ['status' => $this->status, 'buildId' => $this->buildId];
        $this->status = StaticSiteStatus::Pending;
        $this->buildId = $buildId ?? $this->buildId;
    }

    /**
     * Puts back the values the last startOperation() replaced, for an operation that could not be enqueued.
     *
     * @return void
     * @throws \LogicException if startOperation() was not called on this instance
     */
    public function undoOperation(): void
    {
        if ($this->beforeOperation === null) {
            throw new \LogicException('No operation to undo.');
        }

        ['status' => $this->status, 'buildId' => $this->buildId] = $this->beforeOperation;
        $this->beforeOperation = null;
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
