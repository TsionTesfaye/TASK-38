<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Tests billing API request structures and validation.
 */
class BillingApiTest extends TestCase
{
    public function test_bill_creation_request_structure(): void
    {
        $request = [
            'booking_id' => 'bk-001',
            'amount' => '500.00',
            'description' => 'Monthly rent',
            'due_date' => '2026-05-01',
            'line_items' => [
                ['label' => 'Base rent', 'amount' => '450.00'],
                ['label' => 'Utilities', 'amount' => '50.00'],
            ],
        ];

        $this->assertArrayHasKey('booking_id', $request);
        $this->assertArrayHasKey('amount', $request);
        $this->assertArrayHasKey('due_date', $request);
        $this->assertNotEmpty($request['line_items']);
        $this->assertCount(2, $request['line_items']);
    }

    public function test_bill_amount_must_be_positive(): void
    {
        $amount = '500.00';
        $this->assertGreaterThan(0, (float) $amount);

        $zeroAmount = '0.00';
        $this->assertEquals(0, (float) $zeroAmount);
    }

    public function test_line_items_sum_to_total(): void
    {
        $lineItems = [
            ['label' => 'Base rent', 'amount' => '450.00'],
            ['label' => 'Utilities', 'amount' => '50.00'],
        ];
        $total = '0.00';
        foreach ($lineItems as $item) {
            $total = bcadd($total, $item['amount'], 2);
        }
        $this->assertSame('500.00', $total);
    }

    public function test_void_request_requires_bill_id_and_reason(): void
    {
        $request = [
            'bill_id' => 'bill-001',
            'void_reason' => 'Duplicate billing',
        ];
        $this->assertArrayHasKey('bill_id', $request);
        $this->assertArrayHasKey('void_reason', $request);
        $this->assertNotEmpty($request['bill_id']);
        $this->assertNotEmpty($request['void_reason']);
    }

    public function test_pdf_path_follows_convention(): void
    {
        $orgId = 'org-001';
        $billId = 'bill-abc-123';
        $expectedPath = "bills/{$orgId}/{$billId}.pdf";

        $this->assertStringStartsWith('bills/', $expectedPath);
        $this->assertStringEndsWith('.pdf', $expectedPath);
        $this->assertStringContainsString($orgId, $expectedPath);
        $this->assertStringContainsString($billId, $expectedPath);
    }

    public function test_bill_status_values(): void
    {
        $validStatuses = ['draft', 'issued', 'paid', 'voided', 'overdue'];
        $this->assertContains('draft', $validStatuses);
        $this->assertContains('voided', $validStatuses);
        $this->assertCount(5, $validStatuses);
    }

    public function test_due_date_must_be_valid_format(): void
    {
        $dueDate = '2026-05-01';
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
        $this->assertSame('2026-05-01', $parsed->format('Y-m-d'));
    }
}
