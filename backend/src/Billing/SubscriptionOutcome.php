<?php

namespace App\Billing;

/** What became of a subscription's current state: Company::applySubscription()'s answer, or SubscriptionSync's when there was no company to ask. */
enum SubscriptionOutcome
{
    /** The company's own subscription, or one it now takes as its own: stored. */
    case Applied;

    /**
     * A subscription that isn't paying and isn't the company's own: one that never
     * started, or an old one reported late. Nothing changed.
     */
    case IgnoredNotOurs;

    /**
     * A second paying subscription while the company's own is paying too. Nothing
     * changed, and nothing remembers the second one: somebody has to cancel and refund
     * it, the one that is not stored, by hand. Cancelling the stored one instead leaves
     * the company on Free until the other's next event (its renewal).
     */
    case IgnoredSecondPaying;

    /** No company has this subscription, and nothing on it names one. */
    case NoCompany;
}
