<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class RescheduleAnketaRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $meetingDate = '',
    ) {
    }

    #[Assert\Callback]
    public function validateMeetingDate(ExecutionContextInterface $context): void
    {
        if ('' !== $this->meetingDate) {
            try {
                new \DateTimeImmutable($this->meetingDate);
            } catch (\Exception) {
                DtoViolation::add($context, 'meetingDate', 'errors.meeting_date_must_be_valid_date');
            }
        }
    }
}
