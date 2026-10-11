<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class SetUserManagerRequest
{
    private const UNSET = '__NOT_SET__';

    public function __construct(
        public mixed $managerId = self::UNSET,
    ) {
    }

    /** Null clears the manager, so a missing field must not read as null. */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (self::UNSET === $this->managerId || (!\is_null($this->managerId) && (!\is_string($this->managerId) || '' === $this->managerId))) {
            DtoViolation::add($context, 'managerId', 'errors.missing_or_invalid_field', ['%field%' => 'managerId']);
        }
    }
}
