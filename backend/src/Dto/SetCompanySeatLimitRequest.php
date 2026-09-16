<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class SetCompanySeatLimitRequest
{
    public function __construct(
        public mixed $seatLimit = '__NOT_SET__',
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('__NOT_SET__' === $this->seatLimit || (!\is_null($this->seatLimit) && (!\is_int($this->seatLimit) || $this->seatLimit < 1))) {
            DtoViolation::add($context, 'seatLimit', 'errors.missing_or_invalid_seat_limit');
        }
    }
}
