<?php

namespace Tests\Unit;

use App\Domain\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_allowed_transitions(): void
    {
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::AwaitingPayment));
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Pending->canTransitionTo(OrderStatus::Confirmed));

        $this->assertTrue(OrderStatus::AwaitingPayment->canConfirmFromPayment());
        $this->assertFalse(OrderStatus::Pending->canConfirmFromPayment());
        $this->assertTrue(OrderStatus::Confirmed->canShip());
    }
}
