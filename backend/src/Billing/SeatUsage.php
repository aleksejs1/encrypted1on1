<?php

namespace App\Billing;

/**
 * How many of a company's seats are taken, as SeatLimitChecker counts them: the numbers
 * the limit is enforced against, so a page that shows them can't disagree with a
 * refused invitation.
 */
final readonly class SeatUsage
{
    public function __construct(
        /** Accounts that aren't deleted; a blocked one still holds its seat. */
        public int $people,
        /** Invitations that can still be accepted. */
        public int $pendingInvites,
        /** Null = unlimited. */
        public ?int $limit,
    ) {
    }

    public function used(): int
    {
        return $this->people + $this->pendingInvites;
    }

    public function isFull(): bool
    {
        return null !== $this->limit && $this->used() >= $this->limit;
    }
}
