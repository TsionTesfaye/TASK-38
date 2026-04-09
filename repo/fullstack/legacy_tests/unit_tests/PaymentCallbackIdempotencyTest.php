<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\PaymentStatus;

class PaymentCallbackIdempotencyTest extends TestCase
{
    public function test_terminal_payment_cannot_be_reprocessed(): void
    {
        // A payment in SUCCEEDED state must not accept new transitions
        $this->assertTrue(PaymentStatus::SUCCEEDED->isTerminal());
        $this->assertFalse(PaymentStatus::SUCCEEDED->canTransitionTo(PaymentStatus::SUCCEEDED));
        $this->assertFalse(PaymentStatus::SUCCEEDED->canTransitionTo(PaymentStatus::FAILED));
        $this->assertFalse(PaymentStatus::SUCCEEDED->canTransitionTo(PaymentStatus::REJECTED));
    }

    public function test_failed_payment_cannot_become_succeeded(): void
    {
        $this->assertTrue(PaymentStatus::FAILED->isTerminal());
        $this->assertFalse(PaymentStatus::FAILED->canTransitionTo(PaymentStatus::SUCCEEDED));
    }

    public function test_rejected_payment_cannot_become_succeeded(): void
    {
        $this->assertTrue(PaymentStatus::REJECTED->isTerminal());
        $this->assertFalse(PaymentStatus::REJECTED->canTransitionTo(PaymentStatus::SUCCEEDED));
    }

    public function test_pending_to_succeeded_valid(): void
    {
        $this->assertTrue(PaymentStatus::PENDING->canTransitionTo(PaymentStatus::SUCCEEDED));
    }

    public function test_duplicate_callback_on_terminal_returns_same_state(): void
    {
        // Design: processCallback checks isTerminal() and returns existing payment
        // This test validates the enum-level guard that enables idempotency
        $status = PaymentStatus::SUCCEEDED;
        $this->assertTrue($status->isTerminal());

        // Second "callback" would hit the isTerminal check and return early
        // No state machine method allows changing a terminal state
        $allTargets = PaymentStatus::cases();
        foreach ($allTargets as $target) {
            $this->assertFalse($status->canTransitionTo($target));
        }
    }
}
