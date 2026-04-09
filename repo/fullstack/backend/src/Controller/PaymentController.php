<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Exception\DuplicateRequestException;
use App\Exception\EntityNotFoundException;
use App\Exception\PaymentValidationException;
use App\Security\PaymentSignatureVerifier;
use App\Service\PaymentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PaymentController
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaymentSignatureVerifier $signatureVerifier,
    ) {}

    #[Route('/payments', name: 'payments_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $billId = $body['bill_id'] ?? '';
            $amount = $body['amount'] ?? '';
            $currency = $body['currency'] ?? '';
            if ($billId === '' || $amount === '' || $currency === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required fields: bill_id, amount, currency', 'details' => null], 422);
            }
            $result = $this->paymentService->initiatePayment($user, $billId, $amount, $currency);

            return new JsonResponse(['data' => $result], 201);
        } catch (PaymentValidationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (DuplicateRequestException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/payments/callback', name: 'payments_callback', methods: ['POST'])]
    public function callback(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $signature = $request->headers->get('X-Payment-Signature', '');

        if (!$this->signatureVerifier->verifySignature($signature, $body)) {
            return new JsonResponse([
                'code' => 401,
                'message' => 'Invalid payment signature',
                'details' => null,
            ], 401);
        }

        try {
            $requestId = $body['request_id'] ?? '';
            $callbackSignature = $body['signature'] ?? $signature;
            if ($requestId === '') {
                return new JsonResponse([
                    'code' => 422,
                    'message' => 'Missing required field: request_id',
                    'details' => null,
                ], 422);
            }
            $result = $this->paymentService->processCallback($requestId, $callbackSignature, $body);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (PaymentValidationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/payments', name: 'payments_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $page = max(1, (int) $request->query->get('page', '1'));
            $perPage = min(100, max(1, (int) $request->query->get('per_page', '25')));
            $filters = $request->query->all();
            unset($filters['page'], $filters['per_page']);
            $result = $this->paymentService->listPayments($user, $filters, $page, $perPage);

            return new JsonResponse(['data' => $result], 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/payments/{id}', name: 'payments_get', methods: ['GET'])]
    public function get(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->paymentService->getPayment($user, $id);

            return new JsonResponse(['data' => $result], 200);
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
