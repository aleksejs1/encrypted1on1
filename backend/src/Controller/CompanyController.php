<?php

namespace App\Controller;

use App\Entity\ActivationToken;
use App\Entity\Company;
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
 * The one genuinely new public entry point Phase B of private/cloud-service-plan.md
 * (not tracked in git) adds: self-service company creation, gated behind CLOUD_MODE
 * (off by default — self-hosted deployments never expose this; they bootstrap their one
 * company via bin/console app:create-activation-link instead, unchanged). Mirrors
 * SignupController's own shape closely: unauthenticated, enumeration-sensitive,
 * rate-limited — and, like it, only ever takes an email, never a password. The real
 * crypto/keypair generation happens later, at the same /activate/:token page every other
 * activation flow already uses; ActivationController needed zero changes, since
 * grantsAdmin was already a plain flag on the token.
 */
class CompanyController
{
    /**
     * Every self-service-created company starts on this seat count (Phase D of
     * private/cloud-service-plan.md, not tracked in git) — a placeholder, not a decided
     * pricing tier: no real plan/pricing structure exists yet (see the plan's own "not
     * an engineering decision" note), so this is deliberately just "a real, enforced
     * number, easy to change in one place" rather than an invented tier system. Every
     * company that predates Phase D (the single self-hosted default company) stays
     * unlimited (seatLimit stays null) — this constant only applies to brand-new
     * companies created from here on.
     */
    private const int DEFAULT_SEAT_LIMIT = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationNotifier $notifier,
        private readonly CsrfGuard $csrfGuard,
        private readonly TranslatorInterface $translator,
        private readonly bool $cloudMode,
        #[Autowire(service: 'limiter.create_company')]
        private readonly RateLimiterFactory $createCompanyLimiter,
    ) {
    }

    #[Route('/api/companies', name: 'company_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        // Consumed before the mode check, not after — same reasoning
        // SignupController::signup() already documents: keeps this limiter
        // exercisable regardless of whether CLOUD_MODE happens to be on.
        $limit = $this->createCompanyLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        if (!$this->cloudMode) {
            return new JsonResponse(['error' => $this->translator->trans('errors.cloud_signup_not_open')], 400);
        }

        $body = $request->toArray();
        $name = $body['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'name'])], 400);
        }

        $email = $body['adminEmail'] ?? null;
        if (!\is_string($email) || '' === $email) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_email')], 400);
        }

        // Same enumeration-avoidance discipline as SignupController::signup()/
        // PasswordResetController::request(): the response never reveals whether the
        // email already has an account anywhere on this instance, and — since a company
        // is only ever persisted once that check passes — no orphaned, admin-less
        // Company row is ever left behind by a no-op request either.
        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $existing) {
            $company = new Company(trim($name), seatLimit: self::DEFAULT_SEAT_LIMIT);
            $this->entityManager->persist($company);

            [$activationToken, $rawToken] = ActivationToken::issue($email, $company, grantsAdmin: true);
            $this->entityManager->persist($activationToken);
            $this->entityManager->flush();

            $this->notifier->notifyCompanySignup($email, $rawToken, $company->getName());
        }

        return new JsonResponse(['ok' => true]);
    }
}
