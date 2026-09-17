<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Shared bearer-token check for the API's controllers.
 *
 * The token comes from PUBLISH_API_TOKEN, wired in config/services.yaml.
 */
final class ApiTokenCheck
{
    public function __construct(private readonly string $apiToken)
    {
    }

    /**
     * True if the request carries `Authorization: Bearer <token>` matching the
     * configured token.
     */
    public function authenticates(Request $request): bool
    {
        $header = (string) $request->headers->get('Authorization', '');
        $matches = [];

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1) {
            return false;
        }

        return hash_equals($this->apiToken, $matches[1]);
    }
}
