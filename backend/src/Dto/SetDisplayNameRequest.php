<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class SetDisplayNameRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('string')]
        public mixed $displayName = null,
    ) {
    }
}
