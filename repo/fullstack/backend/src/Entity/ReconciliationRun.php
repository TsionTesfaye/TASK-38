<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\ReconciliationRunStatus;
use App\Repository\ReconciliationRunRepository;

#[ORM\Entity(repositoryClass: ReconciliationRunRepository::class)]
#[ORM\Table(name: 'reconciliation_runs')]
#[ORM\UniqueConstraint(name: 'UNIQ_reconciliation_runs_org_date', columns: ['organization_id', 'run_date'])]
class ReconciliationRun implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\Column(name: 'run_date', type: 'date_immutable')]
    private \DateTimeImmutable $runDate;

    #[ORM\Column(type: 'string', length: 30, enumType: ReconciliationRunStatus::class)]
    private ReconciliationRunStatus $status = ReconciliationRunStatus::RUNNING;

    #[ORM\Column(name: 'mismatch_count', type: 'integer', options: ['default' => 0])]
    private int $mismatchCount = 0;

    #[ORM\Column(name: 'output_csv_path', type: 'string', length: 500, nullable: true)]
    private ?string $outputCsvPath = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(string $id, Organization $organization, \DateTimeImmutable $runDate)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->runDate = $runDate;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getRunDate(): \DateTimeImmutable { return $this->runDate; }
    public function getStatus(): ReconciliationRunStatus { return $this->status; }
    public function getMismatchCount(): int { return $this->mismatchCount; }
    public function getOutputCsvPath(): ?string { return $this->outputCsvPath; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    public function transitionTo(ReconciliationRunStatus $newStatus): void
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \App\Exception\InvalidStateTransitionException($this->status->value, $newStatus->value);
        }
        $this->status = $newStatus;
    }

    public function markCompleted(int $mismatchCount, ?string $csvPath): void
    {
        $this->transitionTo(ReconciliationRunStatus::COMPLETED);
        $this->mismatchCount = $mismatchCount;
        $this->outputCsvPath = $csvPath;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(): void
    {
        $this->transitionTo(ReconciliationRunStatus::FAILED);
        $this->completedAt = new \DateTimeImmutable();
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'run_date' => $this->runDate->format('Y-m-d'),
            'status' => $this->status->value,
            'mismatch_count' => $this->mismatchCount,
            'output_csv_path' => $this->outputCsvPath,
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'completed_at' => $this->completedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
