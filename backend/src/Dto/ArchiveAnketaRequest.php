<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class ArchiveAnketaRequest
{
    public function __construct(
        // Nullable, not bool: the old manual parsing treated an explicit JSON `null`
        // the same as an absent key (`$body['missed'] ?? false`) — a non-nullable
        // bool would reject that same request with a 400 instead of defaulting it.
        public ?bool $missed = false,
        public ?bool $skipNextMeeting = false,
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
        // Only relevant when a next meeting is actually being created — the old
        // controller only ever parsed this field under the same condition, so a
        // stray/garbage value alongside skipNextMeeting: true was always ignored.
        if (true === $this->skipNextMeeting) {
            return;
        }

        if (null !== $this->nextMeetingDate && '' !== $this->nextMeetingDate) {
            try {
                new \DateTimeImmutable($this->nextMeetingDate);
            } catch (\Exception) {
                DtoViolation::add($context, 'nextMeetingDate', 'errors.next_meeting_date_must_be_valid_date');
            }
        }
    }
}
