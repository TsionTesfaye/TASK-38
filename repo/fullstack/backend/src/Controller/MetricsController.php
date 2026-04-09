<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Security\RbacEnforcer;
use App\Service\MetricsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class MetricsController
{
    public function __construct(
        private readonly MetricsService $metricsService,
        private readonly RbacEnforcer $rbac,
    ) {}

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function get(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            // RBAC enforcement moved into MetricsService.getMetricsSummary()
            $result = $this->metricsService->getMetricsSummary($user);

            return new JsonResponse(['data' => $result], 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    private function getAuthenticatedUser(Request $request): User
    {
        $user = $request->attributes->get('authenticated_user');

        if (!$user instanceof User) {
            throw new AuthenticationException('Not authenticated');
        }

        return $user;
    }
}
