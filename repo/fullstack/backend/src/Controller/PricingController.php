<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Exception\CurrencyMismatchException;
use App\Exception\EntityNotFoundException;
use App\Service\PricingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PricingController
{
    public function __construct(
        private readonly PricingService $pricingService,
    ) {}

    #[Route('/inventory/{itemId}/pricing', name: 'pricing_list', methods: ['GET'])]
    public function list(Request $request, string $itemId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->pricingService->listPricing($user, $itemId);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/inventory/{itemId}/pricing', name: 'pricing_create', methods: ['POST'])]
    public function create(Request $request, string $itemId): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->pricingService->createPricing(
                $user,
                $itemId,
                $body['rate_type'] ?? '',
                $body['amount'] ?? '0.00',
                $body['currency'] ?? '',
                new \DateTimeImmutable($body['effective_from'] ?? 'now'),
                isset($body['effective_to']) ? new \DateTimeImmutable($body['effective_to']) : null,
            );

            return new JsonResponse(['data' => $result], 201);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (CurrencyMismatchException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (\DomainException $e) {
            return new JsonResponse([
                'code' => 409,
                'message' => 'Pricing configuration conflict',
                'details' => null,
            ], 409);
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
