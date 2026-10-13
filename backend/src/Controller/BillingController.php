<?php

namespace App\Controller;

use App\Billing\BillingCustomerMissingException;
use App\Billing\BillingNotConfiguredException;
use App\Billing\BillingProviderException;
use App\Billing\BillingProviderInterface;
use App\Billing\Plan;
use App\Billing\SubscriptionSync;
use App\Entity\Company;
use App\Security\AuthSession;
use App\Security\RequiresCompanyAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Phase D of private/cloud-service-plan.md (not tracked in git). See
 * StripeBillingProvider's own docblock for what was verified against a Stripe sandbox
 * and what wasn't. The checkout-session route itself was not part of that run: the
 * provider was called directly.
 */
class BillingController
{
    use RequiresCompanyAdmin;

    public function __construct(
        private readonly AuthSession $authSession,
        private readonly TranslatorInterface $translator,
        private readonly BillingProviderInterface $billingProvider,
        private readonly SubscriptionSync $subscriptionSync,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $frontendBaseUrl,
        private readonly bool $cloudMode,
    ) {
    }

    /**
     * Starts the purchase of Plus for the admin's own company. Refused when there is
     * nothing to buy: the company already has a subscription that pays (a second one
     * would bill as well), or its plan was set by hand, which the webhook leaves alone
     * (Company::applySubscription()), so the payment would change nothing.
     */
    #[Route('/api/admin/billing/checkout-session', name: 'billing_checkout_session', methods: ['POST'])]
    public function createCheckoutSession(Request $request): JsonResponse
    {
        // A self-hosted instance sells nothing: to it the route is as good as absent.
        if (!$this->cloudMode) {
            throw new NotFoundHttpException($this->translator->trans('errors.billing_not_configured'));
        }
        $company = $this->requireAdmin($request)->getCompany();

        if ($company->hasPayingSubscription()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.billing_already_subscribed')], 409);
        }
        // No seat limit is by hand too: Plus would add nothing to it.
        if (Plan::FREE !== $company->getPlanTier() || null === $company->getSeatLimit()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.billing_plan_not_purchasable')], 409);
        }

        try {
            try {
                $url = $this->checkoutUrl($company);
            } catch (BillingCustomerMissingException) {
                $this->logger->warning('Stripe no longer has customer {customer} of company {company}; the checkout starts a new one.', [
                    'customer' => $company->getStripeCustomerId(),
                    'company' => $company->getId(),
                ]);
                $company->forgetStripeCustomer();
                $this->entityManager->flush();
                $url = $this->checkoutUrl($company);
            }
        } catch (BillingNotConfiguredException) {
            return new JsonResponse(['error' => $this->translator->trans('errors.billing_not_configured')], 503);
        } catch (BillingProviderException $e) {
            // Thrown, so JsonExceptionListener logs it (with Stripe's words, cleaned up)
            // and Sentry sees it. 503, not 502: the frontend reads a 502 as a proxy
            // answering for a backend that is down, and shows its connection-loss
            // banner (answeredBy() in frontend/src/api/client.ts).
            throw new ServiceUnavailableHttpException(null, $this->translator->trans('errors.billing_checkout_failed'), $e);
        }

        return new JsonResponse(['url' => $url]);
    }

    /** Stripe sends the admin back to the Plan page either way. */
    private function checkoutUrl(Company $company): string
    {
        $planPage = rtrim($this->frontendBaseUrl, '/').'/admin/plan';

        return $this->billingProvider->createCheckoutSession(
            $company,
            successUrl: $planPage.'?checkout=success',
            cancelUrl: $planPage.'?checkout=cancelled',
        );
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

        if (null !== $event->subscriptionId) {
            // An exception here (Stripe didn't return the subscription, or its secret key
            // isn't set) is left to become a 500: Stripe then delivers the event again.
            $this->subscriptionSync->sync($event->subscriptionId, $event->checkoutCompanyId);
        }

        return new JsonResponse(['received' => true]);
    }
}
