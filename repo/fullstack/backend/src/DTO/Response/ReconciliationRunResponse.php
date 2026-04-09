<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\ReconciliationRun;

readonly class ReconciliationRunResponse
{
    public function __construct(
        public string $id,
        public string $organization_id,
        public string $run_date,
        public string $status,
        public int $mismatch_count,
        public ?string $output_csv_path,
        public string $started_at,
        public ?string $completed_at,
    ) {}

    public static function fromEntity(ReconciliationRun $run): self
    {
        return new self(
            id: $run->getId(),
            organization_id: $run->getOrganizationId(),
            run_date: $run->getRunDate()->format('Y-m-d'),
            status: $run->getStatus()->value,
            mismatch_count: $run->getMismatchCount(),
            output_csv_path: $run->getOutputCsvPath(),
            started_at: $run->getStartedAt()->format(\DateTimeInterface::ATOM),
            completed_at: $run->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'run_date' => $this->run_date,
            'status' => $this->status,
            'mismatch_count' => $this->mismatch_count,
            'output_csv_path' => $this->output_csv_path,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
