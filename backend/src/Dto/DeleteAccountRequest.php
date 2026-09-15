<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class DeleteAccountRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $currentAuthKey = '',
    ) {
    }
}
