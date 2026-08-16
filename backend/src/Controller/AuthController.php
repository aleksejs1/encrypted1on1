<?php

namespace App\Controller;

use App\Entity\Anketa;
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
        #[Autowire(service: 'limiter.login')]
        private readonly RateLimiterFactory $loginLimiter,
        #[Autowire(service: 'limiter.change_password')]
        private readonly RateLimiterFactory $changePasswordLimiter,
        #[Autowire(service: 'limiter.delete_account')]
        private readonly RateLimiterFactory $deleteAccountLimiter,
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

        // The billing-suspension gate (Phase D of private/cloud-service-plan.md, not
        // tracked in git) — mirrors isBlocked's own reversible-gate shape and check
        // point exactly, just at the company level instead of the account level. Every
        // self-hosted company is never suspended (nothing sets suspendedAt outside
        // Company::applyStripeSubscriptionUpdate()/suspend(), neither of which any
        // self-hosted code path calls), so this is a genuine no-op there.
        if ($user->getCompany()->isSuspended()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.company_suspended')], 403);
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
            // "Invite" UI (Phase 6g) — not admin-only information. Now a per-company
            // setting (private/cloud-service-plan.md, not tracked in git, Phase A).
            'registrationMode' => $user->getCompany()->getRegistrationMode(),
            // Non-sensitive (already exposed unauthenticated via GET /api/registration-info
            // for self-hosted deployments) — needed so AdminPanel.svelte's invite-settings
            // card can display the current restriction without a dedicated GET endpoint.
            'allowedEmailDomain' => $user->getCompany()->getAllowedEmailDomain(),
            'meetingRemindersEnabled' => $user->wantsMeetingReminders(),
            // Drives a persistent "you're viewing the shared demo" banner —
            // see private/demo-mode-plan.md (not tracked in git).
            'isDemo' => $user->isDemo(),
            // Lets the frontend show real content instead of "Not authorized" on
            // /platform-admin (Phase C, private/cloud-service-plan.md, not tracked in
            // git) — never drives any navigation link, unlike isAdmin.
            'isPlatformAdmin' => $user->isPlatformAdmin(),
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

    /**
     * Gates only the day-before/not-filled-out reminder emails (AnketaNotifier::
     * notifyMeetingTomorrow()/notifyNotFilledOut()) — the "new anketa scheduled" email
     * stays mandatory regardless, per the Account Settings plan.
     */
    #[Route('/api/me/notification-preferences', name: 'me_set_notification_preferences', methods: ['PUT'])]
    public function setNotificationPreferences(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            return new JsonResponse(['error' => $this->translator->trans('errors.not_authenticated')], 401);
        }

        $enabled = $request->toArray()['meetingRemindersEnabled'] ?? null;
        if (!\is_bool($enabled)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'meetingRemindersEnabled'])], 400);
        }

        $user->setMeetingRemindersEnabled($enabled);
        $this->entityManager->flush();

        return new JsonResponse(['meetingRemindersEnabled' => $user->wantsMeetingReminders()]);
    }

    /**
     * In-app "change password" for a still-logged-in user who remembers their current
     * password — distinct from PasswordResetController's forgot-password flow: the
     * keypair itself doesn't change, only the password wrapping the (unchanged) private
     * key, so there's no anketa re-sharing consequence. See User::changePassword().
     */
    #[Route('/api/me/password', name: 'me_change_password', methods: ['PUT'])]
    public function changePassword(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            return new JsonResponse(['error' => $this->translator->trans('errors.not_authenticated')], 401);
        }

        // Keyed by the account itself, not IP — same reasoning as the invite limiter:
        // this is a per-user action, and IP-keying would collectively throttle a shared
        // office network.
        $limit = $this->changePasswordLimiter->create($user->getId())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        $body = $request->toArray();
        foreach (['currentAuthKey', 'newAuthKey', 'newEncryptedPrivateKey'] as $field) {
            if (!\is_string($body[$field] ?? null) || '' === $body[$field]) {
                return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => $field])], 400);
            }
        }

        if (!hash_equals($user->getAuthHash(), $body['currentAuthKey'])) {
            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_current_password')], 401);
        }

        $user->changePassword($body['newAuthKey'], $body['newEncryptedPrivateKey']);
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Self-service account deletion — deletes the caller's own account only (see
     * User::delete()'s docblock for why this is anonymization-in-place, not a real row
     * delete, and why the pair's anketas are never touched). Requires the current
     * password, same reasoning as changePassword(): an unattended-but-open session
     * shouldn't be able to destroy the account without proving the password is known.
     */
    #[Route('/api/me', name: 'me_delete', methods: ['DELETE'])]
    public function deleteAccount(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            return new JsonResponse(['error' => $this->translator->trans('errors.not_authenticated')], 401);
        }

        $limit = $this->deleteAccountLimiter->create($user->getId())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        $currentAuthKey = $request->toArray()['currentAuthKey'] ?? null;
        if (!\is_string($currentAuthKey) || '' === $currentAuthKey) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'currentAuthKey'])], 400);
        }

        if (!hash_equals($user->getAuthHash(), $currentAuthKey)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_current_password')], 401);
        }

        /** @var Anketa[] $anketas */
        $anketas = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Anketa::class, 'a')
            ->where('a.employee = :user OR a.manager = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        foreach ($anketas as $anketa) {
            $anketa->clearUnpublishedDraftFor($user);
        }

        $user->delete();
        $this->authSession->logOut($request);
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }
}
