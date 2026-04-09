<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Service\LedgerService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class LedgerController
{
    public function __construct(
        private readonly LedgerService $ledgerService,
    ) {}

    #[Route('/ledger', name: 'ledger_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $page = max(1, (int) $request->query->get('page', '1'));
            $perPage = min(100, max(1, (int) $request->query->get('per_page', '25')));
            $filters = $request->query->all();
            unset($filters['page'], $filters['per_page']);
            $result = $this->ledgerService->listEntries($user, $filters, $page, $perPage);

            return new JsonResponse(['data' => $result], 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/ledger/bill/{billId}', name: 'ledger_by_bill', methods: ['GET'])]
    public function byBill(Request $request, string $billId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->ledgerService->getEntriesForBill($user, $billId);

            return new JsonResponse(['data' => $result], 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/ledger/booking/{bookingId}', name: 'ledger_by_booking', methods: ['GET'])]
    public function byBooking(Request $request, string $bookingId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->ledgerService->getEntriesForBooking($user, $bookingId);

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
