<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public Endpoint that answers to the container healthcheck and monitoring.
 */
final class HealthController
{
    /**
     * Returns a fixed ok status.
     *
     * @return JsonResponse `{"status":"ok"}` with a 200
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
