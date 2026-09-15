<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class SaveBlobRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('string')]
        public ?string $blob = null,
    ) {
    }
}
