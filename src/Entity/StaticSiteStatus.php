<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The state of a StaticSite, stored as its string value.
 * The column is a plain string, so further states need no schema change.
 */
enum StaticSiteStatus: string
{
    case Created = 'created';
    case Updated = 'updated';
}
