<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\BookingHoldStatus;

class BookingHoldStateMachineTest extends TestCase
{
    public function test_active_can_transition_to_expired(): void
    {
        $this->assertTrue(BookingHoldStatus::ACTIVE->canTransitionTo(BookingHoldStatus::EXPIRED));
    }

    public function test_active_can_transition_to_released(): void
    {
        $this->assertTrue(BookingHoldStatus::ACTIVE->canTransitionTo(BookingHoldStatus::RELEASED));
    }

    public function test_active_can_transition_to_converted(): void
    {
        $this->assertTrue(BookingHoldStatus::ACTIVE->canTransitionTo(BookingHoldStatus::CONVERTED));
    }

    public function test_active_is_not_terminal(): void
    {
        $this->assertFalse(BookingHoldStatus::ACTIVE->isTerminal());
    }

    public function test_expired_is_terminal(): void
    {
        $this->assertTrue(BookingHoldStatus::EXPIRED->isTerminal());
        foreach (BookingHoldStatus::cases() as $target) {
            $this->assertFalse(BookingHoldStatus::EXPIRED->canTransitionTo($target));
        }
    }

    public function test_released_is_terminal(): void
    {
        $this->assertTrue(BookingHoldStatus::RELEASED->isTerminal());
        foreach (BookingHoldStatus::cases() as $target) {
            $this->assertFalse(BookingHoldStatus::RELEASED->canTransitionTo($target));
        }
    }

    public function test_converted_is_terminal(): void
    {
        $this->assertTrue(BookingHoldStatus::CONVERTED->isTerminal());
        foreach (BookingHoldStatus::cases() as $target) {
            $this->assertFalse(BookingHoldStatus::CONVERTED->canTransitionTo($target));
        }
    }

    public function test_active_cannot_transition_to_itself(): void
    {
        $this->assertFalse(BookingHoldStatus::ACTIVE->canTransitionTo(BookingHoldStatus::ACTIVE));
    }

    public function test_allowed_transitions_returns_correct_map(): void
    {
        $transitions = BookingHoldStatus::allowedTransitions();
        $this->assertArrayHasKey('active', $transitions);
        $this->assertCount(3, $transitions['active']);
        $this->assertContains(BookingHoldStatus::EXPIRED, $transitions['active']);
        $this->assertContains(BookingHoldStatus::RELEASED, $transitions['active']);
        $this->assertContains(BookingHoldStatus::CONVERTED, $transitions['active']);
    }
}
