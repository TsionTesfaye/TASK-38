<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\TerminalPackageTransfer;

readonly class TransferResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $terminal_id,
        public string $package_name,
        public string $checksum,
        public int $total_chunks,
        public int $transferred_chunks,
        public string $status,
        public string $started_at,
        public ?string $completed_at,
    ) {}

    public static function fromEntity(TerminalPackageTransfer $transfer): self
    {
        return new self(
            id: $transfer->getId(),
            organization_id: $transfer->getOrganizationId(),
            terminal_id: $transfer->getTerminalId(),
            package_name: $transfer->getPackageName(),
            checksum: $transfer->getChecksum(),
            total_chunks: $transfer->getTotalChunks(),
            transferred_chunks: $transfer->getTransferredChunks(),
            status: $transfer->getStatus()->value,
            started_at: $transfer->getStartedAt()->format(\DateTimeInterface::ATOM),
            completed_at: $transfer->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'terminal_id' => $this->terminal_id,
            'package_name' => $this->package_name,
            'checksum' => $this->checksum,
            'total_chunks' => $this->total_chunks,
            'transferred_chunks' => $this->transferred_chunks,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
