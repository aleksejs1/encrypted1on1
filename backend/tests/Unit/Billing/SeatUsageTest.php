<?php

namespace App\Tests\Unit\Billing;

use App\Billing\SeatUsage;
use PHPUnit\Framework\TestCase;

class SeatUsageTest extends TestCase
{
    public function testUsedIsPeoplePlusPendingInvites(): void
    {
        self::assertSame(40, (new SeatUsage(people: 37, pendingInvites: 3, limit: 100))->used());
    }

    public function testIsFullFromTheLimitUp(): void
    {
        self::assertFalse((new SeatUsage(people: 1, pendingInvites: 0, limit: 2))->isFull());
        self::assertTrue((new SeatUsage(people: 1, pendingInvites: 1, limit: 2))->isFull());
        self::assertTrue((new SeatUsage(people: 3, pendingInvites: 0, limit: 2))->isFull());
    }

    public function testAnUnlimitedCompanyIsNeverFull(): void
    {
        self::assertFalse((new SeatUsage(people: 5000, pendingInvites: 5000, limit: null))->isFull());
    }
}
