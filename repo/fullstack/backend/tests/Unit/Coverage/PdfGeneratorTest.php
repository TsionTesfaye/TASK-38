<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coverage;

use App\Entity\Bill;
use App\Entity\Organization;
use App\Entity\Payment;
use App\Entity\Refund;
use App\Entity\User;
use App\Enum\BillStatus;
use App\Enum\BillType;
use App\Enum\PaymentStatus;
use App\Enum\RefundStatus;
use App\Enum\UserRole;
use App\PDF\PdfGenerator;
use PHPUnit\Framework\TestCase;

class PdfGeneratorTest extends TestCase
{
    private Organization $org;
    private User $tenant;
    private User $admin;

    protected function setUp(): void
    {
        $this->org = new Organization('org-pdf', 'PDF', 'PDFOrg', 'USD');
        $this->tenant = new User('t-pdf', $this->org, 'tpdf', 'h', 'T', UserRole::TENANT);
        $this->admin = new User('a-pdf', $this->org, 'apdf', 'h', 'A', UserRole::ADMINISTRATOR);
    }

    private function makeBill(BillStatus $status, ?\DateTimeImmutable $dueAt = null): Bill
    {
        $bill = new Bill(
            'b-pdf-' . uniqid(),
            $this->org,
            null,
            $this->tenant,
            BillType::INITIAL,
            'USD',
            '100.00',
            $dueAt,
        );
        $r = new \ReflectionProperty($bill, 'status');
        $r->setAccessible(true);
        $r->setValue($bill, $status);
        return $bill;
    }

    public function testGenerateBillPdfOpen(): void
    {
        $gen = new PdfGenerator();
        $bill = $this->makeBill(BillStatus::OPEN);
        $pdf = $gen->generateBillPdf($bill);
        $this->assertNotEmpty($pdf);
        // PDF format starts with %PDF-
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testGenerateBillPdfPaid(): void
    {
        $gen = new PdfGenerator();
        $bill = $this->makeBill(BillStatus::PAID);
        $pdf = $gen->generateBillPdf($bill);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testGenerateBillPdfPartiallyRefunded(): void
    {
        $gen = new PdfGenerator();
        $bill = $this->makeBill(BillStatus::PARTIALLY_REFUNDED);
        $pdf = $gen->generateBillPdf($bill);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testGenerateBillPdfVoided(): void
    {
        $gen = new PdfGenerator();
        $bill = $this->makeBill(BillStatus::VOIDED);
        $pdf = $gen->generateBillPdf($bill);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testGenerateBillPdfWithDueDate(): void
    {
        $gen = new PdfGenerator();
        $bill = $this->makeBill(BillStatus::OPEN, new \DateTimeImmutable('2026-12-31'));
        $pdf = $gen->generateBillPdf($bill);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testGenerateBillPdfWithPaymentsAndRefunds(): void
    {
        $gen = new PdfGenerator();
        $bill = $this->makeBill(BillStatus::PARTIALLY_REFUNDED);

        $payment = new Payment(
            'pay-pdf-1',
            $this->org,
            $bill,
            'req-1',
            'USD',
            '100.00',
        );
        $ps = new \ReflectionProperty($payment, 'status');
        $ps->setAccessible(true);
        $ps->setValue($payment, PaymentStatus::SUCCEEDED);

        $refund = new Refund(
            'ref-pdf-1',
            $this->org,
            $bill,
            null,
            '25.00',
            'customer requested',
            RefundStatus::ISSUED,
            $this->admin,
        );

        $pdf = $gen->generateBillPdf($bill, [$payment], [$refund]);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
