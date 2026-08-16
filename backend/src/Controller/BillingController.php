<?php

namespace App\Controller;

use App\Billing\BillingNotConfiguredException;
use App\Billing\BillingProviderInterface;
use App\Entity\Company;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Phase D of private/cloud-service-plan.md (not tracked in git). See
 * StripeBillingProvider's own docblock for exactly which half of this is genuinely
 * verified (the webhook signature/event handling) and which half cannot be without a
 * real Stripe account (checkout-session creation).
 */
class BillingController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
        private readonly TranslatorInterface $translator,
        private readonly BillingProviderInterface $billingProvider,
        private readonly string $frontendBaseUrl,
        private readonly bool $cloudMode,
    ) {
    }

    /** Company-admin only — the same "who may act on behalf of a company" boundary AdminController already draws. */
    #[Route('/api/billing/checkout-session', name: 'billing_checkout_session', methods: ['POST'])]
    public function createCheckoutSession(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            throw new UnauthorizedHttpException('', $this->translator->trans('errors.not_authenticated'));
        }
        if (!$this->cloudMode) {
            return new JsonResponse(['error' => $this->translator->trans('errors.cloud_signup_not_open')], 400);
        }
        if (!$user->isAdmin()) {
            throw new AccessDeniedHttpException($this->translator->trans('errors.admin_only'));
        }

        $base = rtrim($this->frontendBaseUrl, '/');

        try {
            $url = $this->billingProvider->createCheckoutSession(
                $user->getCompany(),
                successUrl: $base.'/account?billing=success',
                cancelUrl: $base.'/account?billing=cancelled',
            );
        } catch (BillingNotConfiguredException) {
            return new JsonResponse(['error' => $this->translator->trans('errors.billing_not_configured')], 503);
        }

        return new JsonResponse(['url' => $url]);
    }

    /**
     * Genuinely unauthenticated — Stripe calls this server-to-server, no session cookie
     * involved, so there's nothing for CSRF protection to protect (CSRF exists to stop a
     * malicious page from riding a *browser's* existing session; there is no browser
     * here). Signature verification (BillingProviderInterface::constructWebhookEvent(),
     * checked against STRIPE_WEBHOOK_SECRET) is the correct and sufficient protection —
     * the deliberate exception to every other state-changing route in this app calling
     * CsrfGuard, not an oversight.
     */
    #[Route('/api/billing/webhook', name: 'billing_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $signature = $request->headers->get('Stripe-Signature', '');

        try {
            $event = $this->billingProvider->constructWebhookEvent($request->getContent(), $signature);
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => 'invalid signature'], 400);
        }

        $company = $this->resolveCompany($event->companyId, $event->stripeSubscriptionId, $event->stripeCustomerId);
        if (null !== $company && null !== $event->subscriptionStatus) {
            // Naturally idempotent — reapplying the same status update twice has the
            // same effect as once, so no separate event-id deduplication is needed.
            $company->applyStripeSubscriptionUpdate($event->subscriptionStatus, $event->stripeCustomerId, $event->stripeSubscriptionId);
            $this->entityManager->flush();
        }

        return new JsonResponse(['received' => true]);
    }

    private function resolveCompany(?string $companyId, ?string $stripeSubscriptionId, ?string $stripeCustomerId): ?Company
    {
        if (null !== $companyId) {
            return $this->entityManager->find(Company::class, $companyId);
        }
        if (null !== $stripeSubscriptionId) {
            $company = $this->entityManager->getRepository(Company::class)->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
            if (null !== $company) {
                return $company;
            }
        }
        if (null !== $stripeCustomerId) {
            return $this->entityManager->getRepository(Company::class)->findOneBy(['stripeCustomerId' => $stripeCustomerId]);
        }

        return null;
    }
}
