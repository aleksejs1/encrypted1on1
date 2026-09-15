<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class ChangePasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $currentAuthKey = '',

        #[Assert\NotBlank]
        public string $newAuthKey = '',

        #[Assert\NotBlank]
        public string $newEncryptedPrivateKey = '',
    ) {
    }
}
