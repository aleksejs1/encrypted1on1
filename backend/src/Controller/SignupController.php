<?php

namespace App\Controller;

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
 */
class SignupController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationNotifier $notifier,
        private readonly CsrfGuard $csrfGuard,
        private readonly TranslatorInterface $translator,
        private readonly SingleCompanyProvider $singleCompanyProvider,
        #[Autowire(service: 'limiter.signup')]
        private readonly RateLimiterFactory $signupLimiter,
    ) {
    }

    /**
     * Unauthenticated by design — the frontend needs to know this *before* login to
     * decide whether to show a "Sign up" link at all. Neither value is sensitive:
     * registrationMode is already exposed via authenticated /api/me (Phase 6g), and
     * allowedEmailDomain is already echoed back in errors.email_domain_restricted's
     * message to invite-flow users.
     *
     * Reads the one Company row Phase A guarantees exists (private/cloud-service-plan.md,
     * not tracked in git) — with no way yet to create a second company, there's no
     * ambiguity about which one an unauthenticated visitor means. A later phase that adds
     * self-service company creation must revisit this: resolving "which company" from an
     * anonymous visitor with no session needs a real design (e.g. matching the email they
     * type against each company's allowedEmailDomain), not this single-row shortcut.
     */
    #[Route('/api/registration-info', name: 'registration_info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        $company = $this->singleCompanyProvider->get();

        return new JsonResponse([
            'registrationMode' => $company->getRegistrationMode(),
            'allowedEmailDomain' => $company->getAllowedEmailDomain(),
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
            [$activationToken, $rawToken] = ActivationToken::issue($email, $company);
            $this->entityManager->persist($activationToken);
            $this->entityManager->flush();

            $this->notifier->notifySignup($email, $rawToken);
        }

        return new JsonResponse(['ok' => true]);
    }
}
