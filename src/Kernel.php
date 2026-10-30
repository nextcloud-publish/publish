<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/** Boots the application from config/ with MicroKernelTrait. */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Lists the values APP_ENV may take.
     * @return list<string> the allowed environment names
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
