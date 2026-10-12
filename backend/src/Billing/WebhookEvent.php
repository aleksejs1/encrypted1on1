<?php

namespace App\Billing;

/**
 * An already-verified Stripe webhook event, reduced to what BillingController takes from
 * it: which subscription it is about. Nothing else in an event is trusted to be current
 * (it may arrive late or out of order), so the subscription's state is read from Stripe
 * afterwards (SubscriptionSync).
 */
final readonly class WebhookEvent
{
    public function __construct(
        public string $type,
        /** Null for an event type this app doesn't act on: acknowledged and nothing done. */
        public ?string $subscriptionId,
        /**
         * The company a checkout was started for (the session's `client_reference_id`),
         * on `checkout.session.completed` only. The last resort for finding the company.
         */
        public ?string $checkoutCompanyId = null,
    ) {
    }
}
