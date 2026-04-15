<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Organization;
use App\Entity\Settings;
use App\Entity\Terminal;
use App\Entity\TerminalPackageTransfer;
use App\Entity\TerminalPlaylist;
use App\Entity\User;
use App\Enum\TerminalTransferStatus;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\EntityNotFoundException;
use App\Repository\SettingsRepository;
use App\Repository\TerminalPackageTransferRepository;
use App\Repository\TerminalPlaylistRepository;
use App\Repository\TerminalRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TerminalServiceDeepTest extends TestCase
{
    private Organization $org;
    private User $admin;
    private User $tenant;
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->org = new Organization('org-t', 'ORGT', 'Org T', 'USD');
        $this->admin = new User('admin-t', $this->org, 'admint', 'h', 'Admin', UserRole::ADMINISTRATOR);
        $this->tenant = new User('ten-t', $this->org, 'tent', 'h', 'Ten', UserRole::TENANT);
        // Use a unique tmp dir per test run so assembly doesn't collide.
        $this->storageRoot = sys_get_temp_dir() . '/ts_test_' . uniqid();
        mkdir($this->storageRoot, 0750, true);
        $_ENV['STORAGE_PATH'] = $this->storageRoot;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->storageRoot);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }

    private function makeService(
        ?TerminalRepository $terminalRepo = null,
        ?TerminalPlaylistRepository $playlistRepo = null,
        ?TerminalPackageTransferRepository $transferRepo = null,
        ?EntityManagerInterface $em = null,
        bool $terminalsEnabled = true,
    ): TerminalService {
        $settings = $this->createMock(Settings::class);
        $settings->method('getTerminalsEnabled')->willReturn($terminalsEnabled);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-t');

        return new TerminalService(
            $terminalRepo ?? $this->createMock(TerminalRepository::class),
            $playlistRepo ?? $this->createMock(TerminalPlaylistRepository::class),
            $transferRepo ?? $this->createMock(TerminalPackageTransferRepository::class),
            $settingsRepo,
            $em ?? $this->createMock(EntityManagerInterface::class),
            $orgScope,
            new RbacEnforcer(),
            $this->createMock(AuditService::class),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // registerTerminal
    // ═══════════════════════════════════════════════════════════════

    public function testRegisterTerminalSucceeds(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $svc = $this->makeService(null, null, null, $em);
        $terminal = $svc->registerTerminal($this->admin, 'T-01', 'Lobby', 'HQ', 'en', false);
        $this->assertInstanceOf(Terminal::class, $terminal);
        $this->assertSame('T-01', $terminal->getTerminalCode());
    }

    public function testRegisterTerminalRejectedWhenDisabled(): void
    {
        $svc = $this->makeService(null, null, null, null, terminalsEnabled: false);
        $this->expectException(AccessDeniedException::class);
        $svc->registerTerminal($this->admin, 'T-02', 'Nope', 'HQ');
    }

    public function testRegisterTerminalForbiddenForTenant(): void
    {
        $svc = $this->makeService();
        $this->expectException(AccessDeniedException::class);
        $svc->registerTerminal($this->tenant, 'T-tenant', 'N', 'HQ');
    }

    // ═══════════════════════════════════════════════════════════════
    // updateTerminal
    // ═══════════════════════════════════════════════════════════════

    public function testUpdateTerminalChangesFields(): void
    {
        $terminal = new Terminal('t-1', $this->org, 'T-01', 'Old', 'HQ', 'en', false);

        $termRepo = $this->createMock(TerminalRepository::class);
        $termRepo->method('findByIdAndOrg')->willReturn($terminal);

        $svc = $this->makeService($termRepo);
        $updated = $svc->updateTerminal($this->admin, 't-1', [
            'display_name' => 'New Name',
            'language_code' => 'fr',
            'accessibility_mode' => true,
            'is_active' => false,
        ]);

        $this->assertSame('New Name', $updated->getDisplayName());
        $this->assertSame('fr', $updated->getLanguageCode());
        $this->assertTrue($updated->getAccessibilityMode());
        $this->assertFalse($updated->isActive());
    }

    public function testUpdateTerminalNotFoundThrows(): void
    {
        $termRepo = $this->createMock(TerminalRepository::class);
        $termRepo->method('findByIdAndOrg')->willReturn(null);

        $svc = $this->makeService($termRepo);
        $this->expectException(EntityNotFoundException::class);
        $svc->updateTerminal($this->admin, 'missing', ['display_name' => 'X']);
    }

    // ═══════════════════════════════════════════════════════════════
    // createPlaylist
    // ═══════════════════════════════════════════════════════════════

    public function testCreatePlaylistSucceeds(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');

        $svc = $this->makeService(null, null, null, $em);
        $playlist = $svc->createPlaylist($this->admin, 'Weekday', 'HQ', 'MON-FRI 09:00-17:00');
        $this->assertInstanceOf(TerminalPlaylist::class, $playlist);
        $this->assertSame('Weekday', $playlist->getName());
    }

    // ═══════════════════════════════════════════════════════════════
    // initiateTransfer + sanitizePackageName
    // ═══════════════════════════════════════════════════════════════

    public function testInitiateTransferSanitizesPackageName(): void
    {
        $terminal = new Terminal('t-tx', $this->org, 'T-X', 'K', 'HQ');

        $termRepo = $this->createMock(TerminalRepository::class);
        $termRepo->method('findByIdAndOrg')->willReturn($terminal);

        $em = $this->createMock(EntityManagerInterface::class);

        $svc = $this->makeService($termRepo, null, null, $em);
        // Path components in name should be stripped (without leading dot)
        $tr = $svc->initiateTransfer($this->admin, 't-tx', 'sub/dir/pkg.zip', str_repeat('a', 64), 2);
        $this->assertInstanceOf(TerminalPackageTransfer::class, $tr);
        $this->assertStringNotContainsString('/', $tr->getPackageName());
    }

    public function testInitiateTransferRejectsLeadingDotName(): void
    {
        $terminal = new Terminal('t-dot', $this->org, 'T-D', 'K', 'HQ');

        $termRepo = $this->createMock(TerminalRepository::class);
        $termRepo->method('findByIdAndOrg')->willReturn($terminal);

        $svc = $this->makeService($termRepo);
        $this->expectException(\InvalidArgumentException::class);
        $svc->initiateTransfer($this->admin, 't-dot', '../../etc/passwd', str_repeat('a', 64), 2);
    }

    public function testInitiateTransferUnknownTerminalThrows(): void
    {
        $termRepo = $this->createMock(TerminalRepository::class);
        $termRepo->method('findByIdAndOrg')->willReturn(null);

        $svc = $this->makeService($termRepo);
        $this->expectException(EntityNotFoundException::class);
        $svc->initiateTransfer($this->admin, 'missing', 'pkg.zip', 'abc', 1);
    }

    // ═══════════════════════════════════════════════════════════════
    // recordChunk — full lifecycle through assembly + completion
    // ═══════════════════════════════════════════════════════════════

    public function testRecordChunkFullLifecycleToCompletion(): void
    {
        $terminal = new Terminal('t-rc', $this->org, 'T-R', 'K', 'HQ');
        $chunk0 = 'hello-';
        $chunk1 = 'world';
        $fullPayload = $chunk0 . $chunk1;
        $checksum = hash('sha256', $fullPayload);

        $transfer = new TerminalPackageTransfer(
            'tx-rc',
            $this->org,
            $terminal,
            'pkg.zip',
            $checksum,
            2,
        );

        $txRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $txRepo->method('findByIdAndOrg')->willReturn($transfer);

        $em = $this->createMock(EntityManagerInterface::class);
        $svc = $this->makeService(null, null, $txRepo, $em);

        // Upload chunk 0 → PENDING → IN_PROGRESS
        $svc->recordChunk($this->admin, 'tx-rc', 0, base64_encode($chunk0));
        $this->assertSame(TerminalTransferStatus::IN_PROGRESS, $transfer->getStatus());

        // Upload chunk 1 → triggers assembly + COMPLETED
        $svc->recordChunk($this->admin, 'tx-rc', 1, base64_encode($chunk1));
        $this->assertSame(TerminalTransferStatus::COMPLETED, $transfer->getStatus());

        // Assembled file should exist with correct content
        $assembled = $this->storageRoot . '/terminal_assets/pkg.zip';
        $this->assertFileExists($assembled);
        $this->assertSame($fullPayload, file_get_contents($assembled));
    }

    public function testRecordChunkRejectsOutOfOrderIndex(): void
    {
        $terminal = new Terminal('t-oo', $this->org, 'T-O', 'K', 'HQ');
        $transfer = new TerminalPackageTransfer('tx-oo', $this->org, $terminal, 'p.zip', 'abc', 3);

        $txRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $txRepo->method('findByIdAndOrg')->willReturn($transfer);

        $svc = $this->makeService(null, null, $txRepo);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Chunk index mismatch');
        $svc->recordChunk($this->admin, 'tx-oo', 5, base64_encode('x'));
    }

    public function testRecordChunkRejectsIndexPastTotal(): void
    {
        $terminal = new Terminal('t-past', $this->org, 'T-P', 'K', 'HQ');
        $transfer = new TerminalPackageTransfer('tx-past', $this->org, $terminal, 'p.zip', 'abc', 1);

        // Advance to 1 chunk already transferred → next chunkIndex=1 hits "exceeds total"
        $transfer->incrementChunks();

        $txRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $txRepo->method('findByIdAndOrg')->willReturn($transfer);

        $svc = $this->makeService(null, null, $txRepo);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('exceeds total chunks');
        $svc->recordChunk($this->admin, 'tx-past', 1, base64_encode('x'));
    }

    public function testRecordChunkRejectsInvalidBase64(): void
    {
        $terminal = new Terminal('t-b64', $this->org, 'T-B', 'K', 'HQ');
        $transfer = new TerminalPackageTransfer('tx-b64', $this->org, $terminal, 'p.zip', 'abc', 2);

        $txRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $txRepo->method('findByIdAndOrg')->willReturn($transfer);

        $svc = $this->makeService(null, null, $txRepo);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Invalid base64');
        $svc->recordChunk($this->admin, 'tx-b64', 0, '~~~not-base64~~~');
    }

    public function testRecordChunkChecksumMismatchTransitionsToFailed(): void
    {
        $terminal = new Terminal('t-cksum', $this->org, 'T-C', 'K', 'HQ');
        $transfer = new TerminalPackageTransfer(
            'tx-cksum',
            $this->org,
            $terminal,
            'p.zip',
            'wrong-checksum',   // won't match actual content
            1,
        );

        $txRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $txRepo->method('findByIdAndOrg')->willReturn($transfer);

        $svc = $this->makeService(null, null, $txRepo);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/checksum/i');
        $svc->recordChunk($this->admin, 'tx-cksum', 0, base64_encode('payload'));
    }

    public function testRecordChunkTransferNotFound(): void
    {
        $txRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $txRepo->method('findByIdAndOrg')->willReturn(null);

        $svc = $this->makeService(null, null, $txRepo);
        $this->expectException(EntityNotFoundException::class);
        $svc->recordChunk($this->admin, 'unknown', 0, base64_encode('x'));
    }

    // ═══════════════════════════════════════════════════════════════
    // pauseTransfer / resumeTransfer
    // ═══════════════════════════════════════════════════════════════

    public function testPauseAndResumeTransfer(): void
    {
        $terminal = new Terminal('t-pr', $this->org, 'T-P', 'K', 'HQ');
        $transfer = new TerminalPackageTransfer('tx-pr', $this->org, $terminal, 'p.zip', 'abc', 2);
        $transfer->transitionTo(TerminalTransferStatus::IN_PROGRESS);

        $txRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $txRepo->method('findByIdAndOrg')->willReturn($transfer);

        $svc = $this->makeService(null, null, $txRepo);

        $paused = $svc->pauseTransfer($this->admin, 'tx-pr');
        $this->assertSame(TerminalTransferStatus::PAUSED, $paused->getStatus());

        $resumed = $svc->resumeTransfer($this->admin, 'tx-pr');
        $this->assertSame(TerminalTransferStatus::IN_PROGRESS, $resumed->getStatus());
    }

    public function testPauseUnknownTransferThrows(): void
    {
        $txRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $txRepo->method('findByIdAndOrg')->willReturn(null);

        $svc = $this->makeService(null, null, $txRepo);
        $this->expectException(EntityNotFoundException::class);
        $svc->pauseTransfer($this->admin, 'missing');
    }

    public function testGetTransferReturnsEntity(): void
    {
        $terminal = new Terminal('t-get', $this->org, 'T-G', 'K', 'HQ');
        $transfer = new TerminalPackageTransfer('tx-get', $this->org, $terminal, 'p.zip', 'abc', 1);

        $txRepo = $this->createMock(TerminalPackageTransferRepository::class);
        $txRepo->method('findByIdAndOrg')->willReturn($transfer);

        $svc = $this->makeService(null, null, $txRepo);
        $this->assertSame($transfer, $svc->getTransfer($this->admin, 'tx-get'));
    }

    // ═══════════════════════════════════════════════════════════════
    // listTerminals / listPlaylists with pagination meta
    // ═══════════════════════════════════════════════════════════════

    public function testListTerminalsHasNextWhenMore(): void
    {
        $termRepo = $this->createMock(TerminalRepository::class);
        $termRepo->method('findByOrg')->willReturn([]);
        $termRepo->method('countByOrg')->willReturn(50);

        $svc = $this->makeService($termRepo);
        $r = $svc->listTerminals($this->admin, [], 1, 25);
        $this->assertTrue($r['meta']['has_next']);
        $this->assertSame(50, $r['meta']['total']);
    }

    public function testListPlaylistsCaps100(): void
    {
        $plRepo = $this->createMock(TerminalPlaylistRepository::class);
        $plRepo->method('findByOrgAndLocation')->willReturn([]);
        $plRepo->method('countByOrgAndLocation')->willReturn(0);

        $svc = $this->makeService(null, $plRepo);
        $r = $svc->listPlaylists($this->admin, '', 1, 500); // requested 500 → capped
        $this->assertSame(100, $r['meta']['per_page']);
    }
}
