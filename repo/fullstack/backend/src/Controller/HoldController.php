<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Exception\EntityNotFoundException;
use App\Exception\HoldExpiredException;
use App\Exception\InsufficientCapacityException;
use App\Exception\InvalidStateTransitionException;
use App\Service\BookingHoldService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class HoldController
{
    public function __construct(
        private readonly BookingHoldService $holdService,
    ) {}

    #[Route('/holds', name: 'holds_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        $inventoryItemId = $body['inventory_item_id'] ?? '';
        $units = (int) ($body['held_units'] ?? 0);
        $rawStart = $body['start_at'] ?? '';
        $rawEnd = $body['end_at'] ?? '';
        $requestKey = $body['request_key'] ?? '';

        if ($inventoryItemId === '' || $units <= 0 || $rawStart === '' || $rawEnd === '' || $requestKey === '') {
            return new JsonResponse(['code' => 422, 'message' => 'Missing required fields: inventory_item_id, held_units, start_at, end_at, request_key', 'details' => null], 422);
        }

        try {
            $startAt = new \DateTimeImmutable($rawStart);
            $endAt = new \DateTimeImmutable($rawEnd);
        } catch (\Exception) {
            return new JsonResponse(['code' => 422, 'message' => 'Invalid date format for start_at or end_at', 'details' => null], 422);
        }

        if ($startAt >= $endAt) {
            return new JsonResponse(['code' => 422, 'message' => 'start_at must be before end_at', 'details' => null], 422);
        }

        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->holdService->createHold($user, $inventoryItemId, $units, $startAt, $endAt, $requestKey);

            return new JsonResponse(['data' => $result], 201);
        } catch (InsufficientCapacityException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/holds/{id}/confirm', name: 'holds_confirm', methods: ['POST'])]
    public function confirm(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $body = json_decode($request->getContent(), true) ?? [];
            $requestKey = $body['request_key'] ?? '';
            if ($requestKey === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required field: request_key', 'details' => null], 422);
            }
            $result = $this->holdService->confirmHold($user, $id, $requestKey);

            return new JsonResponse(['data' => $result], 200);
        } catch (HoldExpiredException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (InvalidStateTransitionException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/holds/{id}/release', name: 'holds_release', methods: ['POST'])]
    public function release(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $this->holdService->releaseHold($user, $id);

            return new JsonResponse(['data' => ['message' => 'Hold released']], 200);
        } catch (InvalidStateTransitionException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/holds/{id}', name: 'holds_get', methods: ['GET'])]
    public function get(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->holdService->getHold($user, $id);

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
