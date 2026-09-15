<?php

namespace App\Dto;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

readonly class SetLocaleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: User::SUPPORTED_LOCALES)]
        public string $locale = '',
    ) {
    }
}
