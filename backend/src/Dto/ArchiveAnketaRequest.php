<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class ArchiveAnketaRequest
{
    public function __construct(
        public bool $missed = false,
        public bool $skipNextMeeting = false,
        #[Assert\Type('string')]
        public ?string $nextMeetingDate = null,
        #[Assert\Type('string')]
        public ?string $outcomesBlob = null,
        #[Assert\Type('string')]
        public ?string $mySealedKey = null,
        #[Assert\Type('string')]
        public ?string $counterpartSealedKey = null,
    ) {
    }

    #[Assert\Callback]
    public function validateNextMeetingDate(ExecutionContextInterface $context): void
    {
        if (null !== $this->nextMeetingDate && '' !== $this->nextMeetingDate) {
            try {
                new \DateTimeImmutable($this->nextMeetingDate);
            } catch (\Exception) {
                $context->buildViolation('Next meeting date must be a valid date.')
                    ->atPath('nextMeetingDate')
                    ->addViolation();
            }
        }
    }
}
