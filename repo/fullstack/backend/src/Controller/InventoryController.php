<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Exception\EntityNotFoundException;
use App\Service\InventoryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/inventory')]
class InventoryController
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    #[Route('', name: 'inventory_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $page = max(1, (int) $request->query->get('page', '1'));
            $perPage = min(100, max(1, (int) $request->query->get('per_page', '25')));
            $filters = $request->query->all();
            unset($filters['page'], $filters['per_page']);
            $result = $this->inventoryService->listItems($user, $filters, $page, $perPage);

            return new JsonResponse(['data' => $result], 200);
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('', name: 'inventory_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->inventoryService->createItem(
                $user,
                $body['asset_code'] ?? '',
                $body['name'] ?? '',
                $body['asset_type'] ?? '',
                $body['location_name'] ?? '',
                $body['capacity_mode'] ?? '',
                (int) ($body['total_capacity'] ?? 1),
                $body['timezone'] ?? 'UTC',
            );

            return new JsonResponse(['data' => $result], 201);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/{id}', name: 'inventory_get', methods: ['GET'])]
    public function get(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->inventoryService->getItem($user, $id);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/{id}', name: 'inventory_update', methods: ['PUT'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->inventoryService->updateItem($user, $id, $body);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/{id}/deactivate', name: 'inventory_deactivate', methods: ['POST'])]
    public function deactivate(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->inventoryService->deactivateItem($user, $id);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/{id}/availability', name: 'inventory_availability', methods: ['GET'])]
    public function availability(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $startAt = new \DateTimeImmutable($request->query->get('start_at', 'now'));
            $endAt = new \DateTimeImmutable($request->query->get('end_at', '+1 day'));
            $requestedUnits = max(1, (int) $request->query->get('units', '1'));
            $result = $this->inventoryService->checkAvailability($user, $id, $startAt, $endAt, $requestedUnits);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/{id}/calendar', name: 'inventory_calendar', methods: ['GET'])]
    public function calendar(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $from = new \DateTimeImmutable($request->query->get('from', 'today'));
            $to = new \DateTimeImmutable($request->query->get('to', '+30 days'));
            $result = $this->inventoryService->getAvailabilityCalendar($user, $id, $from, $to);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
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
