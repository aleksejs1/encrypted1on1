<?php

namespace App\Billing;

/**
 * A subscription as Stripe reports it right now, reduced to what this app reads. No
 * logic: what a status means for a company's plan is decided elsewhere.
 */
final readonly class SubscriptionSnapshot
{
    public function __construct(
        public string $id,
        public ?string $customerId,
        /** Stripe's own status word, unmapped: active, past_due, canceled, unpaid, ... */
        public string $status,
        /**
         * The end of the current period. For an unpaid (past_due) subscription this is
         * the end of a period nobody paid for yet, not a "next payment" date.
         */
        public ?\DateTimeImmutable $periodEndsAt,
        /**
         * When a scheduled cancellation takes effect. Stripe's `cancel_at`, which is
         * set for a cancellation at period end too; `cancel_at_period_end` is not read,
         * a cancellation made in the customer portal leaves it false.
         */
        public ?\DateTimeImmutable $cancelsAt,
        /**
         * The company the checkout tagged the subscription with (`metadata.company_id`).
         * Null for a subscription from before checkouts wrote the tag.
         */
        public ?string $companyId,
    ) {
    }
}
