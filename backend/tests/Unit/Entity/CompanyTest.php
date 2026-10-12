<?php

namespace App\Tests\Unit\Entity;

use App\Billing\Plan;
use App\Billing\SubscriptionSnapshot;
use App\Entity\Company;
use PHPUnit\Framework\Attributes\TestWith;
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
        self::assertNull($company->getSubscriptionPeriodEndsAt());
        self::assertNull($company->getSubscriptionCancelsAt());
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

    #[TestWith(['active', 'active'])]
    #[TestWith(['trialing', 'trialing'])]
    #[TestWith(['past_due', 'past_due'])]
    public function testAPayingSubscriptionMovesAFreeCompanyToPlus(string $stripeStatus, string $storedStatus): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $periodEnd = new \DateTimeImmutable('2026-11-12 02:15:00');

        $company->applySubscription($this->subscription($stripeStatus, periodEndsAt: $periodEnd), $this->plan());

        self::assertSame(Plan::PLUS, $company->getPlanTier());
        self::assertSame(1000, $company->getSeatLimit());
        self::assertSame($storedStatus, $company->getSubscriptionStatus());
        self::assertSame('sub_1', $company->getStripeSubscriptionId());
        self::assertSame('cus_1', $company->getStripeCustomerId());
        self::assertSame($periodEnd, $company->getSubscriptionPeriodEndsAt());
        self::assertNull($company->getSubscriptionCancelsAt());
    }

    #[TestWith(['canceled'])]
    #[TestWith(['unpaid'])]
    #[TestWith(['incomplete'])]
    #[TestWith(['incomplete_expired'])]
    #[TestWith(['paused'])]
    #[TestWith(['a-status-stripe-adds-later'])]
    public function testAnyOtherStatusMovesAPlusCompanyBackToFree(string $stripeStatus): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription($this->subscription('active'), $this->plan());

        $company->applySubscription($this->subscription($stripeStatus), $this->plan());

        self::assertSame(Plan::FREE, $company->getPlanTier());
        self::assertSame(100, $company->getSeatLimit());
        self::assertSame('canceled', $company->getSubscriptionStatus());
        // Kept: the next checkout reuses the customer.
        self::assertSame('cus_1', $company->getStripeCustomerId());
        self::assertSame('sub_1', $company->getStripeSubscriptionId());
    }

    public function testANonPayingSubscriptionLeavesAFreeCompanyOnFree(): void
    {
        $company = new Company('Acme', seatLimit: 100);

        $company->applySubscription($this->subscription('incomplete'), $this->plan());

        self::assertSame(Plan::FREE, $company->getPlanTier());
        self::assertSame(100, $company->getSeatLimit());
        self::assertSame('canceled', $company->getSubscriptionStatus());
    }

    public function testAScheduledCancellationIsStoredAndClearedAgain(): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $cancelsAt = new \DateTimeImmutable('2026-11-12 02:15:00');

        $company->applySubscription($this->subscription('active', cancelsAt: $cancelsAt), $this->plan());
        self::assertSame($cancelsAt, $company->getSubscriptionCancelsAt());
        self::assertSame(Plan::PLUS, $company->getPlanTier());

        // "Don't cancel subscription" in the portal.
        $company->applySubscription($this->subscription('active'), $this->plan());
        self::assertNull($company->getSubscriptionCancelsAt());
    }

    public function testAHandSetSeatLimitSurvivesAnEventThatKeepsThePlan(): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription($this->subscription('active'), $this->plan());
        $company->setSeatLimit(2500);

        $company->applySubscription($this->subscription('past_due'), $this->plan());
        $company->applySubscription($this->subscription('active'), $this->plan());

        self::assertSame(Plan::PLUS, $company->getPlanTier());
        self::assertSame(2500, $company->getSeatLimit());
    }

    public function testAHandSetSeatLimitIsReplacedWhenThePlanChanges(): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription($this->subscription('active'), $this->plan());
        $company->setSeatLimit(2500);

        $company->applySubscription($this->subscription('canceled'), $this->plan());

        self::assertSame(Plan::FREE, $company->getPlanTier());
        self::assertSame(100, $company->getSeatLimit());
    }

    public function testACompanyWithoutASeatLimitNeverGetsOne(): void
    {
        $company = new Company('Acme');

        $company->applySubscription($this->subscription('active'), $this->plan());
        self::assertSame(Plan::PLUS, $company->getPlanTier());
        self::assertNull($company->getSeatLimit());

        $company->applySubscription($this->subscription('canceled'), $this->plan());
        self::assertSame(Plan::FREE, $company->getPlanTier());
        self::assertNull($company->getSeatLimit());
    }

    #[TestWith(['active'])]
    #[TestWith(['canceled'])]
    public function testAPlanSetByHandAndItsLimitAreLeftAlone(string $stripeStatus): void
    {
        $company = new Company('Acme', seatLimit: 5000, planTier: 'enterprise');

        $company->applySubscription($this->subscription($stripeStatus), $this->plan());

        self::assertSame('enterprise', $company->getPlanTier());
        self::assertSame(5000, $company->getSeatLimit());
        // What Stripe says is still recorded.
        self::assertSame('sub_1', $company->getStripeSubscriptionId());
        self::assertSame('active' === $stripeStatus ? 'active' : 'canceled', $company->getSubscriptionStatus());
    }

    #[TestWith(['active'])]
    #[TestWith(['past_due'])]
    #[TestWith(['canceled'])]
    public function testSuspensionIsNeverTouched(string $stripeStatus): void
    {
        $suspended = new Company('Suspended', seatLimit: 100);
        $suspended->suspend();
        $suspendedAt = $suspended->getSuspendedAt();
        $open = new Company('Open', seatLimit: 100);

        $suspended->applySubscription($this->subscription($stripeStatus), $this->plan());
        $open->applySubscription($this->subscription($stripeStatus), $this->plan());

        self::assertSame($suspendedAt, $suspended->getSuspendedAt(), 'a platform admin\'s suspension is not lifted');
        self::assertFalse($open->isSuspended(), 'not paying does not suspend');
    }

    public function testAStoredCustomerIdSurvivesASnapshotWithoutOne(): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription($this->subscription('active'), $this->plan());

        $company->applySubscription(new SubscriptionSnapshot('sub_1', null, 'active', null, null, null), $this->plan());

        self::assertSame('cus_1', $company->getStripeCustomerId());
    }

    private function plan(): Plan
    {
        return new Plan(defaultSeatLimit: 100, plusSeatLimit: 1000);
    }

    private function subscription(string $status, ?\DateTimeImmutable $periodEndsAt = null, ?\DateTimeImmutable $cancelsAt = null): SubscriptionSnapshot
    {
        return new SubscriptionSnapshot('sub_1', 'cus_1', $status, $periodEndsAt, $cancelsAt, null);
    }
}
