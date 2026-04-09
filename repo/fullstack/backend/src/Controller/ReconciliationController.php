<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Exception\EntityNotFoundException;
use App\Service\ReconciliationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reconciliation')]
class ReconciliationController
{
    public function __construct(
        private readonly ReconciliationService $reconciliationService,
    ) {}

    #[Route('/run', name: 'reconciliation_run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->reconciliationService->runReconciliation($user);

            return new JsonResponse(['data' => $result], 201);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/runs', name: 'reconciliation_runs_list', methods: ['GET'])]
    public function listRuns(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $page = max(1, (int) $request->query->get('page', '1'));
            $perPage = min(100, max(1, (int) $request->query->get('per_page', '25')));
            $result = $this->reconciliationService->listRuns($user, $page, $perPage);

            return new JsonResponse(['data' => $result], 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/runs/{id}', name: 'reconciliation_runs_get', methods: ['GET'])]
    public function getRun(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->reconciliationService->getRun($user, $id);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/runs/{id}/csv', name: 'reconciliation_runs_csv', methods: ['GET'])]
    public function downloadCsv(Request $request, string $id): Response
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $csvContent = $this->reconciliationService->exportRunCsv($user, $id);

            $response = new Response($csvContent, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="reconciliation-' . $id . '.csv"',
                'Content-Length' => (string) strlen($csvContent),
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
