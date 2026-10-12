<?php

namespace App\Tests\Unit\Billing;

use App\Billing\Plan;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class PlanTest extends TestCase
{
    public function testFreeAndPlusHaveTheConfiguredSeatLimits(): void
    {
        $plan = new Plan(defaultSeatLimit: 100, plusSeatLimit: 1000);

        self::assertSame(100, $plan->seatLimit(Plan::FREE));
        self::assertSame(1000, $plan->seatLimit(Plan::PLUS));
    }

    public function testAPlanSetByHandHasNoSeatLimitToLookUp(): void
    {
        $plan = new Plan(defaultSeatLimit: 100, plusSeatLimit: 1000);

        $this->expectException(\InvalidArgumentException::class);
        $plan->seatLimit('enterprise');
    }

    #[TestWith([0, 1000])]
    #[TestWith([100, 0])]
    #[TestWith([-1, 1000])]
    public function testRefusesASeatLimitThatIsNotPositive(int $defaultSeatLimit, int $plusSeatLimit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Plan($defaultSeatLimit, $plusSeatLimit);
    }

    public function testOnlyFreeAndPlusAreManagedByPayment(): void
    {
        self::assertTrue(Plan::isManagedByPayment(Plan::FREE));
        self::assertTrue(Plan::isManagedByPayment(Plan::PLUS));
        self::assertFalse(Plan::isManagedByPayment('enterprise'));
        self::assertFalse(Plan::isManagedByPayment('Plus'));
        self::assertFalse(Plan::isManagedByPayment(''));
    }
}
