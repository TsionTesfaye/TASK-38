<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditActions;
use App\Entity\ReconciliationRun;
use App\Entity\User;
use App\Enum\LedgerEntryType;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\DuplicateRequestException;
use App\Exception\EntityNotFoundException;
use App\Repository\BillRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PaymentRepository;
use App\Repository\ReconciliationRunRepository;
use App\Repository\RefundRepository;
use App\Security\OrganizationScope;
use App\Security\RbacEnforcer;
use App\Storage\LocalStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ReconciliationService
{
    public function __construct(
        private readonly ReconciliationRunRepository $runRepository,
        private readonly BillRepository $billRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly RefundRepository $refundRepository,
        private readonly LedgerEntryRepository $ledgerEntryRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly OrganizationScope $orgScope,
        private readonly RbacEnforcer $rbac,
        private readonly AuditService $auditService,
        private readonly LocalStorageService $storageService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function runReconciliation(User $actor): ReconciliationRun
    {
        $this->rbac->enforce($actor, RbacEnforcer::ACTION_EXPORT_FINANCE);
        $orgId = $this->orgScope->getOrganizationId($actor);
        $today = new \DateTimeImmutable('today');

        $existing = $this->runRepository->findByOrgAndDate($orgId, $today);
        if ($existing !== null) {
            return $existing;
        }

        $run = new ReconciliationRun(
            Uuid::v4()->toRfc4122(),
            $actor->getOrganization(),
            $today,
        );
        $this->em->persist($run);
        $this->em->flush();

        try {
            $mismatches = $this->performReconciliation($orgId, $today);
            $mismatchCount = count($mismatches);
            $csvPath = null;

            if ($mismatchCount > 0) {
                $csvContent = $this->buildCsv($mismatches);
                $filename = sprintf('reconciliation_%s_%s.csv', $orgId, $today->format('Y-m-d'));
                $csvPath = $this->storageService->storeExport($csvContent, $filename);
            }

            $run->markCompleted($mismatchCount, $csvPath);
            $this->em->flush();
        } catch (\Throwable $e) {
            $run->markFailed();
            $this->em->flush();
            throw $e;
        }

        $this->auditService->log(
            $orgId,
            $actor,
            $actor->getUsername(),
            AuditActions::RECONCILIATION_RUN,
            'ReconciliationRun',
            $run->getId(),
            null,
            ['mismatch_count' => $run->getMismatchCount(), 'run_date' => $today->format('Y-m-d')],
        );

        return $run;
    }

    public function getRun(User $user, string $runId): ReconciliationRun
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_FINANCE);
        $orgId = $this->orgScope->getOrganizationId($user);
        $run = $this->runRepository->findByIdAndOrg($runId, $orgId);

        if ($run === null) {
            throw new EntityNotFoundException('ReconciliationRun', $runId);
        }

        return $run;
    }

    public function exportRunCsv(User $user, string $runId): string
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_EXPORT_FINANCE);
        $run = $this->getRun($user, $runId);

        $csvPath = $run->getOutputCsvPath();

        if ($csvPath === null) {
            return '';
        }

        return $this->storageService->getFile($csvPath);
    }

    public function listRuns(User $user, int $page, int $perPage): array
    {
        $this->rbac->enforce($user, RbacEnforcer::ACTION_VIEW_FINANCE);
        $orgId = $this->orgScope->getOrganizationId($user);
        $perPage = min($perPage, 100);

        $items = $this->runRepository->findByOrg($orgId, $page, $perPage);
        $total = $this->runRepository->countByOrg($orgId);

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

    public function runDailyReconciliation(): int
    {
        $orgs = $this->organizationRepository->findAllActive();
        $count = 0;

        foreach ($orgs as $org) {
            $today = new \DateTimeImmutable('today');
            $existing = $this->runRepository->findByOrgAndDate($org->getId(), $today);

            if ($existing !== null) {
                continue;
            }

            $run = new ReconciliationRun(
                Uuid::v4()->toRfc4122(),
                $org,
                $today,
            );
            $this->em->persist($run);
            $this->em->flush();

            try {
                $mismatches = $this->performReconciliation($org->getId(), $today);
                $mismatchCount = count($mismatches);
                $csvPath = null;

                if ($mismatchCount > 0) {
                    $csvContent = $this->buildCsv($mismatches);
                    $filename = sprintf('reconciliation_%s_%s.csv', $org->getId(), $today->format('Y-m-d'));
                    $csvPath = $this->storageService->storeExport($csvContent, $filename);
                }

                $run->markCompleted($mismatchCount, $csvPath);
            } catch (\Throwable $e) {
                $run->markFailed();
            }

            $this->em->flush();
            $count++;
        }

        return $count;
    }

    private function performReconciliation(string $orgId, \DateTimeImmutable $date): array
    {
        $mismatches = [];

        $bills = $this->billRepository->findByOrgForReconciliation($orgId);

        foreach ($bills as $bill) {
            $billId = $bill->getId();

            $payments = $this->paymentRepository->findByBillIdAndStatus($billId, PaymentStatus::SUCCEEDED);
            $totalPaid = '0.00';
            foreach ($payments as $payment) {
                $totalPaid = bcadd($totalPaid, $payment->getAmount(), 2);
            }

            $refunds = $this->refundRepository->findByBillIdAndStatus($billId, RefundStatus::ISSUED);
            $totalRefunded = '0.00';
            foreach ($refunds as $refund) {
                $totalRefunded = bcadd($totalRefunded, $refund->getAmount(), 2);
            }

            $expectedOutstanding = bcsub(
                $bill->getOriginalAmount(),
                bcsub($totalPaid, $totalRefunded, 2),
                2,
            );

            if (bccomp($expectedOutstanding, '0.00', 2) < 0) {
                $expectedOutstanding = '0.00';
            }

            $actualOutstanding = $bill->getOutstandingAmount();

            if (bccomp($expectedOutstanding, $actualOutstanding, 2) !== 0) {
                $mismatches[] = [
                    'type' => 'bill_outstanding_mismatch',
                    'bill_id' => $billId,
                    'expected_outstanding' => $expectedOutstanding,
                    'actual_outstanding' => $actualOutstanding,
                    'total_paid' => $totalPaid,
                    'total_refunded' => $totalRefunded,
                    'original_amount' => $bill->getOriginalAmount(),
                ];
            }

            $ledgerEntries = $this->ledgerEntryRepository->findByBillId($billId);
            $ledgerBillTotal = '0.00';
            $ledgerPaymentTotal = '0.00';
            $ledgerRefundTotal = '0.00';

            foreach ($ledgerEntries as $entry) {
                switch ($entry->getEntryType()) {
                    case LedgerEntryType::BILL_ISSUED:
                    case LedgerEntryType::PENALTY_APPLIED:
                        $ledgerBillTotal = bcadd($ledgerBillTotal, $entry->getAmount(), 2);
                        break;
                    case LedgerEntryType::PAYMENT_RECEIVED:
                        $ledgerPaymentTotal = bcadd($ledgerPaymentTotal, $entry->getAmount(), 2);
                        break;
                    case LedgerEntryType::REFUND_ISSUED:
                        $ledgerRefundTotal = bcadd($ledgerRefundTotal, $entry->getAmount(), 2);
                        break;
                    case LedgerEntryType::BILL_VOIDED:
                        break;
                }
            }

            if (bccomp($ledgerPaymentTotal, $totalPaid, 2) !== 0) {
                $mismatches[] = [
                    'type' => 'ledger_payment_mismatch',
                    'bill_id' => $billId,
                    'ledger_payment_total' => $ledgerPaymentTotal,
                    'actual_payment_total' => $totalPaid,
                ];
            }

            if (bccomp($ledgerRefundTotal, $totalRefunded, 2) !== 0) {
                $mismatches[] = [
                    'type' => 'ledger_refund_mismatch',
                    'bill_id' => $billId,
                    'ledger_refund_total' => $ledgerRefundTotal,
                    'actual_refund_total' => $totalRefunded,
                ];
            }

            // Bill-side ledger validation: ledger BILL_ISSUED + PENALTY_APPLIED totals
            // must equal the bill's original amount.
            if (bccomp($ledgerBillTotal, $bill->getOriginalAmount(), 2) !== 0) {
                $mismatches[] = [
                    'type' => 'ledger_bill_amount_mismatch',
                    'bill_id' => $billId,
                    'ledger_bill_total' => $ledgerBillTotal,
                    'actual_bill_amount' => $bill->getOriginalAmount(),
                ];
            }
        }

        return $mismatches;
    }

    private function buildCsv(array $mismatches): string
    {
        if (count($mismatches) === 0) {
            return '';
        }

        // Collect union of all keys across all mismatch types
        $headerSet = [];
        foreach ($mismatches as $m) {
            foreach (array_keys($m) as $k) {
                $headerSet[$k] = true;
            }
        }
        $headers = array_keys($headerSet);
        $lines = [implode(',', $headers)];

        foreach ($mismatches as $mismatch) {
            $values = [];
            foreach ($headers as $header) {
                $value = $mismatch[$header] ?? '';
                $values[] = '"' . str_replace('"', '""', (string) $value) . '"';
            }
            $lines[] = implode(',', $values);
        }

        return implode("\n", $lines) . "\n";
    }
}
