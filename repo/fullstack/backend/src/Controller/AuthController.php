<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AccountFrozenException;
use App\Exception\AuthenticationException;
use App\Exception\EntityNotFoundException;
use App\Service\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/auth')]
class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    #[Route('/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $username = $body['username'] ?? '';
            $password = $body['password'] ?? '';
            $deviceLabel = $body['device_label'] ?? '';
            $clientDeviceId = $body['client_device_id'] ?? '';

            if ($username === '' || $password === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required fields: username, password', 'details' => null], 422);
            }

            $result = $this->authService->authenticate($username, $password, $deviceLabel, $clientDeviceId);

            return new JsonResponse(['data' => $result], 200);
        } catch (AuthenticationException|AccountFrozenException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $refreshToken = $body['refresh_token'] ?? '';
            if ($refreshToken === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required field: refresh_token', 'details' => null], 422);
            }
            $result = $this->authService->refreshToken($refreshToken);

            return new JsonResponse(['data' => $result], 200);
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $sessionId = $body['session_id'] ?? '';

            if ($sessionId === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required field: session_id', 'details' => null], 422);
            }

            $this->authService->logout($user, $sessionId);

            return new JsonResponse(['data' => ['message' => 'Logged out successfully']], 200);
        } catch (EntityNotFoundException|AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/change-password', name: 'auth_change_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $currentPassword = $body['current_password'] ?? '';
            $newPassword = $body['new_password'] ?? '';
            if ($currentPassword === '' || $newPassword === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required fields: current_password, new_password', 'details' => null], 422);
            }
            $this->authService->changePassword($user, $currentPassword, $newPassword);
            $result = ['message' => 'Password changed successfully'];

            return new JsonResponse(['data' => $result], 200);
        } catch (AuthenticationException|AccessDeniedException $e) {
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
