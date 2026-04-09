<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\ReconciliationRunStatus;

class ReconciliationStateMachineTest extends TestCase
{
    public function test_running_can_transition_to_completed(): void
    {
        $this->assertTrue(ReconciliationRunStatus::RUNNING->canTransitionTo(ReconciliationRunStatus::COMPLETED));
    }

    public function test_running_can_transition_to_failed(): void
    {
        $this->assertTrue(ReconciliationRunStatus::RUNNING->canTransitionTo(ReconciliationRunStatus::FAILED));
    }

    public function test_completed_is_terminal(): void
    {
        $this->assertTrue(ReconciliationRunStatus::COMPLETED->isTerminal());
        foreach (ReconciliationRunStatus::cases() as $target) {
            $this->assertFalse(ReconciliationRunStatus::COMPLETED->canTransitionTo($target));
        }
    }

    public function test_failed_is_terminal(): void
    {
        $this->assertTrue(ReconciliationRunStatus::FAILED->isTerminal());
        foreach (ReconciliationRunStatus::cases() as $target) {
            $this->assertFalse(ReconciliationRunStatus::FAILED->canTransitionTo($target));
        }
    }

    public function test_running_is_not_terminal(): void
    {
        $this->assertFalse(ReconciliationRunStatus::RUNNING->isTerminal());
    }
}
