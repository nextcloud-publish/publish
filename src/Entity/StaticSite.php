<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A static site publish was asked to build, one row per site.
 * BuildController writes it from every accepted build request; a repeated request for the same site updates the row.
 */
#[ORM\Entity]
final class StaticSite
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        public private(set) Uuid $staticSiteId,
        /** Id of the collective the site is published from, as per lib/Db/Collective.php in the Collectives repo. */
        #[ORM\Column(nullable: true)]
        public private(set) ?int $collectiveId,
        #[ORM\Column(length: 128)]
        public private(set) string $slug,
        #[ORM\Column(length: 200)]
        public private(set) string $title,
    ) {
    }

    /** Takes the values of a new build request for this site. */
    public function update(?int $collectiveId, string $slug, string $title): void
    {
        $this->collectiveId = $collectiveId;
        $this->slug = $slug;
        $this->title = $title;
    }
}
