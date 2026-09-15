<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class SaveVersionedBlobRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('string')]
        public ?string $blob = null,

        #[Assert\NotNull]
        #[Assert\Type('int')]
        #[Assert\PositiveOrZero]
        public ?int $expectedVersion = null,
    ) {
    }
}
