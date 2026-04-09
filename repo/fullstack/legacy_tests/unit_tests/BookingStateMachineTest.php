<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\BookingStatus;

class BookingStateMachineTest extends TestCase
{
    public function test_confirmed_can_transition_to_active(): void
    {
        $this->assertTrue(BookingStatus::CONFIRMED->canTransitionTo(BookingStatus::ACTIVE));
    }

    public function test_confirmed_can_transition_to_canceled(): void
    {
        $this->assertTrue(BookingStatus::CONFIRMED->canTransitionTo(BookingStatus::CANCELED));
    }

    public function test_confirmed_cannot_transition_to_completed(): void
    {
        $this->assertFalse(BookingStatus::CONFIRMED->canTransitionTo(BookingStatus::COMPLETED));
    }

    public function test_confirmed_can_transition_to_no_show(): void
    {
        // Design: CONFIRMED bookings past grace period with no check-in become NO_SHOW
        $this->assertTrue(BookingStatus::CONFIRMED->canTransitionTo(BookingStatus::NO_SHOW));
    }

    public function test_active_can_transition_to_completed(): void
    {
        $this->assertTrue(BookingStatus::ACTIVE->canTransitionTo(BookingStatus::COMPLETED));
    }

    public function test_active_can_transition_to_canceled(): void
    {
        $this->assertTrue(BookingStatus::ACTIVE->canTransitionTo(BookingStatus::CANCELED));
    }

    public function test_active_can_transition_to_no_show(): void
    {
        $this->assertTrue(BookingStatus::ACTIVE->canTransitionTo(BookingStatus::NO_SHOW));
    }

    public function test_completed_is_terminal(): void
    {
        $this->assertTrue(BookingStatus::COMPLETED->isTerminal());
        foreach (BookingStatus::cases() as $target) {
            $this->assertFalse(BookingStatus::COMPLETED->canTransitionTo($target));
        }
    }

    public function test_canceled_is_terminal(): void
    {
        $this->assertTrue(BookingStatus::CANCELED->isTerminal());
        foreach (BookingStatus::cases() as $target) {
            $this->assertFalse(BookingStatus::CANCELED->canTransitionTo($target));
        }
    }

    public function test_no_show_is_terminal(): void
    {
        $this->assertTrue(BookingStatus::NO_SHOW->isTerminal());
        foreach (BookingStatus::cases() as $target) {
            $this->assertFalse(BookingStatus::NO_SHOW->canTransitionTo($target));
        }
    }
}
