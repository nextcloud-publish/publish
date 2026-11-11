<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Answers the same fixed ok status on two paths that differ only in their firewall:
 *  - /health is public, for the container healthcheck and monitoring.
 *  - /auth-verification requires the API token, for clients testing their URL and token.
 */
final class HealthController
{
    /**
     * Returns a fixed ok status.
     *
     * @return JsonResponse `{"status":"ok"}` with a 200
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    #[Route('/auth-verification', name: 'auth_verification', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
