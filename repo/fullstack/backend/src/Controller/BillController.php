<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Exception\BillVoidException;
use App\Exception\EntityNotFoundException;
use App\Service\BillingService;
use App\Service\PdfService;
use App\Storage\LocalStorageService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/bills')]
class BillController
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly PdfService $pdfService,
        private readonly LocalStorageService $storageService,
    ) {}

    #[Route('', name: 'bills_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $page = max(1, (int) $request->query->get('page', '1'));
            $perPage = min(100, max(1, (int) $request->query->get('per_page', '25')));
            $filters = $request->query->all();
            unset($filters['page'], $filters['per_page']);
            $result = $this->billingService->listBills($user, $filters, $page, $perPage);

            return new JsonResponse(['data' => $result], 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('', name: 'bills_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $bookingId = $body['booking_id'] ?? '';
            $amount = $body['amount'] ?? '';
            $reason = $body['reason'] ?? '';
            if ($bookingId === '' || $amount === '' || $reason === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required fields: booking_id, amount, reason', 'details' => null], 422);
            }
            $result = $this->billingService->issueSupplementalBill($user, $bookingId, $amount, $reason);

            return new JsonResponse(['data' => $result], 201);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/{id}', name: 'bills_get', methods: ['GET'])]
    public function get(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->billingService->getBill($user, $id);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/{id}/void', name: 'bills_void', methods: ['POST'])]
    public function void(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->billingService->voidBill($user, $id);

            return new JsonResponse(['data' => $result], 200);
        } catch (BillVoidException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/{id}/pdf', name: 'bills_pdf', methods: ['GET'])]
    public function pdf(Request $request, string $id): Response
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $bill = $this->billingService->getBill($user, $id);
            $pdfPath = $this->pdfService->generateBillPdf($bill);
            $pdfContent = $this->storageService->getFile($pdfPath);

            $response = new Response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="bill-' . $id . '.pdf"',
                'Content-Length' => (string) strlen($pdfContent),
            ]);

            return $response;
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
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
