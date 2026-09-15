<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class SetNotificationPreferencesRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public ?bool $meetingRemindersEnabled = null,
    ) {
    }
}
