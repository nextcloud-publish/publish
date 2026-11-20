<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The state of a StaticSite, stored as its string value.
 * The column is a plain string, so further states need no schema change.
 */
enum StaticSiteStatus: string
{
    /** A build is queued; the site is not published yet. */
    case Pending = 'pending';

    /** publish-worker published the site. */
    case Published = 'published';

    /** The last build failed for good; publish-worker reported it as failed. */
    case Failed = 'failed';
}
