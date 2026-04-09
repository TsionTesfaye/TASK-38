<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Enum\NotificationStatus;

class NotificationStateMachineTest extends TestCase
{
    public function test_pending_can_transition_to_delivered(): void
    {
        $this->assertTrue(NotificationStatus::PENDING->canTransitionTo(NotificationStatus::DELIVERED));
    }

    public function test_pending_cannot_transition_to_read(): void
    {
        $this->assertFalse(NotificationStatus::PENDING->canTransitionTo(NotificationStatus::READ));
    }

    public function test_delivered_can_transition_to_read(): void
    {
        $this->assertTrue(NotificationStatus::DELIVERED->canTransitionTo(NotificationStatus::READ));
    }

    public function test_delivered_cannot_transition_to_pending(): void
    {
        $this->assertFalse(NotificationStatus::DELIVERED->canTransitionTo(NotificationStatus::PENDING));
    }

    public function test_read_cannot_transition_to_any(): void
    {
        foreach (NotificationStatus::cases() as $target) {
            $this->assertFalse(NotificationStatus::READ->canTransitionTo($target));
        }
    }
}
