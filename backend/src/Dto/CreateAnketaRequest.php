<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class CreateAnketaRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $counterpartId = '',

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['employee', 'manager'])]
        public string $myRole = '',

        #[Assert\NotBlank]
        public string $meetingDate = '',

        #[Assert\NotBlank]
        public string $mySealedKey = '',

        #[Assert\NotBlank]
        public string $counterpartSealedKey = '',

        #[Assert\Positive]
        public ?int $periodicityDays = null,

        #[Assert\Type('string')]
        public ?string $outcomesBlob = null,
    ) {
    }

    #[Assert\Callback]
    public function validateMeetingDate(ExecutionContextInterface $context): void
    {
        if ('' !== $this->meetingDate) {
            try {
                new \DateTimeImmutable($this->meetingDate);
            } catch (\Exception) {
                $context->buildViolation('Meeting date must be a valid date.')
                    ->atPath('meetingDate')
                    ->addViolation();
            }
        }
    }
}
