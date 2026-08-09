<?php

namespace App\Controller;

use App\Entity\ActivationToken;
use App\Entity\User;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The activation flow: look up what email a token is for, then complete it
 * with client-generated crypto material. See bin/console app:create-activation-link
 * for how tokens get created (the only way, for now — see the Phase 4 plan).
 */
class ActivationController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
    ) {
    }

    #[Route('/api/activation-tokens/{token}', name: 'activation_token_lookup', methods: ['GET'])]
    public function lookup(string $token): JsonResponse
    {
        $activationToken = $this->findUsableToken($token);
        if (null === $activationToken) {
            return new JsonResponse(['error' => 'Invalid or expired activation link.'], 404);
        }

        return new JsonResponse(['email' => $activationToken->getEmail()]);
    }

    #[Route('/api/activation-tokens/{token}/complete', name: 'activation_token_complete', methods: ['POST'])]
    public function complete(string $token, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        $activationToken = $this->findUsableToken($token);
        if (null === $activationToken) {
            return new JsonResponse(['error' => 'Invalid or expired activation link.'], 404);
        }

        $body = $request->toArray();
        foreach (['authKey', 'publicKey', 'encryptedPrivateKey'] as $field) {
            if (empty($body[$field]) || !\is_string($body[$field])) {
                return new JsonResponse(['error' => sprintf('Missing or invalid "%s".', $field)], 400);
            }
        }

        // Optional: the frontend's currently-active UI locale (Phase 6h) at the moment of
        // activation, so this account starts with a sensible email language (Phase 6i)
        // instead of always English. Invalid/missing values fall back to English inside
        // the constructor itself — not worth a hard validation error for a preference field.
        $locale = $body['locale'] ?? 'en';

        $user = new User(
            email: $activationToken->getEmail(),
            authHash: $body['authKey'],
            publicKey: $body['publicKey'],
            encryptedPrivateKey: $body['encryptedPrivateKey'],
            isAdmin: $activationToken->grantsAdmin(),
            locale: \is_string($locale) ? $locale : 'en',
        );
        $activationToken->markUsed();

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->authSession->logIn($request, $user);

        return new JsonResponse(['id' => $user->getId(), 'email' => $user->getEmail(), 'isAdmin' => $user->isAdmin()]);
    }

    private function findUsableToken(string $token): ?ActivationToken
    {
        $tokenHash = hash('sha256', $token);
        $activationToken = $this->entityManager->getRepository(ActivationToken::class)
            ->findOneBy(['tokenHash' => $tokenHash]);

        if (null === $activationToken || !$activationToken->isUsable()) {
            return null;
        }

        return $activationToken;
    }
}
