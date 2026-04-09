<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\TerminalTransferStatus;
use App\Repository\TerminalPackageTransferRepository;

#[ORM\Entity(repositoryClass: TerminalPackageTransferRepository::class)]
#[ORM\Table(name: 'terminal_package_transfers')]
#[ORM\Index(columns: ['terminal_id', 'status'], name: 'IDX_terminal_pkg_transfers_terminal_status')]
class TerminalPackageTransfer implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Terminal::class)]
    #[ORM\JoinColumn(name: 'terminal_id', referencedColumnName: 'id', nullable: false)]
    private Terminal $terminal;

    #[ORM\Column(name: 'package_name', type: 'string', length: 255)]
    private string $packageName;

    #[ORM\Column(type: 'string', length: 255)]
    private string $checksum;

    #[ORM\Column(name: 'total_chunks', type: 'integer')]
    private int $totalChunks;

    #[ORM\Column(name: 'transferred_chunks', type: 'integer', options: ['default' => 0])]
    private int $transferredChunks = 0;

    #[ORM\Column(type: 'string', length: 30, enumType: TerminalTransferStatus::class)]
    private TerminalTransferStatus $status = TerminalTransferStatus::PENDING;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(string $id, Organization $organization, Terminal $terminal, string $packageName, string $checksum, int $totalChunks)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->terminal = $terminal;
        $this->packageName = $packageName;
        $this->checksum = $checksum;
        $this->totalChunks = $totalChunks;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getTerminal(): Terminal { return $this->terminal; }
    public function getTerminalId(): string { return $this->terminal->getId(); }
    public function getPackageName(): string { return $this->packageName; }
    public function getChecksum(): string { return $this->checksum; }
    public function getTotalChunks(): int { return $this->totalChunks; }
    public function getTransferredChunks(): int { return $this->transferredChunks; }
    public function getStatus(): TerminalTransferStatus { return $this->status; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    public function transitionTo(TerminalTransferStatus $newStatus): void
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \App\Exception\InvalidStateTransitionException($this->status->value, $newStatus->value);
        }
        $this->status = $newStatus;
        if ($newStatus === TerminalTransferStatus::COMPLETED) {
            $this->completedAt = new \DateTimeImmutable();
        }
    }

    public function incrementChunks(): void
    {
        $this->transferredChunks++;
    }

    public function isComplete(): bool
    {
        return $this->transferredChunks >= $this->totalChunks;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'terminal_id' => $this->getTerminalId(),
            'package_name' => $this->packageName,
            'checksum' => $this->checksum,
            'total_chunks' => $this->totalChunks,
            'transferred_chunks' => $this->transferredChunks,
            'status' => $this->status->value,
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'completed_at' => $this->completedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
