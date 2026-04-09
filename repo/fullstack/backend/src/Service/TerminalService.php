<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Terminal;
use App\Entity\TerminalPackageTransfer;
use App\Entity\TerminalPlaylist;
use App\Entity\User;
use App\Enum\TerminalTransferStatus;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\SettingsRepository;
use App\Repository\TerminalPackageTransferRepository;
use App\Repository\TerminalPlaylistRepository;
use App\Repository\TerminalRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class TerminalService
{
    public function __construct(
        private readonly TerminalRepository $terminalRepo,
        private readonly TerminalPlaylistRepository $playlistRepo,
        private readonly TerminalPackageTransferRepository $transferRepo,
        private readonly SettingsRepository $settingsRepo,
        private readonly EntityManagerInterface $em,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly AuditService $auditService,
    ) {}

    private function assertTerminalsEnabled(string $orgId): void
    {
        $settings = $this->settingsRepo->findByOrganizationId($orgId);
        if (!$settings || !$settings->getTerminalsEnabled()) {
            throw new AccessDeniedException('Terminal feature is not enabled');
        }
    }

    /**
     * Sanitize a user-supplied package name to prevent path traversal and
     * reserved-name attacks. Returns a safe filename suitable for filesystem use.
     */
    private function sanitizePackageName(string $raw): string
    {
        // Strip directory separators (both Unix and Windows)
        $name = str_replace(['/', '\\'], '', $raw);

        // basename as a safety net (handles any remaining path components)
        $name = basename($name);

        // Reject empty, dot-only, or whitespace-only names
        if ($name === '' || $name === '.' || $name === '..' || trim($name) === '') {
            throw new \InvalidArgumentException('Invalid package name');
        }

        // Reject names that start with a dot (hidden files)
        if (str_starts_with($name, '.')) {
            throw new \InvalidArgumentException('Package name must not start with a dot');
        }

        // Reject reserved Windows device names (CON, PRN, AUX, NUL, COM1-9, LPT1-9)
        $upper = strtoupper(pathinfo($name, PATHINFO_FILENAME));
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $upper)) {
            throw new \InvalidArgumentException('Package name uses a reserved name');
        }

        // Limit length
        if (strlen($name) > 255) {
            throw new \InvalidArgumentException('Package name too long');
        }

        return $name;
    }

    public function registerTerminal(User $manager, string $terminalCode, string $displayName, string $locationGroup, string $languageCode = 'en', bool $accessibilityMode = false): Terminal
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $this->assertTerminalsEnabled($orgId);

        $terminal = new Terminal(
            Uuid::v4()->toRfc4122(),
            $manager->getOrganization(),
            $terminalCode,
            $displayName,
            $locationGroup,
            $languageCode,
            $accessibilityMode,
        );
        $this->em->persist($terminal);
        $this->em->flush();
        $this->auditService->log($orgId, $manager, $manager->getUsername(), 'TERMINAL_REGISTERED', 'Terminal', $terminal->getId());
        return $terminal;
    }

    public function updateTerminal(User $manager, string $terminalId, array $data): Terminal
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $this->assertTerminalsEnabled($orgId);

        $terminal = $this->terminalRepo->findByIdAndOrg($terminalId, $orgId);
        if (!$terminal) {
            throw new EntityNotFoundException('Terminal', $terminalId);
        }

        if (isset($data['display_name'])) { $terminal->setDisplayName($data['display_name']); }
        if (isset($data['language_code'])) { $terminal->setLanguageCode($data['language_code']); }
        if (isset($data['accessibility_mode'])) { $terminal->setAccessibilityMode($data['accessibility_mode']); }
        if (isset($data['is_active'])) { $terminal->setIsActive($data['is_active']); }

        $this->em->flush();
        return $terminal;
    }

    public function listTerminals(User $user, array $filters, int $page, int $perPage): array
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($user);
        $perPage = min($perPage, 100);
        $items = $this->terminalRepo->findByOrg($orgId, $filters, $page, $perPage);
        $total = $this->terminalRepo->countByOrg($orgId, $filters);

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_next' => ($page * $perPage) < $total,
            ],
        ];
    }

    public function getTerminal(User $user, string $terminalId): Terminal
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($user);
        $terminal = $this->terminalRepo->findByIdAndOrg($terminalId, $orgId);
        if (!$terminal) {
            throw new EntityNotFoundException('Terminal', $terminalId);
        }
        return $terminal;
    }

    public function createPlaylist(User $manager, string $name, string $locationGroup, string $scheduleRule): TerminalPlaylist
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $this->assertTerminalsEnabled($orgId);

        $playlist = new TerminalPlaylist(
            Uuid::v4()->toRfc4122(),
            $manager->getOrganization(),
            $name,
            $locationGroup,
            $scheduleRule,
        );
        $this->em->persist($playlist);
        $this->em->flush();
        return $playlist;
    }

    public function listPlaylists(User $user, string $locationGroup, int $page, int $perPage): array
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($user);
        $perPage = min($perPage, 100);

        $items = $this->playlistRepo->findByOrgAndLocation($orgId, $locationGroup, $page, $perPage);
        $total = $this->playlistRepo->countByOrgAndLocation($orgId, $locationGroup);

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_next' => ($page * $perPage) < $total,
            ],
        ];
    }

    public function initiateTransfer(User $manager, string $terminalId, string $packageName, string $checksum, int $totalChunks): TerminalPackageTransfer
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($manager);
        $this->assertTerminalsEnabled($orgId);

        // Sanitize package name: strip path components, reject dangerous names
        $safePackageName = $this->sanitizePackageName($packageName);

        $terminal = $this->terminalRepo->findByIdAndOrg($terminalId, $orgId);
        if (!$terminal) {
            throw new EntityNotFoundException('Terminal', $terminalId);
        }

        $transfer = new TerminalPackageTransfer(
            Uuid::v4()->toRfc4122(),
            $manager->getOrganization(),
            $terminal,
            $safePackageName,
            $checksum,
            $totalChunks,
        );
        $this->em->persist($transfer);
        $this->em->flush();

        $this->auditService->log($orgId, $manager, $manager->getUsername(), 'TRANSFER_INITIATED', 'TerminalPackageTransfer', $transfer->getId());
        return $transfer;
    }

    public function recordChunk(User $manager, string $transferId, int $chunkIndex, string $chunkData): TerminalPackageTransfer
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($manager);

        $transfer = $this->transferRepo->findByIdAndOrg($transferId, $orgId);
        if (!$transfer) {
            throw new EntityNotFoundException('TerminalPackageTransfer', $transferId);
        }

        if ($transfer->getStatus() === TerminalTransferStatus::PENDING) {
            $transfer->transitionTo(TerminalTransferStatus::IN_PROGRESS);
        }

        if ($chunkIndex !== $transfer->getTransferredChunks()) {
            throw new \DomainException('Chunk index mismatch. Expected: ' . $transfer->getTransferredChunks());
        }

        if ($chunkIndex >= $transfer->getTotalChunks()) {
            throw new \DomainException('Chunk index exceeds total chunks');
        }

        // Decode and store the chunk payload to disk
        $decodedChunk = base64_decode($chunkData, true);
        if ($decodedChunk === false) {
            throw new \DomainException('Invalid base64 chunk data');
        }

        $storagePath = ($_ENV['STORAGE_PATH'] ?? '/var/www/storage');
        $chunksDir = $storagePath . '/terminal_chunks/' . $transferId;
        if (!is_dir($chunksDir)) {
            mkdir($chunksDir, 0750, true);
        }
        file_put_contents($chunksDir . '/' . $chunkIndex, $decodedChunk);

        $transfer->incrementChunks();

        if ($transfer->isComplete()) {
            // Assemble chunks into the final package file
            $this->assembleChunks($transfer, $chunksDir, $storagePath);
            $this->verifyTransferChecksum($transfer);
            $transfer->transitionTo(TerminalTransferStatus::COMPLETED);
            $this->auditService->log($orgId, $manager, $manager->getUsername(), 'TRANSFER_COMPLETED', 'TerminalPackageTransfer', $transfer->getId());
        }

        $this->em->flush();
        return $transfer;
    }

    private function assembleChunks(TerminalPackageTransfer $transfer, string $chunksDir, string $storagePath): void
    {
        $assetsDir = $storagePath . '/terminal_assets';
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0750, true);
        }

        $outputPath = $assetsDir . '/' . $transfer->getPackageName();
        $outputHandle = fopen($outputPath, 'wb');
        if ($outputHandle === false) {
            throw new \RuntimeException('Cannot create output file for package assembly');
        }

        for ($i = 0; $i < $transfer->getTotalChunks(); $i++) {
            $chunkPath = $chunksDir . '/' . $i;
            if (!file_exists($chunkPath)) {
                fclose($outputHandle);
                @unlink($outputPath);
                throw new \DomainException('Missing chunk ' . $i . ' during assembly');
            }
            $chunkContent = file_get_contents($chunkPath);
            fwrite($outputHandle, $chunkContent);
        }

        fclose($outputHandle);

        // Clean up chunk files
        for ($i = 0; $i < $transfer->getTotalChunks(); $i++) {
            @unlink($chunksDir . '/' . $i);
        }
        @rmdir($chunksDir);
    }

    public function pauseTransfer(User $manager, string $transferId): TerminalPackageTransfer
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($manager);

        $transfer = $this->transferRepo->findByIdAndOrg($transferId, $orgId);
        if (!$transfer) {
            throw new EntityNotFoundException('TerminalPackageTransfer', $transferId);
        }

        $transfer->transitionTo(TerminalTransferStatus::PAUSED);
        $this->em->flush();
        return $transfer;
    }

    public function resumeTransfer(User $manager, string $transferId): TerminalPackageTransfer
    {
        $this->rbac->enforce($manager, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($manager);

        $transfer = $this->transferRepo->findByIdAndOrg($transferId, $orgId);
        if (!$transfer) {
            throw new EntityNotFoundException('TerminalPackageTransfer', $transferId);
        }

        $transfer->transitionTo(TerminalTransferStatus::IN_PROGRESS);
        $this->em->flush();
        return $transfer;
    }

    public function getTransfer(User $user, string $transferId): TerminalPackageTransfer
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_MANAGE_TERMINALS);
        $orgId = $this->orgScope->getOrganizationId($user);
        $transfer = $this->transferRepo->findByIdAndOrg($transferId, $orgId);
        if (!$transfer) {
            throw new EntityNotFoundException('TerminalPackageTransfer', $transferId);
        }
        return $transfer;
    }

    /**
     * Verify transfer integrity before marking complete.
     * Validates: checksum non-empty, chunk count matches, and
     * verifies assembled package hash against stored checksum (SHA-256).
     */
    private function verifyTransferChecksum(TerminalPackageTransfer $transfer): void
    {
        if ($transfer->getChecksum() === '') {
            throw new \DomainException('Transfer checksum is empty — cannot verify integrity');
        }

        if ($transfer->getTransferredChunks() !== $transfer->getTotalChunks()) {
            throw new \DomainException(
                'Chunk count mismatch: transferred=' . $transfer->getTransferredChunks()
                . ' expected=' . $transfer->getTotalChunks(),
            );
        }

        // Verify SHA-256 hash of assembled package against stored checksum
        $storagePath = ($_ENV['STORAGE_PATH'] ?? '/var/www/storage') . '/terminal_assets/' . $transfer->getPackageName();
        if (!file_exists($storagePath)) {
            $transfer->transitionTo(TerminalTransferStatus::FAILED);
            throw new \DomainException('Package file not found at expected path — cannot verify integrity');
        }
        $computedHash = hash_file('sha256', $storagePath);
        if (!hash_equals($transfer->getChecksum(), $computedHash)) {
            $transfer->transitionTo(TerminalTransferStatus::FAILED);
            throw new \DomainException('Checksum verification failed: package integrity compromised');
        }
    }
}
