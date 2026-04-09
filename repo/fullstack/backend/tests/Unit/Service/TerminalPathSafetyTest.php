<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Organization;
use App\Entity\Terminal;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\SettingsRepository;
use App\Repository\TerminalPackageTransferRepository;
use App\Repository\TerminalPlaylistRepository;
use App\Repository\TerminalRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Service\AuditService;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests that unsafe package names are rejected at the service boundary.
 */
class TerminalPathSafetyTest extends TestCase
{
    private TerminalService $service;
    private TerminalRepository&MockObject $terminalRepo;
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->terminalRepo = $this->createMock(TerminalRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $settings = $this->createMock(\App\Entity\Settings::class);
        $settings->method('getTerminalsEnabled')->willReturn(true);

        $settingsRepo = $this->createMock(SettingsRepository::class);
        $settingsRepo->method('findByOrganizationId')->willReturn($settings);

        $orgScope = $this->createMock(OrganizationScope::class);
        $orgScope->method('getOrganizationId')->willReturn('org-1');

        $rbac = $this->createMock(RbacEnforcer::class);
        $audit = $this->createMock(AuditService::class);

        $this->service = new TerminalService(
            $this->terminalRepo,
            $this->createMock(TerminalPlaylistRepository::class),
            $this->createMock(TerminalPackageTransferRepository::class),
            $settingsRepo,
            $this->em,
            $orgScope,
            $rbac,
            $audit,
        );
    }

    private function makeManager(): User&MockObject
    {
        $org = $this->createMock(Organization::class);
        $org->method('getId')->willReturn('org-1');
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('mgr-1');
        $user->method('getRole')->willReturn(UserRole::PROPERTY_MANAGER);
        $user->method('getOrganization')->willReturn($org);
        $user->method('getOrganizationId')->willReturn('org-1');
        $user->method('getUsername')->willReturn('manager');
        return $user;
    }

    private function setupTerminal(): void
    {
        $terminal = $this->createMock(Terminal::class);
        $terminal->method('getId')->willReturn('term-1');
        $this->terminalRepo->method('findByIdAndOrg')->willReturn($terminal);
        $this->em->method('persist');
        $this->em->method('flush');
    }

    // ─── Traversal attacks ────────────────────────────────────────────

    public function testRejectsPathTraversalDoubleDot(): void
    {
        $this->setupTerminal();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateTransfer($this->makeManager(), 'term-1', '../../etc/passwd', 'abc', 1);
    }

    public function testRejectsPathTraversalBackslash(): void
    {
        $this->setupTerminal();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateTransfer($this->makeManager(), 'term-1', '..\\..\\windows\\system32\\evil', 'abc', 1);
    }

    public function testStripsSlashAndSanitizes(): void
    {
        $this->setupTerminal();
        // 'subdir/file.pkg' → after stripping '/' → 'subdirfile.pkg' (safe)
        $result = $this->service->initiateTransfer($this->makeManager(), 'term-1', 'subdir/file.pkg', 'abc', 1);
        $this->assertSame('subdirfile.pkg', $result->getPackageName());
    }

    public function testRejectsEmptyName(): void
    {
        $this->setupTerminal();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateTransfer($this->makeManager(), 'term-1', '', 'abc', 1);
    }

    public function testRejectsDotOnlyName(): void
    {
        $this->setupTerminal();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateTransfer($this->makeManager(), 'term-1', '.', 'abc', 1);
    }

    public function testRejectsDoubleDotOnlyName(): void
    {
        $this->setupTerminal();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateTransfer($this->makeManager(), 'term-1', '..', 'abc', 1);
    }

    public function testRejectsHiddenFileName(): void
    {
        $this->setupTerminal();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateTransfer($this->makeManager(), 'term-1', '.htaccess', 'abc', 1);
    }

    public function testRejectsWindowsReservedName(): void
    {
        $this->setupTerminal();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateTransfer($this->makeManager(), 'term-1', 'CON.pkg', 'abc', 1);
    }

    public function testRejectsNulReservedName(): void
    {
        $this->setupTerminal();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateTransfer($this->makeManager(), 'term-1', 'NUL', 'abc', 1);
    }

    // ─── Valid names ──────────────────────────────────────────────────

    public function testAcceptsSimpleFilename(): void
    {
        $this->setupTerminal();
        $result = $this->service->initiateTransfer($this->makeManager(), 'term-1', 'display-content-v2.pkg', 'abc123', 3);
        $this->assertSame('display-content-v2.pkg', $result->getPackageName());
    }

    public function testAcceptsFilenameWithSpaces(): void
    {
        $this->setupTerminal();
        $result = $this->service->initiateTransfer($this->makeManager(), 'term-1', 'my package file.zip', 'abc123', 1);
        $this->assertSame('my package file.zip', $result->getPackageName());
    }

    public function testStripsTraversalAndUsesBasename(): void
    {
        $this->setupTerminal();
        // After stripping / and \, '..etcpasswd' becomes a valid (if odd) filename
        // since it doesn't start with dot after stripping separators
        // Actually ../../etc/passwd → stripping / → '....etcpasswd' → starts with dot → rejected
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateTransfer($this->makeManager(), 'term-1', '../../etc/passwd', 'abc', 1);
    }
}
