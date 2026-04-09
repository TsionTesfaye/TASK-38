<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\PaymentStatus;

class PaymentStateMachineTest extends TestCase
{
    public function test_pending_can_transition_to_succeeded(): void
    {
        $this->assertTrue(PaymentStatus::PENDING->canTransitionTo(PaymentStatus::SUCCEEDED));
    }

    public function test_pending_can_transition_to_failed(): void
    {
        $this->assertTrue(PaymentStatus::PENDING->canTransitionTo(PaymentStatus::FAILED));
    }

    public function test_pending_can_transition_to_rejected(): void
    {
        $this->assertTrue(PaymentStatus::PENDING->canTransitionTo(PaymentStatus::REJECTED));
    }

    public function test_pending_is_not_terminal(): void
    {
        $this->assertFalse(PaymentStatus::PENDING->isTerminal());
    }

    public function test_succeeded_is_terminal(): void
    {
        $this->assertTrue(PaymentStatus::SUCCEEDED->isTerminal());
        foreach (PaymentStatus::cases() as $target) {
            $this->assertFalse(
                PaymentStatus::SUCCEEDED->canTransitionTo($target),
                "SUCCEEDED should not transition to {$target->value}"
            );
        }
    }

    public function test_failed_is_terminal(): void
    {
        $this->assertTrue(PaymentStatus::FAILED->isTerminal());
        foreach (PaymentStatus::cases() as $target) {
            $this->assertFalse(
                PaymentStatus::FAILED->canTransitionTo($target),
                "FAILED should not transition to {$target->value}"
            );
        }
    }

    public function test_rejected_is_terminal(): void
    {
        $this->assertTrue(PaymentStatus::REJECTED->isTerminal());
        foreach (PaymentStatus::cases() as $target) {
            $this->assertFalse(
                PaymentStatus::REJECTED->canTransitionTo($target),
                "REJECTED should not transition to {$target->value}"
            );
        }
    }

    public function test_pending_cannot_transition_to_itself(): void
    {
        $this->assertFalse(PaymentStatus::PENDING->canTransitionTo(PaymentStatus::PENDING));
    }

    public function test_allowed_transitions_only_from_pending(): void
    {
        $transitions = PaymentStatus::allowedTransitions();
        $this->assertCount(1, $transitions);
        $this->assertArrayHasKey('pending', $transitions);
        $this->assertCount(3, $transitions['pending']);
    }
}
