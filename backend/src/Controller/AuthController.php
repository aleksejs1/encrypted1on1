<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AuthController
{
    /** Same length as a real base64-encoded 32-byte authHash — see login() for why. */
    private const DUMMY_AUTH_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
    ) {
    }

    #[Route('/api/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        $body = $request->toArray();
        $email = $body['email'] ?? null;
        $authKey = $body['authKey'] ?? null;
        if (!\is_string($email) || !\is_string($authKey)) {
            return new JsonResponse(['error' => 'Missing "email" or "authKey".'], 400);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        // Always compare against something, even when the email doesn't exist, so a
        // nonexistent-email response isn't distinguishable by timing from a wrong password.
        $expectedHash = $user?->getAuthHash() ?? self::DUMMY_AUTH_HASH;
        $isValid = hash_equals($expectedHash, $authKey) && null !== $user;

        if (!$isValid) {
            return new JsonResponse(['error' => 'Invalid email or password.'], 401);
        }

        $this->authSession->logIn($request, $user);

        return new JsonResponse([
            'publicKey' => $user->getPublicKey(),
            'encryptedPrivateKey' => $user->getEncryptedPrivateKey(),
        ]);
    }

    #[Route('/api/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $this->authSession->logOut($request);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }

        return new JsonResponse(['id' => $user->getId(), 'email' => $user->getEmail(), 'isAdmin' => $user->isAdmin()]);
    }
}
