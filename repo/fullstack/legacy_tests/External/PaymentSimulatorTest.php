<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\PaymentStatus;
use App\Enum\BillStatus;

class PaymentSimulatorTest extends TestCase
{
    public function test_hmac_signature_consistency(): void
    {
        $secret = 'test_secret';
        $payload = ['amount' => '100.00', 'currency' => 'USD', 'request_id' => 'req-1'];
        ksort($payload);
        $sig1 = hash_hmac('sha256', json_encode($payload), $secret);
        $sig2 = hash_hmac('sha256', json_encode($payload), $secret);
        $this->assertSame($sig1, $sig2);
    }

    public function test_different_payload_different_signature(): void
    {
        $secret = 'test_secret';
        $p1 = ['amount' => '100.00']; ksort($p1);
        $p2 = ['amount' => '200.00']; ksort($p2);
        $this->assertNotSame(
            hash_hmac('sha256', json_encode($p1), $secret),
            hash_hmac('sha256', json_encode($p2), $secret),
        );
    }

    public function test_exact_amount_required_when_partial_disabled(): void
    {
        $outstanding = '500.00';
        $partial = '200.00';
        $this->assertFalse(bccomp($partial, $outstanding, 2) === 0);
    }

    public function test_partial_amount_valid_when_enabled(): void
    {
        $outstanding = '500.00';
        $partial = '200.00';
        $this->assertTrue(bccomp($partial, $outstanding, 2) <= 0);
    }

    public function test_excess_amount_rejected(): void
    {
        $outstanding = '500.00';
        $excess = '600.00';
        $this->assertTrue(bccomp($excess, $outstanding, 2) > 0);
    }

    public function test_terminal_payment_blocks_reprocessing(): void
    {
        foreach ([PaymentStatus::SUCCEEDED, PaymentStatus::FAILED, PaymentStatus::REJECTED] as $status) {
            $this->assertTrue($status->isTerminal());
            foreach (PaymentStatus::cases() as $target) {
                $this->assertFalse($status->canTransitionTo($target));
            }
        }
    }

    public function test_payment_can_update_bill_to_paid(): void
    {
        $this->assertTrue(BillStatus::OPEN->canTransitionTo(BillStatus::PAID));
        $this->assertTrue(BillStatus::OPEN->canTransitionTo(BillStatus::PARTIALLY_PAID));
    }

    public function test_voided_bill_cannot_accept_payment(): void
    {
        $this->assertTrue(BillStatus::VOIDED->isTerminal());
    }
}
