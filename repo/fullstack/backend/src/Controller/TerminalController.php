<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AccessDeniedException;
use App\Exception\AuthenticationException;
use App\Exception\EntityNotFoundException;
use App\Exception\InvalidStateTransitionException;
use App\Service\TerminalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TerminalController
{
    public function __construct(
        private readonly TerminalService $terminalService,
    ) {}

    #[Route('/terminals', name: 'terminals_list', methods: ['GET'])]
    public function listTerminals(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $page = max(1, (int) $request->query->get('page', '1'));
            $perPage = min(100, max(1, (int) $request->query->get('per_page', '25')));
            $filters = $request->query->all();
            unset($filters['page'], $filters['per_page']);
            $result = $this->terminalService->listTerminals($user, $filters, $page, $perPage);

            return new JsonResponse(['data' => $result], 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminals', name: 'terminals_create', methods: ['POST'])]
    public function createTerminal(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $terminalCode = $body['terminal_code'] ?? '';
            $displayName = $body['display_name'] ?? '';
            $locationGroup = $body['location_group'] ?? '';
            $languageCode = $body['language_code'] ?? 'en';
            $accessibilityMode = (bool) ($body['accessibility_mode'] ?? false);

            if ($terminalCode === '' || $displayName === '' || $locationGroup === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required fields: terminal_code, display_name, location_group', 'details' => null], 422);
            }

            $result = $this->terminalService->registerTerminal($user, $terminalCode, $displayName, $locationGroup, $languageCode, $accessibilityMode);

            return new JsonResponse(['data' => $result], 201);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminals/{id}', name: 'terminals_get', methods: ['GET'])]
    public function getTerminal(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->terminalService->getTerminal($user, $id);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminals/{id}', name: 'terminals_update', methods: ['PUT'])]
    public function updateTerminal(Request $request, string $id): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->terminalService->updateTerminal($user, $id, $body);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminal-playlists', name: 'terminal_playlists_list', methods: ['GET'])]
    public function listPlaylists(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $page = max(1, (int) $request->query->get('page', '1'));
            $perPage = min(100, max(1, (int) $request->query->get('per_page', '25')));
            $locationGroup = $request->query->get('location_group', '');
            $result = $this->terminalService->listPlaylists($user, $locationGroup, $page, $perPage);

            return new JsonResponse(['data' => $result], 200);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminal-playlists', name: 'terminal_playlists_create', methods: ['POST'])]
    public function createPlaylist(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $name = $body['name'] ?? '';
            $locationGroup = $body['location_group'] ?? '';
            $scheduleRule = $body['schedule_rule'] ?? '';
            if ($name === '' || $locationGroup === '' || $scheduleRule === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required fields: name, location_group, schedule_rule', 'details' => null], 422);
            }
            $result = $this->terminalService->createPlaylist($user, $name, $locationGroup, $scheduleRule);

            return new JsonResponse(['data' => $result], 201);
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminal-transfers', name: 'terminal_transfers_create', methods: ['POST'])]
    public function createTransfer(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $terminalId = $body['terminal_id'] ?? '';
            $packageName = $body['package_name'] ?? '';
            $checksum = $body['checksum'] ?? '';
            $totalChunks = (int) ($body['total_chunks'] ?? 0);
            if ($terminalId === '' || $packageName === '' || $checksum === '' || $totalChunks <= 0) {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required fields: terminal_id, package_name, checksum, total_chunks', 'details' => null], 422);
            }
            $result = $this->terminalService->initiateTransfer($user, $terminalId, $packageName, $checksum, $totalChunks);

            return new JsonResponse(['data' => $result], 201);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminal-transfers/{id}/chunk', name: 'terminal_transfers_chunk', methods: ['POST'])]
    public function uploadChunk(Request $request, string $id): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $user = $this->getAuthenticatedUser($request);
            $chunkIndex = (int) ($body['chunk_index'] ?? -1);
            $chunkData = $body['chunk_data'] ?? '';
            if ($chunkIndex < 0 || $chunkData === '') {
                return new JsonResponse(['code' => 422, 'message' => 'Missing required fields: chunk_index, chunk_data', 'details' => null], 422);
            }
            $result = $this->terminalService->recordChunk($user, $id, $chunkIndex, $chunkData);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (InvalidStateTransitionException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminal-transfers/{id}/pause', name: 'terminal_transfers_pause', methods: ['POST'])]
    public function pauseTransfer(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->terminalService->pauseTransfer($user, $id);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (InvalidStateTransitionException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminal-transfers/{id}/resume', name: 'terminal_transfers_resume', methods: ['POST'])]
    public function resumeTransfer(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->terminalService->resumeTransfer($user, $id);

            return new JsonResponse(['data' => $result], 200);
        } catch (EntityNotFoundException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (InvalidStateTransitionException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AccessDeniedException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        } catch (AuthenticationException $e) {
            return new JsonResponse($e->toArray(), $e->getHttpStatusCode());
        }
    }

    #[Route('/terminal-transfers/{id}', name: 'terminal_transfers_get', methods: ['GET'])]
    public function getTransfer(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $result = $this->terminalService->getTransfer($user, $id);

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
