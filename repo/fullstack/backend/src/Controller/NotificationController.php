<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Exception\EntityNotFoundException;
use App\Exception\InvalidStateTransitionException;
use App\Service\NotificationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/notifications')]
class NotificationController
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    #[Route('', name: 'notifications_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $page = max(1, (int) $request->query->get('page', '1'));
            $perPage = min(100, max(1, (int) $request->query->get('per_page', '25')));
            $result = $this->notificationService->listNotifications($user, $page, $perPage);

            return new JsonResponse(['data' => $result], 200);
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/{id}/read', name: 'notifications_read', methods: ['POST'])]
    public function markRead(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->notificationService->markRead($user, $id);

            return new JsonResponse(['data' => $result], 200);
        } catch (InvalidStateTransitionException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/preferences', name: 'notifications_preferences', methods: ['GET'])]
    public function getPreferences(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->notificationService->getPreferences($user);

            return new JsonResponse(['data' => $result], 200);
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/preferences/{eventCode}', name: 'notifications_preferences_update', methods: ['PUT'])]
    public function updatePreference(Request $request, string $eventCode): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->notificationService->updatePreference(
                $user,
                $eventCode,
                (bool) ($body['enabled'] ?? true),
                $body['dnd_start'] ?? null,
                $body['dnd_end'] ?? null,
            );

            return new JsonResponse(['data' => $result], 200);
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
