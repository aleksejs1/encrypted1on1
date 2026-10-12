<?php

namespace App\Billing;

/** What Company::applySubscription() did with a subscription's current state. */
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
     * changed; somebody has to cancel and refund one of them by hand.
     */
    case IgnoredSecondPaying;
}
