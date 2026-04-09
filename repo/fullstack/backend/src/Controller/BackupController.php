<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Service\BackupService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/backups')]
class BackupController
{
    public function __construct(
        private readonly BackupService $backupService,
    ) {}

    #[Route('', name: 'backups_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->backupService->createBackup($user);

            return new JsonResponse(['data' => $result], 201);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('', name: 'backups_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $page = max(1, (int) $request->query->get('page', '1'));
            $perPage = min(100, max(1, (int) $request->query->get('per_page', '25')));
            $result = $this->backupService->listBackups($user, $page, $perPage);

            return new JsonResponse($result, 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/preview', name: 'backups_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $filename = $body['filename'] ?? '';
            if ($filename === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required field: filename', 'details' => null], 422);
            }
            $result = $this->backupService->previewRestore($user, $filename);

            return new JsonResponse(['data' => $result], 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/restore', name: 'backups_restore', methods: ['POST'])]
    public function restore(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $filename = $body['filename'] ?? '';
            if ($filename === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required field: filename', 'details' => null], 422);
            }
            $result = $this->backupService->restore($user, $filename);

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
