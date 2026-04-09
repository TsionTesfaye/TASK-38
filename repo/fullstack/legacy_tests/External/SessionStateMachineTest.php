<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\SessionStatus;

class SessionStateMachineTest extends TestCase
{
    public function test_active_can_transition_to_expired(): void
    {
        $this->assertTrue(SessionStatus::ACTIVE->canTransitionTo(SessionStatus::EXPIRED));
    }

    public function test_active_can_transition_to_revoked(): void
    {
        $this->assertTrue(SessionStatus::ACTIVE->canTransitionTo(SessionStatus::REVOKED));
    }

    public function test_expired_is_terminal(): void
    {
        $this->assertTrue(SessionStatus::EXPIRED->isTerminal());
        foreach (SessionStatus::cases() as $target) {
            $this->assertFalse(SessionStatus::EXPIRED->canTransitionTo($target));
        }
    }

    public function test_revoked_is_terminal(): void
    {
        $this->assertTrue(SessionStatus::REVOKED->isTerminal());
        foreach (SessionStatus::cases() as $target) {
            $this->assertFalse(SessionStatus::REVOKED->canTransitionTo($target));
        }
    }

    public function test_active_is_not_terminal(): void
    {
        $this->assertFalse(SessionStatus::ACTIVE->isTerminal());
    }
}
