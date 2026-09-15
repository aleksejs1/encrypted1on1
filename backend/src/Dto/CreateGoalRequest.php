<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class CreateGoalRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 36)]
        public string $goalUuid = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $title = '',

        #[Assert\Type('string')]
        public ?string $description = null,

        #[Assert\Type('string')]
        public ?string $targetDate = null,
    ) {
    }

    #[Assert\Callback]
    public function validateTargetDate(ExecutionContextInterface $context): void
    {
        if (null !== $this->targetDate && '' !== $this->targetDate) {
            try {
                new \DateTimeImmutable($this->targetDate);
            } catch (\Exception) {
                $context->buildViolation('Target date must be a valid date.')
                    ->atPath('targetDate')
                    ->addViolation();
            }
        }
    }
}
