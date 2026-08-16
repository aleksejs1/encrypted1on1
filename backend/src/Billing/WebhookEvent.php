<?php

namespace App\Billing;

/**
 * A normalized, already-verified Stripe webhook event — everything BillingController
 * needs, nothing it has to know about Stripe's own object shapes for. $subscriptionStatus
 * is already mapped to Company::SUBSCRIPTION_STATUSES; null on event types this app
 * doesn't act on (BillingController just acknowledges those with 200 and does nothing).
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $companyId,
        public readonly ?string $stripeCustomerId,
        public readonly ?string $stripeSubscriptionId,
        public readonly ?string $subscriptionStatus,
    ) {
    }
}
