<?php

namespace App\Controller;

use App\Entity\ActivationToken;
use App\Entity\InviteRecord;
use App\Entity\User;
use App\Http\ActivationLinkState;
use App\Http\DisplayNameField;
use App\Http\RateLimitResponse;
use App\Invite\InviteRenewal;
use App\Notification\InvitationNotifier;
use App\Repository\ActivationTokenRepository;
use App\Repository\InviteRecordRepository;
use App\Security\AuthSession;
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
        private readonly TranslatorInterface $translator,
        private readonly ActivationTokenRepository $activationTokenRepository,
        private readonly InviteRecordRepository $inviteRecordRepository,
        private readonly InviteRenewal $inviteRenewal,
        private readonly InvitationNotifier $notifier,
        private readonly bool $cloudMode,
        #[Autowire(service: 'limiter.activation_complete')]
        private readonly RateLimiterFactory $activationCompleteLimiter,
        #[Autowire(service: 'limiter.invite_renewal_request')]
        private readonly RateLimiterFactory $inviteRenewalRequestLimiter,
    ) {
    }

    /**
     * 200 with the email for a usable link. Otherwise the link's state (GitHub issue
     * #169), so the activation page can say what happened and what to do next: 404
     * unknown, 409 already activated, 410 expired with a `renewal` hint (see
     * linkState()).
     */
    #[Route('/api/activation-tokens/{token}', name: 'activation_token_lookup', methods: ['GET'])]
    public function lookup(string $token): JsonResponse
    {
        $activationToken = $this->findToken($token);
        [$state] = $this->linkState($activationToken, new \DateTimeImmutable());
        if (ActivationLinkState::Usable === $state && null !== $activationToken) {
            return new JsonResponse(['email' => $activationToken->getEmail()]);
        }

        return $this->linkStateResponse($state);
    }

    /**
     * The invitee's "Request new invitation" button on an expired link (GitHub issue
     * #169): emails whoever can re-invite them, at most once per
     * InviteRecord::RENEWAL_COOLDOWN_HOURS. It never issues a new token itself; the
     * inviter re-sends the invite as usual.
     */
    #[Route('/api/activation-tokens/{token}/request-renewal', name: 'activation_token_request_renewal', methods: ['POST'])]
    public function requestRenewal(string $token, Request $request): JsonResponse
    {
        // Keyed by IP: the caller has no account. The per-address cooldown (InviteRenewal)
        // is what stops repeated emails to the inviter; this caps one client probing many
        // links, with headroom for an office of new hires behind one address.
        $limit = $this->inviteRenewalRequestLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        $now = new \DateTimeImmutable();
        [$state, $inviteRecord, $recipients] = $this->linkState($this->findToken($token), $now);
        // Anything but `available` (including `requested`, within the cooldown) answers
        // the same as lookup(), so the page just shows that state.
        if (ActivationLinkState::Available !== $state || null === $inviteRecord) {
            return $this->linkStateResponse($state);
        }

        // Not re-checked after the claim: an admin re-sending in the moment between
        // linkState() and here gets one superfluous request email, nothing worse. And a
        // concurrent request that lost the claim answers "requested" even if this one's
        // send then fails and releases it; that invitee can retry after a reload.
        $previous = $inviteRecord->getRenewalRequestedAt();
        if (!$this->inviteRecordRepository->claimRenewalRequest($inviteRecord->getId(), $now)) {
            // A concurrent request claimed it first.
            return $this->linkStateResponse(ActivationLinkState::Requested);
        }

        $sent = false;
        foreach ($recipients as $recipient) {
            $sent = $this->notifier->notifyInviteRenewalRequested($recipient, $inviteRecord->getEmail()) || $sent;
        }
        if (!$sent) {
            $this->inviteRecordRepository->releaseRenewalRequest($inviteRecord->getId(), $now, $previous);

            return new JsonResponse(['error' => $this->translator->trans('errors.invite_renewal_send_failed'), 'code' => 'send_failed'], 503);
        }

        return new JsonResponse(['renewal' => ActivationLinkState::Requested->value]);
    }

    #[Route('/api/activation-tokens/{token}/complete', name: 'activation_token_complete', methods: ['POST'])]
    public function complete(string $token, Request $request): JsonResponse
    {
        // Token brute-forcing itself is already infeasible (256-bit random tokens,
        // see ActivationToken::issue()) — this is defense-in-depth against generic
        // automated abuse of account creation, not the primary defense.
        $limit = $this->activationCompleteLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        $activationToken = $this->findToken($token);
        [$state] = $this->linkState($activationToken, new \DateTimeImmutable());
        if (ActivationLinkState::Usable !== $state || null === $activationToken) {
            // Same answer as lookup(), so a page loaded while the link still worked can
            // switch to the expired/already-activated state on submit (GitHub issue #169).
            return $this->linkStateResponse($state);
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

        // Not found is the expected common case, not an anomaly: only
        // InviteController/SignupController write an InviteRecord (see its own
        // docblock) — the CLI bootstrap and cloud self-service company-creation
        // completions have none, by design (GitHub issue #24's "Scope" section).
        $inviteRecord = $this->entityManager->find(InviteRecord::class, $activationToken->getId());
        $inviteRecord?->markAccepted();

        $this->entityManager->persist($user);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // Two concurrent completions (of the same token, e.g. a double-click or a
            // retried request, or of two tokens to the same address) can both pass the
            // linkState() check above before either commits. The loser's flush hits User::$email's unique
            // constraint — treat that the same as completing an already-used token
            // sequentially would (see testATokenCannotBeCompletedTwice): already activated,
            // not a 500.
            //
            // A failed flush leaves Doctrine's UnitOfWork closed for the rest of this
            // request (ORM behavior, not something we control) — returning immediately,
            // as below, is required; don't add EntityManager use after this catch block.
            //
            // Reported to Sentry explicitly (a no-op when SENTRY_DSN is unset, the
            // self-hosted default — config/packages/sentry.php) since returning a 409
            // here, instead of letting the exception bubble up as a 500, would
            // otherwise make this race invisible even to deployments that do have
            // monitoring configured.
            \Sentry\captureException($exception);

            return $this->linkStateResponse(ActivationLinkState::AlreadyActivated);
        }

        $this->authSession->logIn($request, $user);

        return new JsonResponse(['id' => $user->getId(), 'email' => $user->getEmail(), 'isAdmin' => $user->isAdmin()]);
    }

    private function findToken(string $token): ?ActivationToken
    {
        return $this->activationTokenRepository->findOneBy(['tokenHash' => hash('sha256', $token)]);
    }

    /**
     * What `$activationToken` means for the person holding it, at `$now` (see
     * ActivationLinkState for each state). An account for the address (checked once,
     * here) wins over the link still being usable; an expired link is then whatever
     * InviteRenewal::stateFor() says about its address.
     *
     * @return array{0: ActivationLinkState, 1: InviteRecord|null, 2: list<User>} the
     *                                                                            state, and for Requested/Available the invite to record the request on and who to ask
     */
    private function linkState(?ActivationToken $activationToken, \DateTimeImmutable $now): array
    {
        if (null === $activationToken) {
            return [ActivationLinkState::Invalid, null, []];
        }
        if ($activationToken->isUsed() || $this->inviteRenewal->hasAccount($activationToken->getEmail())) {
            return [ActivationLinkState::AlreadyActivated, null, []];
        }
        if ($activationToken->isUsable($now)) {
            return [ActivationLinkState::Usable, null, []];
        }

        // Only the CLI bootstrap and cloud company creation issue a token with no invite
        // record; on Cloud, an admin link is almost always a company creation (the page
        // words it so it still fits a CLI-bootstrapped first admin).
        $withoutInvite = $this->cloudMode && $activationToken->grantsAdmin() ? ActivationLinkState::CreateCompany : ActivationLinkState::None;

        return $this->inviteRenewal->stateFor($activationToken->getEmail(), $activationToken->getCompany(), $now, $withoutInvite);
    }

    private function linkStateResponse(ActivationLinkState $state): JsonResponse
    {
        return match ($state) {
            ActivationLinkState::Invalid => new JsonResponse(['error' => $this->translator->trans('errors.invalid_or_expired_activation_link'), 'code' => 'invalid_token'], 404),
            ActivationLinkState::AlreadyActivated => new JsonResponse(['error' => $this->translator->trans('errors.account_already_active'), 'code' => $state->value], 409),
            // Only requestRenewal() can get here: a usable link needs no renewal.
            ActivationLinkState::Usable => new JsonResponse(['error' => $this->translator->trans('errors.activation_link_not_expired'), 'code' => 'not_expired'], 409),
            ActivationLinkState::Reissued,
            ActivationLinkState::Signup,
            ActivationLinkState::CreateCompany,
            ActivationLinkState::None,
            ActivationLinkState::Requested,
            ActivationLinkState::Available => new JsonResponse(['error' => $this->translator->trans('errors.activation_link_expired'), 'code' => 'expired', 'renewal' => $state->value], 410),
        };
    }
}
