<?php

declare(strict_types=1);

namespace App\Message;

/** Says what a BuildJob does with its site; the values are the `operation` strings publish sends. */
enum BuildOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
