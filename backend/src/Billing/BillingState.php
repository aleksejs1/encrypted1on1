<?php

namespace App\Billing;

use App\Entity\Company;

/**
 * What the Plan page shows for a company: one answer, decided here, so the page never
 * derives it from separate flags. Display only: no rule reads it.
 */
enum BillingState: string
{
    case Custom = 'custom';
    case PlusPastDue = 'plus-past-due';
    case PlusEnding = 'plus-ending';
    case Plus = 'plus';
    case FreeUnavailable = 'free-unavailable';
    case Free = 'free';

    /**
     * Checked top to bottom, the first match wins: a failed payment matters more than a
     * cancellation that is scheduled as well.
     *
     * Custom is every other company that can't buy Plus (Company::canBuyPlus()), so the
     * page offers a purchase exactly where the checkout accepts one: a plan set by hand,
     * `free` without a seat limit, and what the rules don't produce and a database edit
     * can, `plus` without a paying subscription or `free` with one.
     */
    public static function of(Company $company, bool $paymentAvailable): self
    {
        if (Plan::PLUS === $company->getPlanTier() && $company->hasPayingSubscription()) {
            return match (true) {
                'past_due' === $company->getSubscriptionStatus() => self::PlusPastDue,
                null !== $company->getSubscriptionCancelsAt() => self::PlusEnding,
                default => self::Plus,
            };
        }
        if (!$company->canBuyPlus()) {
            return self::Custom;
        }

        return $paymentAvailable ? self::Free : self::FreeUnavailable;
    }
}
