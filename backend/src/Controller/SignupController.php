<?php

namespace App\Controller;

use App\Billing\SeatLimitChecker;
use App\Company\SingleCompanyProvider;
use App\Entity\ActivationToken;
use App\Entity\User;
use App\Http\RateLimitResponse;
use App\Notification\InvitationNotifier;
use App\Security\CsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * REGISTRATION_MODE=domain: open self-registration, restricted to
 * ALLOWED_EMAIL_DOMAIN, gated by double opt-in — submitting the email is the
 * first opt-in, clicking the emailed link is the second. Reuses
 * ActivationToken/ActivationController entirely unchanged for the second
 * half; this controller only adds a new, self-service way to *issue* a
 * token, alongside the CLI bootstrap and InviteController's inviter-issued
 * tokens. Mirrors PasswordResetController's shape: unauthenticated,
 * enumeration-sensitive, rate-limited.
 *
 * Disabled entirely once CLOUD_MODE is on (Phase B of private/cloud-service-plan.md,
 * not tracked in git) — this controller's whole design assumes there's exactly one
 * Company row to resolve via SingleCompanyProvider, which stops being true the moment
 * CompanyController can create a second one. Growing an existing cloud-mode company
 * still works exactly as before, just invite-only (InviteController, which reads
 * $inviter->getCompany() directly and never had this assumption). Resolving "which
 * company" an anonymous domain-signup visitor means — e.g. matching their email
 * against each company's allowedEmailDomain — is real, undesigned future work, not
 * something this phase silently guesses at.
 */
class SignupController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationNotifier $notifier,
        private readonly CsrfGuard $csrfGuard,
        private readonly TranslatorInterface $translator,
        private readonly SingleCompanyProvider $singleCompanyProvider,
        private readonly SeatLimitChecker $seatLimitChecker,
        private readonly bool $cloudMode,
        #[Autowire(service: 'limiter.signup')]
        private readonly RateLimiterFactory $signupLimiter,
    ) {
    }

    /**
     * Unauthenticated by design — the frontend needs to know this *before* login to
     * decide whether to show a "Sign up" link at all. Neither value is sensitive:
     * registrationMode is already exposed via authenticated /api/me (Phase 6g), and
     * allowedEmailDomain is already echoed back in errors.email_domain_restricted's
     * message to invite-flow users. cloudMode is new (Phase B) — drives whether the
     * frontend shows a "Start a new company" link instead/as well.
     */
    #[Route('/api/registration-info', name: 'registration_info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        if ($this->cloudMode) {
            // See this class's own docblock: no single company's mode/domain is a
            // correct answer anymore, so domain-based self-signup just reports as
            // closed — 'invite' is the same safe default a brand-new company already
            // starts with (see CompanyController).
            return new JsonResponse([
                'registrationMode' => 'invite',
                'allowedEmailDomain' => '',
                'cloudMode' => true,
            ]);
        }

        $company = $this->singleCompanyProvider->get();

        return new JsonResponse([
            'registrationMode' => $company->getRegistrationMode(),
            'allowedEmailDomain' => $company->getAllowedEmailDomain(),
            'cloudMode' => false,
        ]);
    }

    #[Route('/api/signup', name: 'signup', methods: ['POST'])]
    public function signup(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        // Consumed before the mode check, not after — keeps this limiter exercisable
        // regardless of which mode happens to be configured.
        $limit = $this->signupLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        if ($this->cloudMode) {
            return new JsonResponse(['error' => $this->translator->trans('errors.registration_not_open')], 400);
        }

        $company = $this->singleCompanyProvider->get();
        if ('domain' !== $company->getRegistrationMode()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.registration_not_open')], 400);
        }

        $email = $request->toArray()['email'] ?? null;
        if (!\is_string($email) || '' === $email) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_email')], 400);
        }

        $allowedEmailDomain = $company->getAllowedEmailDomain();
        if ('' !== $allowedEmailDomain && !str_ends_with($email, '@'.$allowedEmailDomain)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.email_domain_restricted', ['%domain%' => $allowedEmailDomain])], 400);
        }

        // Same enumeration-avoidance discipline as PasswordResetController::request():
        // the response never reveals whether the email already has an account.
        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $existing) {
            if ($this->seatLimitChecker->hasReachedLimit($company)) {
                return new JsonResponse(['error' => $this->translator->trans('errors.seat_limit_reached')], 400);
            }

            [$activationToken, $rawToken] = ActivationToken::issue($email, $company);
            $this->entityManager->persist($activationToken);
            $this->entityManager->flush();

            $this->notifier->notifySignup($email, $rawToken);
        }

        return new JsonResponse(['ok' => true]);
    }
}
