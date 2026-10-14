<?php

namespace App\Billing;

/** What Plus costs, as Stripe has it: the price Checkout charges, reduced to what the Plan page shows. */
final readonly class PlusPrice
{
    public function __construct(
        /** In the currency's minor units, which are not hundredths for every currency. */
        public int $amount,
        /** Lowercase ISO code, as Stripe writes it. */
        public string $currency,
        /** Stripe's word for the billing period: day, week, month or year. One of them, never several. */
        public string $interval,
        /** Stripe's word: inclusive, exclusive or unspecified. */
        public string $taxBehavior,
    ) {
    }
}
