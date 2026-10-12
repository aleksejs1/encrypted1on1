<?php

namespace App\Tests\Unit\Entity;

use App\Billing\Plan;
use App\Billing\SubscriptionOutcome;
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

    #[TestWith(['active'])]
    #[TestWith(['trialing'])]
    #[TestWith(['past_due'])]
    public function testAPayingSubscriptionMovesAFreeCompanyToPlus(string $status): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $periodEnd = new \DateTimeImmutable('2026-11-12 02:15:00');

        $outcome = $company->applySubscription($this->subscription($status, periodEndsAt: $periodEnd), $this->plan());

        self::assertSame(SubscriptionOutcome::Applied, $outcome);
        self::assertSame(Plan::PLUS, $company->getPlanTier());
        self::assertSame(1000, $company->getSeatLimit());
        self::assertSame($status, $company->getSubscriptionStatus());
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
    public function testAnyOtherStatusOfItsOwnSubscriptionMovesAPlusCompanyBackToFree(string $status): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $date = new \DateTimeImmutable('2026-11-12 02:15:00');
        $company->applySubscription($this->subscription('active', periodEndsAt: $date, cancelsAt: $date), $this->plan());

        $outcome = $company->applySubscription($this->subscription($status, periodEndsAt: $date, cancelsAt: $date), $this->plan());

        self::assertSame(SubscriptionOutcome::Applied, $outcome);
        self::assertSame(Plan::FREE, $company->getPlanTier());
        self::assertSame(100, $company->getSeatLimit());
        self::assertSame('canceled', $company->getSubscriptionStatus());
        // Kept: the next checkout reuses the customer, and a late event of this
        // subscription is still recognized as the company's own.
        self::assertSame('cus_1', $company->getStripeCustomerId());
        self::assertSame('sub_1', $company->getStripeSubscriptionId());
        // An ended subscription has no next payment and no cancellation ahead.
        self::assertNull($company->getSubscriptionPeriodEndsAt());
        self::assertNull($company->getSubscriptionCancelsAt());
    }

    public function testALaterEventReplacesThePeriodEnd(): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription($this->subscription('active', periodEndsAt: new \DateTimeImmutable('2026-11-12')), $this->plan());
        $renewed = new \DateTimeImmutable('2026-12-12');

        $company->applySubscription($this->subscription('active', periodEndsAt: $renewed), $this->plan());

        self::assertSame($renewed, $company->getSubscriptionPeriodEndsAt());
    }

    #[TestWith(['incomplete'])]
    #[TestWith(['incomplete_expired'])]
    #[TestWith(['canceled'])]
    public function testASubscriptionThatIsNotPayingAndNotItsOwnChangesNothing(string $status): void
    {
        $neverSubscribed = new Company('Never', seatLimit: 5);
        $paying = new Company('Paying', seatLimit: 100);
        $paying->applySubscription($this->subscription('active', id: 'sub_own'), $this->plan());

        self::assertSame(SubscriptionOutcome::IgnoredNotOurs, $neverSubscribed->applySubscription($this->subscription($status, id: 'sub_failed'), $this->plan()));
        self::assertSame(SubscriptionOutcome::IgnoredNotOurs, $paying->applySubscription($this->subscription($status, id: 'sub_old', customerId: 'cus_old'), $this->plan()));

        self::assertNull($neverSubscribed->getStripeSubscriptionId());
        self::assertNull($neverSubscribed->getStripeCustomerId());
        self::assertSame('active', $neverSubscribed->getSubscriptionStatus());
        self::assertSame(5, $neverSubscribed->getSeatLimit());
        self::assertSame(Plan::PLUS, $paying->getPlanTier());
        self::assertSame('sub_own', $paying->getStripeSubscriptionId());
        self::assertSame('cus_1', $paying->getStripeCustomerId());
        self::assertSame('active', $paying->getSubscriptionStatus());
    }

    public function testAPayingSubscriptionReplacesAnOwnOneThatEnded(): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription($this->subscription('active', id: 'sub_first'), $this->plan());
        $company->applySubscription($this->subscription('canceled', id: 'sub_first'), $this->plan());

        $outcome = $company->applySubscription($this->subscription('active', id: 'sub_second', customerId: 'cus_second'), $this->plan());

        self::assertSame(SubscriptionOutcome::Applied, $outcome);
        self::assertSame(Plan::PLUS, $company->getPlanTier());
        self::assertSame('sub_second', $company->getStripeSubscriptionId());
        self::assertSame('cus_second', $company->getStripeCustomerId());
    }

    #[TestWith(['active'])]
    #[TestWith(['past_due'])]
    public function testASecondPayingSubscriptionIsReportedAndChangesNothing(string $ownStatus): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription($this->subscription($ownStatus, id: 'sub_own'), $this->plan());

        $outcome = $company->applySubscription($this->subscription('active', id: 'sub_second', customerId: 'cus_second'), $this->plan());

        self::assertSame(SubscriptionOutcome::IgnoredSecondPaying, $outcome);
        self::assertSame('sub_own', $company->getStripeSubscriptionId());
        self::assertSame('cus_1', $company->getStripeCustomerId());
        self::assertSame($ownStatus, $company->getSubscriptionStatus());
    }

    public function testANonPayingEventLeavesAFreeCompanysOwnLimitAlone(): void
    {
        // A company from before the plans: Free with the old placeholder limit.
        $company = new Company('Acme', seatLimit: 5);
        $company->applySubscription($this->subscription('active'), $this->plan());
        $company->applySubscription($this->subscription('canceled'), $this->plan());
        $company->setSeatLimit(250);

        // Stripe reports the ended subscription once more.
        $company->applySubscription($this->subscription('canceled'), $this->plan());

        self::assertSame(Plan::FREE, $company->getPlanTier());
        self::assertSame(250, $company->getSeatLimit());
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

    #[TestWith(['active', Plan::PLUS, 1000])]
    #[TestWith(['canceled', Plan::FREE, 100])]
    public function testAHandSetSeatLimitIsReplacedWhenThePlanChanges(string $status, string $expectedPlan, int $expectedLimit): void
    {
        $company = new Company('Acme', seatLimit: 100);
        if (Plan::FREE === $expectedPlan) {
            $company->applySubscription($this->subscription('active'), $this->plan());
        }
        $company->setSeatLimit(2500);

        $company->applySubscription($this->subscription($status), $this->plan());

        self::assertSame($expectedPlan, $company->getPlanTier());
        self::assertSame($expectedLimit, $company->getSeatLimit());
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

    #[TestWith(['active', 'active'])]
    #[TestWith(['canceled', 'canceled'])]
    #[TestWith(['unpaid', 'canceled'])]
    public function testAPlanSetByHandAndItsLimitAreLeftAlone(string $status, string $storedStatus): void
    {
        $company = new Company('Acme', seatLimit: 5000, planTier: 'enterprise');
        $company->applySubscription($this->subscription('active'), $this->plan());

        $outcome = $company->applySubscription($this->subscription($status), $this->plan());

        self::assertSame(SubscriptionOutcome::Applied, $outcome);
        self::assertSame('enterprise', $company->getPlanTier());
        self::assertSame(5000, $company->getSeatLimit());
        // What Stripe says is still recorded.
        self::assertSame('sub_1', $company->getStripeSubscriptionId());
        self::assertSame($storedStatus, $company->getSubscriptionStatus());
    }

    #[TestWith(['active'])]
    #[TestWith(['past_due'])]
    #[TestWith(['canceled'])]
    public function testSuspensionIsNeverTouched(string $status): void
    {
        $suspended = new Company('Suspended', seatLimit: 100);
        $suspended->applySubscription($this->subscription('active'), $this->plan());
        $suspended->suspend();
        $suspendedAt = $suspended->getSuspendedAt();
        $open = new Company('Open', seatLimit: 100);
        $open->applySubscription($this->subscription('active'), $this->plan());

        $suspended->applySubscription($this->subscription($status), $this->plan());
        $open->applySubscription($this->subscription($status), $this->plan());

        self::assertSame($suspendedAt, $suspended->getSuspendedAt(), 'a platform admin\'s suspension is not lifted');
        self::assertFalse($open->isSuspended(), 'not paying does not suspend');
    }

    /**
     * Known and accepted (decision 11 of GitHub issue #286): the second subscription is
     * not remembered, so the company is on Free until that one's next event.
     */
    public function testTheOwnSubscriptionEndingAfterASecondOneWasIgnoredLeavesFree(): void
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription($this->subscription('past_due', id: 'sub_own'), $this->plan());
        $company->applySubscription($this->subscription('active', id: 'sub_second'), $this->plan());

        $company->applySubscription($this->subscription('canceled', id: 'sub_own'), $this->plan());
        self::assertSame(Plan::FREE, $company->getPlanTier());

        // The second one's next event (its renewal) is then taken as the company's own.
        self::assertSame(SubscriptionOutcome::Applied, $company->applySubscription($this->subscription('active', id: 'sub_second'), $this->plan()));
        self::assertSame(Plan::PLUS, $company->getPlanTier());
        self::assertSame('sub_second', $company->getStripeSubscriptionId());
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

    private function subscription(string $status, ?\DateTimeImmutable $periodEndsAt = null, ?\DateTimeImmutable $cancelsAt = null, string $id = 'sub_1', string $customerId = 'cus_1'): SubscriptionSnapshot
    {
        return new SubscriptionSnapshot($id, $customerId, $status, $periodEndsAt, $cancelsAt, null);
    }
}
