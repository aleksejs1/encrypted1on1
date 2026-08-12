<?php

namespace App\Controller;

use App\Entity\Anketa;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Http\RateLimitResponse;
use App\Notification\PasswordResetNotifier;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mirrors ActivationController's shape closely — same one-time-token flow —
 * but completes against an *existing* user (User::resetCredentials()) rather
 * than creating one. The actual re-sharing mechanism (a counterpart re-sealing
 * an anketa's key to this user's new public key) lives in
 * AnketaController::reshareKey() instead — it's anketa-scoped, not
 * password-reset-scoped — but the "your counterpart's key changed" email that
 * tells them to go do that fires from right here, at the moment the key
 * actually changes.
 */
class PasswordResetController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
        private readonly PasswordResetNotifier $notifier,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.password_reset_request')]
        private readonly RateLimiterFactory $requestLimiter,
        #[Autowire(service: 'limiter.password_reset_complete')]
        private readonly RateLimiterFactory $completeLimiter,
    ) {
    }

    #[Route('/api/password-reset', name: 'password_reset_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        // Keyed by IP — there's no authenticated actor here, unlike invite's per-user limit.
        $limit = $this->requestLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        $email = $request->toArray()['email'] ?? null;
        if (!\is_string($email) || '' === $email) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_email')], 400);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        // Same enumeration-avoidance discipline as login's dummy-hash comparison: the
        // response never reveals whether the email has an account, or is blocked.
        if (null !== $user && !$user->isBlocked()) {
            [$token, $rawToken] = PasswordResetToken::issue($email);
            $this->entityManager->persist($token);
            $this->entityManager->flush();

            $this->notifier->notifyPasswordResetRequested($user, $rawToken);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/password-reset-tokens/{token}', name: 'password_reset_token_lookup', methods: ['GET'])]
    public function lookup(string $token): JsonResponse
    {
        $resetToken = $this->findUsableToken($token);
        if (null === $resetToken) {
            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_or_expired_reset_link')], 404);
        }

        return new JsonResponse(['email' => $resetToken->getEmail()]);
    }

    #[Route('/api/password-reset-tokens/{token}/complete', name: 'password_reset_token_complete', methods: ['POST'])]
    public function complete(string $token, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        // Token brute-forcing itself is already infeasible (256-bit random tokens,
        // see PasswordResetToken::issue()) — defense-in-depth against generic abuse,
        // same reasoning as ActivationController::complete().
        $limit = $this->completeLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        $resetToken = $this->findUsableToken($token);
        if (null === $resetToken) {
            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_or_expired_reset_link')], 404);
        }

        $body = $request->toArray();
        foreach (['authKey', 'publicKey', 'encryptedPrivateKey'] as $field) {
            if (empty($body[$field]) || !\is_string($body[$field])) {
                return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => $field])], 400);
            }
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $resetToken->getEmail()]);
        if (null === $user) {
            // The account was deleted between requesting and completing the reset.
            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_or_expired_reset_link')], 404);
        }
        // Re-checked here too, not just at request time — a token requested before a
        // block shouldn't let someone regain access after being blocked in between.
        if ($user->isBlocked()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.account_blocked')], 403);
        }

        $user->resetCredentials($body['authKey'], $body['publicKey'], $body['encryptedPrivateKey']);
        $resetToken->markUsed();
        $this->entityManager->flush();

        $this->authSession->logIn($request, $user);

        // Best-effort (see PasswordResetNotifier) — never blocks the response on a mail
        // failure, same as every other notification fired from a successful request in
        // this app.
        foreach ($this->findCounterparts($user) as $counterpart) {
            $this->notifier->notifyCounterpartKeyChanged($counterpart, $user);
        }

        return new JsonResponse(['id' => $user->getId(), 'email' => $user->getEmail(), 'isAdmin' => $user->isAdmin()]);
    }

    private function findUsableToken(string $token): ?PasswordResetToken
    {
        $tokenHash = hash('sha256', $token);
        $resetToken = $this->entityManager->getRepository(PasswordResetToken::class)
            ->findOneBy(['tokenHash' => $tokenHash]);

        if (null === $resetToken || !$resetToken->isUsable()) {
            return null;
        }

        return $resetToken;
    }

    /**
     * Every distinct user $target shares at least one anketa with, deduped by id —
     * same "unordered pair" query shape AnketaController already uses elsewhere, just
     * collecting the *other* side across every anketa rather than looking for one
     * specific pair.
     *
     * @return User[]
     */
    private function findCounterparts(User $target): array
    {
        /** @var Anketa[] $anketas */
        $anketas = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Anketa::class, 'a')
            ->where('a.employee = :user OR a.manager = :user')
            ->setParameter('user', $target)
            ->getQuery()
            ->getResult();

        $counterparts = [];
        foreach ($anketas as $anketa) {
            $counterpart = $anketa->isEmployee($target) ? $anketa->getManager() : $anketa->getEmployee();
            $counterparts[$counterpart->getId()] = $counterpart;
        }

        return array_values($counterparts);
    }
}
