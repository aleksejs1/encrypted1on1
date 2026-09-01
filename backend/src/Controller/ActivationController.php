<?php

namespace App\Controller;

use App\Entity\ActivationToken;
use App\Entity\User;
use App\Http\DisplayNameField;
use App\Http\RateLimitResponse;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.activation_complete')]
        private readonly RateLimiterFactory $activationCompleteLimiter,
    ) {
    }

    #[Route('/api/activation-tokens/{token}', name: 'activation_token_lookup', methods: ['GET'])]
    public function lookup(string $token): JsonResponse
    {
        $activationToken = $this->findUsableToken($token);
        if (null === $activationToken) {
            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_or_expired_activation_link')], 404);
        }

        return new JsonResponse(['email' => $activationToken->getEmail()]);
    }

    #[Route('/api/activation-tokens/{token}/complete', name: 'activation_token_complete', methods: ['POST'])]
    public function complete(string $token, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        // Token brute-forcing itself is already infeasible (256-bit random tokens,
        // see ActivationToken::issue()) — this is defense-in-depth against generic
        // automated abuse of account creation, not the primary defense.
        $limit = $this->activationCompleteLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        $activationToken = $this->findUsableToken($token);
        if (null === $activationToken) {
            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_or_expired_activation_link')], 404);
        }

        $body = $request->toArray();
        foreach (['authKey', 'publicKey', 'encryptedPrivateKey'] as $field) {
            if (!\is_string($body[$field] ?? null) || '' === $body[$field]) {
                return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => $field])], 400);
            }
        }

        // Optional: the frontend's currently-active UI locale (Phase 6h) at the moment of
        // activation, so this account starts with a sensible email language (Phase 6i)
        // instead of always English. Invalid/missing values fall back to English inside
        // the constructor itself — not worth a hard validation error for a preference field.
        $locale = $body['locale'] ?? 'en';

        $displayName = DisplayNameField::parse($body['displayName'] ?? '', $this->translator);
        if ($displayName instanceof JsonResponse) {
            return $displayName;
        }

        $user = new User(
            email: $activationToken->getEmail(),
            authHash: $body['authKey'],
            publicKey: $body['publicKey'],
            encryptedPrivateKey: $body['encryptedPrivateKey'],
            company: $activationToken->getCompany(),
            isAdmin: $activationToken->grantsAdmin(),
            locale: \is_string($locale) ? $locale : 'en',
            displayName: $displayName,
        );
        $activationToken->markUsed();

        $this->entityManager->persist($user);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // Two concurrent completions of the same token (e.g. a double-click, or a
            // retried request) can both pass findUsableToken()'s isUsable() check above
            // before either commits. The loser's flush hits User::$email's unique
            // constraint — treat that the same as completing an already-used token
            // sequentially would (see testATokenCannotBeCompletedTwice), not a 500.
            //
            // A failed flush leaves Doctrine's UnitOfWork closed for the rest of this
            // request (ORM behavior, not something we control) — returning immediately,
            // as below, is required; don't add EntityManager use after this catch block.
            //
            // Reported to Sentry explicitly (a no-op when SENTRY_DSN is unset, the
            // self-hosted default — config/packages/sentry.php) since returning a plain
            // 404 here, instead of letting the exception bubble up as a 500, would
            // otherwise make this race invisible even to deployments that do have
            // monitoring configured.
            \Sentry\captureException($exception);

            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_or_expired_activation_link')], 404);
        }

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
