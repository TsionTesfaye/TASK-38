<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests Do-Not-Disturb window evaluation logic.
 *
 * DND window rules:
 * - If start > end (crosses midnight): time >= start OR time < end
 * - If start < end (same day): time >= start AND time < end
 * - If start === end: no DND window (always false)
 */
class DndWindowTest extends TestCase
{
    private function isInDndWindow(string $time, string $windowStart, string $windowEnd): bool
    {
        if ($windowStart === $windowEnd) {
            return false;
        }

        if ($windowStart > $windowEnd) {
            // Crosses midnight: e.g. 21:00 - 08:00
            return $time >= $windowStart || $time < $windowEnd;
        }

        // Same day: e.g. 09:00 - 17:00
        return $time >= $windowStart && $time < $windowEnd;
    }

    public function test_time_during_evening_in_overnight_window(): void
    {
        // 22:00 is within 21:00-08:00
        $this->assertTrue($this->isInDndWindow('22:00', '21:00', '08:00'));
    }

    public function test_midday_not_in_overnight_window(): void
    {
        // 12:00 is NOT within 21:00-08:00
        $this->assertFalse($this->isInDndWindow('12:00', '21:00', '08:00'));
    }

    public function test_after_midnight_in_overnight_window(): void
    {
        // 03:00 is within 21:00-08:00 (after midnight)
        $this->assertTrue($this->isInDndWindow('03:00', '21:00', '08:00'));
    }

    public function test_same_start_and_end_means_no_dnd(): void
    {
        // Same start and end = no DND window
        $this->assertFalse($this->isInDndWindow('10:00', '21:00', '21:00'));
    }

    public function test_time_within_same_day_window(): void
    {
        // 10:00 is within 09:00-17:00
        $this->assertTrue($this->isInDndWindow('10:00', '09:00', '17:00'));
    }

    public function test_time_outside_same_day_window(): void
    {
        // 18:00 is NOT within 09:00-17:00
        $this->assertFalse($this->isInDndWindow('18:00', '09:00', '17:00'));
    }

    public function test_time_at_window_start_is_inside(): void
    {
        // 21:00 exactly is within 21:00-08:00 (>= start)
        $this->assertTrue($this->isInDndWindow('21:00', '21:00', '08:00'));
    }

    public function test_time_at_window_end_is_outside(): void
    {
        // 08:00 exactly is NOT within 21:00-08:00 (< end, not <=)
        $this->assertFalse($this->isInDndWindow('08:00', '21:00', '08:00'));
    }
}
