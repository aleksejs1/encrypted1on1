<?php

namespace App\Billing;

/**
 * The plans of the Cloud deployment and how many seats each gives. The one place these
 * names and numbers live.
 *
 * `free` and `plus` are the plans a payment moves a company between. Any other value of
 * Company::$planTier is a plan somebody set by hand (a custom agreement), which payment
 * events must leave alone.
 *
 * The seat limits are environment variables, not constants: another operator may run
 * cloud mode with their own numbers, as they set their own price in Stripe.
 * DEFAULT_SEAT_LIMIT is the Free limit; its name predates the plans. A self-hosted
 * deployment's one company has no seat limit and never gets one from here, but both
 * values must still be valid there: the service is built wherever it is injected.
 */
class Plan
{
    public const string FREE = 'free';
    public const string PLUS = 'plus';

    public function __construct(
        private readonly int $defaultSeatLimit,
        private readonly int $plusSeatLimit,
    ) {
        if ($defaultSeatLimit < 1 || $plusSeatLimit < 1) {
            throw new \InvalidArgumentException('DEFAULT_SEAT_LIMIT and PLUS_SEAT_LIMIT must be positive numbers.');
        }
    }

    public static function isManagedByPayment(string $plan): bool
    {
        return self::FREE === $plan || self::PLUS === $plan;
    }

    /** The seat limit of `free` or `plus`; a plan set by hand has no limit of its own to look up. */
    public function seatLimit(string $plan): int
    {
        return match ($plan) {
            self::FREE => $this->defaultSeatLimit,
            self::PLUS => $this->plusSeatLimit,
            default => throw new \InvalidArgumentException(\sprintf('"%s" is not a plan with a seat limit.', $plan)),
        };
    }
}
