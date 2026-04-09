<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\BookingStatus;
use PHPUnit\Framework\TestCase;

/**
 * Enum-level validation for no-show state transitions.
 *
 * These call the REAL enum methods (not logic replicas). They validate the
 * state machine at the enum level. Service-level tests exercising the full
 * BookingService::markNoShow() flow are in BookingServiceStateTransitionTest.
 */
class NoShowLogicTest extends TestCase
{
    public function testConfirmedCanTransitionToNoShow(): void
    {
        $this->assertTrue(
            BookingStatus::CONFIRMED->canTransitionTo(BookingStatus::NO_SHOW),
            'CONFIRMED must be able to transition to NO_SHOW'
        );
    }

    public function testActiveCanTransitionToNoShow(): void
    {
        $this->assertTrue(
            BookingStatus::ACTIVE->canTransitionTo(BookingStatus::NO_SHOW),
            'ACTIVE must be able to transition to NO_SHOW'
        );
    }

    public function testNoShowIsTerminal(): void
    {
        $this->assertTrue(BookingStatus::NO_SHOW->isTerminal());
    }

    public function testCompletedCannotTransitionToNoShow(): void
    {
        $this->assertFalse(BookingStatus::COMPLETED->canTransitionTo(BookingStatus::NO_SHOW));
    }

    public function testCanceledCannotTransitionToNoShow(): void
    {
        $this->assertFalse(BookingStatus::CANCELED->canTransitionTo(BookingStatus::NO_SHOW));
    }
}
