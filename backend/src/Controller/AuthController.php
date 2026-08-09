<?php

namespace App\Controller;

use App\Entity\User;
use App\Http\RateLimitResponse;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class AuthController
{
    /** Same length as a real base64-encoded 32-byte authHash — see login() for why. */
    private const DUMMY_AUTH_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
        private readonly TranslatorInterface $translator,
        private readonly string $registrationMode,
        #[Autowire(service: 'limiter.login')]
        private readonly RateLimiterFactory $loginLimiter,
    ) {
    }

    #[Route('/api/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        // Keyed by IP, not email — the point is slowing down automated guessing
        // from one source, not punishing a specific account for someone else's
        // attempts against it.
        $limit = $this->loginLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        $body = $request->toArray();
        $email = $body['email'] ?? null;
        $authKey = $body['authKey'] ?? null;
        if (!\is_string($email) || !\is_string($authKey)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_email_or_auth_key')], 400);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        // Always compare against something, even when the email doesn't exist, so a
        // nonexistent-email response isn't distinguishable by timing from a wrong password.
        $expectedHash = $user?->getAuthHash() ?? self::DUMMY_AUTH_HASH;
        $isValid = hash_equals($expectedHash, $authKey) && null !== $user;

        if (!$isValid) {
            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_email_or_password')], 401);
        }

        // Distinct from the 401 above: this only triggers after a *correct* password is
        // already proven, so there's no enumeration concern in a clearer message here.
        if ($user->isBlocked()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.account_blocked')], 403);
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
            return new JsonResponse(['error' => $this->translator->trans('errors.not_authenticated')], 401);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'isAdmin' => $user->isAdmin(),
            // So a page refresh can re-derive the unwrapped private key from the
            // sessionStorage master-key without a full re-login — see identity.ts.
            'publicKey' => $user->getPublicKey(),
            'encryptedPrivateKey' => $user->getEncryptedPrivateKey(),
            // Every authenticated user needs this to decide whether to show the general
            // "Invite" UI (Phase 6g) — not admin-only information.
            'registrationMode' => $this->registrationMode,
        ]);
    }

    /**
     * Background sync target for the frontend's language switcher (Phase 6h/6i) — updates
     * which language *emails* to this user are sent in. Deliberately doesn't affect what
     * the UI displays (that stays a client-only preference, see the Phase 6i plan).
     */
    #[Route('/api/me/locale', name: 'me_set_locale', methods: ['PUT'])]
    public function setLocale(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            return new JsonResponse(['error' => $this->translator->trans('errors.not_authenticated')], 401);
        }

        $locale = $request->toArray()['locale'] ?? null;
        if (!\is_string($locale) || !\in_array($locale, User::SUPPORTED_LOCALES, true)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.locale_must_be_one_of', ['%locales%' => implode(', ', User::SUPPORTED_LOCALES)])], 400);
        }

        $user->setLocale($locale);
        $this->entityManager->flush();

        return new JsonResponse(['locale' => $user->getLocale()]);
    }
}
