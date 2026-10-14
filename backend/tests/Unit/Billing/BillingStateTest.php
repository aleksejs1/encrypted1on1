<?php

namespace App\Tests\Unit\Billing;

use App\Billing\BillingState;
use App\Billing\Plan;
use App\Billing\SubscriptionSnapshot;
use App\Entity\Company;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class BillingStateTest extends TestCase
{
    public function testACompanyThatNeverPaidIsFree(): void
    {
        $company = new Company('Acme', seatLimit: 100);

        self::assertSame(BillingState::Free, BillingState::of($company, true));
        self::assertSame(BillingState::FreeUnavailable, BillingState::of($company, false));
    }

    #[TestWith(['active'])]
    #[TestWith(['trialing'])]
    public function testAPayingSubscriptionIsPlus(string $status): void
    {
        $company = $this->subscribed($status);

        self::assertSame(BillingState::Plus, BillingState::of($company, true));
    }

    /** Whether a new purchase could be made doesn't matter to a company that has one. */
    public function testPlusDoesNotDependOnPaymentBeingAvailable(): void
    {
        self::assertSame(BillingState::Plus, BillingState::of($this->subscribed('active'), false));
    }

    public function testAScheduledCancellationIsPlusEnding(): void
    {
        $company = $this->subscribed('active', cancelsAt: new \DateTimeImmutable('2026-11-11'));

        self::assertSame(BillingState::PlusEnding, BillingState::of($company, true));
    }

    public function testAFailedPaymentIsPastDue(): void
    {
        self::assertSame(BillingState::PlusPastDue, BillingState::of($this->subscribed('past_due'), true));
    }

    public function testAFailedPaymentComesBeforeAScheduledCancellation(): void
    {
        $company = $this->subscribed('past_due', cancelsAt: new \DateTimeImmutable('2026-11-11'));

        self::assertSame(BillingState::PlusPastDue, BillingState::of($company, true));
    }

    public function testAnEndedSubscriptionIsFreeAgain(): void
    {
        $company = $this->subscribed('active', cancelsAt: new \DateTimeImmutable('2026-11-11'));
        $company->applySubscription(new SubscriptionSnapshot('sub_1', 'cus_1', 'canceled', null, null, null), new Plan(100, 1000));

        self::assertSame(BillingState::Free, BillingState::of($company, true));
        self::assertSame(BillingState::FreeUnavailable, BillingState::of($company, false));
    }

    public function testAPlanSetByHandIsCustom(): void
    {
        $company = new Company('Acme', seatLimit: 5000, planTier: 'enterprise');

        self::assertSame(BillingState::Custom, BillingState::of($company, true));
        self::assertSame(BillingState::Custom, BillingState::of($company, false));
    }

    /** A paying subscription changes nothing about a plan set by hand, its state included. */
    public function testAPlanSetByHandStaysCustomWithAPayingSubscription(): void
    {
        $company = new Company('Acme', seatLimit: 5000, planTier: 'enterprise');
        $company->applySubscription(new SubscriptionSnapshot('sub_1', 'cus_1', 'past_due', null, null, null), new Plan(100, 1000));

        self::assertSame(BillingState::Custom, BillingState::of($company, true));
    }

    /** The rules don't produce it; a database edit can. `active` is also what a company that never paid has stored. */
    public function testPlusWithoutASubscriptionIsCustom(): void
    {
        $company = new Company('Acme', seatLimit: 1000, planTier: Plan::PLUS);

        self::assertSame('active', $company->getSubscriptionStatus());
        self::assertSame(BillingState::Custom, BillingState::of($company, true));
    }

    /** A database edit again: the checkout refuses a second subscription, so the page must not offer one. */
    public function testFreeWithAPayingSubscriptionIsCustom(): void
    {
        $company = $this->subscribed('active');
        new \ReflectionProperty(Company::class, 'planTier')->setValue($company, Plan::FREE);

        self::assertSame(BillingState::Custom, BillingState::of($company, true));
    }

    /** The checkout refuses such a company, so the page must not offer it Plus. */
    public function testFreeWithoutASeatLimitIsCustom(): void
    {
        $company = new Company('Acme');

        self::assertSame(BillingState::Custom, BillingState::of($company, true));
        self::assertSame(BillingState::Custom, BillingState::of($company, false));
    }

    /** A limit a platform admin lifted from a paying company leaves it a paying company. */
    public function testPlusWithoutASeatLimitIsStillPlus(): void
    {
        $company = $this->subscribed('active');
        $company->setSeatLimit(null);

        self::assertSame(BillingState::Plus, BillingState::of($company, true));
    }

    private function subscribed(string $status, ?\DateTimeImmutable $cancelsAt = null): Company
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription(new SubscriptionSnapshot('sub_1', 'cus_1', $status, new \DateTimeImmutable('2026-11-11'), $cancelsAt, null), new Plan(100, 1000));

        return $company;
    }
}
