<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests availability calculation logic.
 *
 * Formula: available = total_capacity - active_holds - active_bookings
 */
class AvailabilityCalculationTest extends TestCase
{
    private function calculateAvailability(int $totalCapacity, int $activeHolds, int $activeBookings): int
    {
        return $totalCapacity - $activeHolds - $activeBookings;
    }

    public function test_full_capacity_available_when_no_holds_or_bookings(): void
    {
        $available = $this->calculateAvailability(10, 0, 0);
        $this->assertSame(10, $available);
    }

    public function test_holds_reduce_availability(): void
    {
        $available = $this->calculateAvailability(10, 3, 0);
        $this->assertSame(7, $available);
    }

    public function test_bookings_reduce_availability(): void
    {
        $available = $this->calculateAvailability(10, 0, 4);
        $this->assertSame(6, $available);
    }

    public function test_holds_and_bookings_both_reduce_availability(): void
    {
        $available = $this->calculateAvailability(10, 3, 4);
        $this->assertSame(3, $available);
    }

    public function test_zero_availability_when_fully_booked(): void
    {
        $available = $this->calculateAvailability(10, 5, 5);
        $this->assertSame(0, $available);
    }

    public function test_negative_availability_means_overbooked(): void
    {
        $available = $this->calculateAvailability(10, 6, 6);
        $this->assertSame(-2, $available);
    }

    public function test_single_unit_capacity(): void
    {
        $available = $this->calculateAvailability(1, 0, 0);
        $this->assertSame(1, $available);

        $available = $this->calculateAvailability(1, 1, 0);
        $this->assertSame(0, $available);

        $available = $this->calculateAvailability(1, 0, 1);
        $this->assertSame(0, $available);
    }

    public function test_large_capacity(): void
    {
        $available = $this->calculateAvailability(500, 120, 280);
        $this->assertSame(100, $available);
    }

    public function test_zero_capacity_always_zero_or_negative(): void
    {
        $available = $this->calculateAvailability(0, 0, 0);
        $this->assertSame(0, $available);

        $available = $this->calculateAvailability(0, 1, 0);
        $this->assertSame(-1, $available);
    }
}
