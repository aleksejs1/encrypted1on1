<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use App\Entity\InviteRecord;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class InviteRecordTest extends TestCase
{
    public function testStatusIsPendingBeforeExpiryWhenNotAccepted(): void
    {
        $inviteRecord = new InviteRecord('id-1', 'someone@example.com', new Company('Test Co'), null, new \DateTimeImmutable('+1 hour'));

        self::assertSame('pending', $inviteRecord->status(new \DateTimeImmutable()));
    }

    public function testStatusIsExpiredOncePastExpiryWhenNotAccepted(): void
    {
        $inviteRecord = new InviteRecord('id-2', 'someone@example.com', new Company('Test Co'), null, new \DateTimeImmutable('-1 hour'));

        self::assertSame('expired', $inviteRecord->status(new \DateTimeImmutable()));
    }

    public function testStatusIsAcceptedEvenPastExpiry(): void
    {
        $inviteRecord = new InviteRecord('id-3', 'someone@example.com', new Company('Test Co'), null, new \DateTimeImmutable('-1 hour'));

        $inviteRecord->markAccepted();

        self::assertSame('accepted', $inviteRecord->status(new \DateTimeImmutable()));
    }

    public function testInvitedByIsNullForSelfRegistration(): void
    {
        $inviteRecord = new InviteRecord('id-4', 'someone@example.com', new Company('Test Co'), null, new \DateTimeImmutable('+1 hour'));

        self::assertNull($inviteRecord->getInvitedBy());
    }

    public function testInvitedByIsSetForAnAdminSentInvite(): void
    {
        $inviter = new User('inviter@example.com', 'hash', 'pub', 'priv', new Company('Test Co'), false, 'en', '');
        $inviteRecord = new InviteRecord('id-5', 'someone@example.com', new Company('Test Co'), $inviter, new \DateTimeImmutable('+1 hour'));

        self::assertSame($inviter, $inviteRecord->getInvitedBy());
    }

    public function testScrubEmailOverwritesTheEmailWithACollisionFreePlaceholder(): void
    {
        $inviteRecord = new InviteRecord('id-6', 'someone@example.com', new Company('Test Co'), null, new \DateTimeImmutable('+1 hour'));

        $inviteRecord->scrubEmail();

        self::assertNotSame('someone@example.com', $inviteRecord->getEmail());
        self::assertStringEndsWith('@deleted.invalid', $inviteRecord->getEmail());
    }
}
