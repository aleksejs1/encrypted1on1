<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class CsrfTokenController
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    #[Route('/api/csrf-token', name: 'csrf_token', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['token' => $this->csrfTokenManager->getToken('api')->getValue()]);
    }
}
