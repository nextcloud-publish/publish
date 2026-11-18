<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A static site publish knows of, one entry per site.
 * The slug is unique across all sites; the constraint is named so code handling a violation can refer to it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'static_site')]
#[ORM\UniqueConstraint(name: 'uniq_static_site_slug', columns: ['slug'])]
final class StaticSite
{
    /**
     * @param Uuid $id Identifies the site; chosen by the client, which calls it static_site_id.
     * @param string $slug The site's unique name, at most as long as BuildController's SAFE_ID allows.
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
    }
}
