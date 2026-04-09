<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\BillStatus;

class BillStateMachineTest extends TestCase
{
    public function test_open_can_transition_to_partially_paid(): void
    {
        $this->assertTrue(BillStatus::OPEN->canTransitionTo(BillStatus::PARTIALLY_PAID));
    }

    public function test_open_can_transition_to_paid(): void
    {
        $this->assertTrue(BillStatus::OPEN->canTransitionTo(BillStatus::PAID));
    }

    public function test_open_can_transition_to_voided(): void
    {
        $this->assertTrue(BillStatus::OPEN->canTransitionTo(BillStatus::VOIDED));
    }

    public function test_open_cannot_transition_to_partially_refunded(): void
    {
        $this->assertFalse(BillStatus::OPEN->canTransitionTo(BillStatus::PARTIALLY_REFUNDED));
    }

    public function test_partially_paid_can_transition_to_paid(): void
    {
        $this->assertTrue(BillStatus::PARTIALLY_PAID->canTransitionTo(BillStatus::PAID));
    }

    public function test_partially_paid_can_transition_to_voided(): void
    {
        $this->assertTrue(BillStatus::PARTIALLY_PAID->canTransitionTo(BillStatus::VOIDED));
    }

    public function test_partially_paid_cannot_transition_to_open(): void
    {
        $this->assertFalse(BillStatus::PARTIALLY_PAID->canTransitionTo(BillStatus::OPEN));
    }

    public function test_paid_can_transition_to_partially_refunded(): void
    {
        $this->assertTrue(BillStatus::PAID->canTransitionTo(BillStatus::PARTIALLY_REFUNDED));
    }

    public function test_paid_cannot_transition_to_open(): void
    {
        $this->assertFalse(BillStatus::PAID->canTransitionTo(BillStatus::OPEN));
    }

    public function test_paid_cannot_transition_to_voided(): void
    {
        $this->assertFalse(BillStatus::PAID->canTransitionTo(BillStatus::VOIDED));
    }

    public function test_paid_is_not_terminal(): void
    {
        $this->assertFalse(BillStatus::PAID->isTerminal());
    }

    public function test_voided_is_terminal(): void
    {
        $this->assertTrue(BillStatus::VOIDED->isTerminal());
    }

    public function test_voided_cannot_transition_to_any_status(): void
    {
        foreach (BillStatus::cases() as $target) {
            $this->assertFalse(
                BillStatus::VOIDED->canTransitionTo($target),
                "VOIDED should not transition to {$target->value}"
            );
        }
    }

    public function test_partially_refunded_cannot_transition_to_any_status(): void
    {
        foreach (BillStatus::cases() as $target) {
            $this->assertFalse(
                BillStatus::PARTIALLY_REFUNDED->canTransitionTo($target),
                "PARTIALLY_REFUNDED should not transition to {$target->value}"
            );
        }
    }

    public function test_open_is_not_terminal(): void
    {
        $this->assertFalse(BillStatus::OPEN->isTerminal());
    }

    public function test_allowed_transitions_has_correct_keys(): void
    {
        $transitions = BillStatus::allowedTransitions();
        $this->assertArrayHasKey('open', $transitions);
        $this->assertArrayHasKey('partially_paid', $transitions);
        $this->assertArrayHasKey('paid', $transitions);
        $this->assertArrayNotHasKey('voided', $transitions);
    }
}
