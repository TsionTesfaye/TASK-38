<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\HealthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController
{
    public function __construct(
        private readonly HealthService $healthService,
    ) {}

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $result = $this->healthService->checkHealth();
        $httpStatus = $result['status'] === 'ok' ? 200 : 503;

        return new JsonResponse(['data' => $result], $httpStatus);
    }
}
