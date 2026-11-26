<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Empties static_site after each test of a kernel test case, so every test can create the same site.
 * After the kernel is shut down the container is gone, so this runs before the parent's tearDown().
 */
trait ResetsStaticSites
{
    protected function tearDown(): void
    {
        if (static::$booted) {
            static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE static_site');
        }

        parent::tearDown();
    }
}
