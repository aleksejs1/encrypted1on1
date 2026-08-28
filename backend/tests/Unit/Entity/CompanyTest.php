<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use PHPUnit\Framework\TestCase;

class CompanyTest extends TestCase
{
    public function testConstructorDefaultsToInviteModeAndUnrestrictedDomain(): void
    {
        $company = new Company('Acme');

        self::assertSame('Acme', $company->getName());
        self::assertSame('invite', $company->getRegistrationMode());
        self::assertSame('', $company->getAllowedEmailDomain());
    }

    public function testConstructorAcceptsAnyValidRegistrationMode(): void
    {
        foreach (Company::REGISTRATION_MODES as $mode) {
            $company = new Company('Acme', $mode);
            self::assertSame($mode, $company->getRegistrationMode());
        }
    }

    public function testConstructorRejectsAnUnsupportedRegistrationMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Company('Acme', 'bogus-mode');
    }

    public function testConstructorAcceptsAnAllowedEmailDomain(): void
    {
        $company = new Company('Acme', 'domain', 'acme.example');

        self::assertSame('acme.example', $company->getAllowedEmailDomain());
    }

    public function testConstructorDefaultsToUnlimitedSeatsAndFreePlan(): void
    {
        $company = new Company('Acme');

        self::assertNull($company->getSeatLimit());
        self::assertSame('free', $company->getPlanTier());
        self::assertSame('active', $company->getSubscriptionStatus());
        self::assertFalse($company->isSuspended());
    }

    public function testConstructorAcceptsAnExplicitSeatLimitAndPlanTier(): void
    {
        $company = new Company('Acme', seatLimit: 5, planTier: 'starter');

        self::assertSame(5, $company->getSeatLimit());
        self::assertSame('starter', $company->getPlanTier());
    }

    public function testConstructorRejectsANonPositiveSeatLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Company('Acme', seatLimit: 0);
    }

    public function testSetSeatLimitRoundTrips(): void
    {
        $company = new Company('Acme', seatLimit: 5);

        $company->setSeatLimit(10);
        self::assertSame(10, $company->getSeatLimit());

        $company->setSeatLimit(null);
        self::assertNull($company->getSeatLimit());
    }

    public function testSetSeatLimitRejectsZeroOrNegative(): void
    {
        $company = new Company('Acme');

        $this->expectException(\InvalidArgumentException::class);
        $company->setSeatLimit(0);
    }

    public function testSuspendAndUnsuspendToggleIsSuspended(): void
    {
        $company = new Company('Acme');

        $company->suspend();
        self::assertTrue($company->isSuspended());
        self::assertNotNull($company->getSuspendedAt());

        $company->unsuspend();
        self::assertFalse($company->isSuspended());
        self::assertNull($company->getSuspendedAt());
    }

    public function testApplyStripeSubscriptionUpdateSuspendsOnPastDueAndCanceled(): void
    {
        foreach (['past_due', 'canceled'] as $status) {
            $company = new Company('Acme');
            $company->applyStripeSubscriptionUpdate($status, 'cus_123', 'sub_123');

            self::assertSame($status, $company->getSubscriptionStatus());
            self::assertTrue($company->isSuspended(), "expected suspension for status {$status}");
            self::assertSame('cus_123', $company->getStripeCustomerId());
            self::assertSame('sub_123', $company->getStripeSubscriptionId());
        }
    }

    public function testApplyStripeSubscriptionUpdateUnsuspendsOnActiveOrTrialing(): void
    {
        foreach (['active', 'trialing'] as $status) {
            $company = new Company('Acme');
            $company->suspend();

            $company->applyStripeSubscriptionUpdate($status, null, null);

            self::assertSame($status, $company->getSubscriptionStatus());
            self::assertFalse($company->isSuspended(), "expected no suspension for status {$status}");
        }
    }

    public function testApplyStripeSubscriptionUpdateRejectsAnUnknownStatus(): void
    {
        $company = new Company('Acme');

        $this->expectException(\InvalidArgumentException::class);
        $company->applyStripeSubscriptionUpdate('bogus-status', null, null);
    }
}
