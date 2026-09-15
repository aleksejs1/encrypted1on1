<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateCompanyRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        public string $name = '',

        #[Assert\NotBlank]
        #[Assert\Email]
        public string $adminEmail = '',
    ) {
    }
}
