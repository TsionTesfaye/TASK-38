<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\BootstrapAlreadyCompletedException;
use App\Service\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class BootstrapController
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    #[Route('/bootstrap', name: 'bootstrap', methods: ['POST'])]
    public function bootstrap(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $orgName = $body['organization_name'] ?? '';
            $orgCode = $body['organization_code'] ?? '';
            $adminUsername = $body['admin_username'] ?? '';
            $adminPassword = $body['admin_password'] ?? '';
            $adminDisplayName = $body['admin_display_name'] ?? '';
            $defaultCurrency = strtoupper(trim($body['default_currency'] ?? 'USD'));

            if ($orgName === '' || $orgCode === '' || $adminUsername === '' || $adminPassword === '' || $adminDisplayName === '') {
                return new JsonResponse([
                    'code' => 422,
                    'message' => 'Missing required fields: organization_name, organization_code, admin_username, admin_password, admin_display_name',
                    'details' => null,
                ], 422);
            }

            if (strlen($adminPassword) < 8) {
                return new JsonResponse(['code' => 422, 'message' => 'admin_password must be at least 8 characters', 'details' => null], 422);
            }

            $result = $this->authService->bootstrap($orgName, $orgCode, $adminUsername, $adminPassword, $adminDisplayName, $defaultCurrency);

            return new JsonResponse(['data' => $result], 201);
        } catch (BootstrapAlreadyCompletedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }
}
