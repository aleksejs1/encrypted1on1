<?php

namespace App\Controller;

use App\Billing\BillingCustomerMissingException;
use App\Billing\BillingNotConfiguredException;
use App\Billing\BillingProviderException;
use App\Billing\BillingProviderInterface;
use App\Billing\BillingState;
use App\Billing\Plan;
use App\Billing\SeatLimitChecker;
use App\Billing\SubscriptionSync;
use App\Entity\Company;
use App\Entity\User;
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
 * docs/deployment.md's "Stripe billing" section for what was verified against a Stripe
 * sandbox and what wasn't.
 */
class BillingController
{
    use RequiresCompanyAdmin;

    public function __construct(
        private readonly AuthSession $authSession,
        private readonly TranslatorInterface $translator,
        private readonly BillingProviderInterface $billingProvider,
        private readonly SubscriptionSync $subscriptionSync,
        private readonly SeatLimitChecker $seatLimitChecker,
        private readonly Plan $plan,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $frontendBaseUrl,
        private readonly bool $cloudMode,
        private readonly string $supportEmail,
    ) {
    }

    /**
     * Everything the Plan page shows, from what is stored on the company: Stripe is not
     * asked, so the page can poll this while it waits for a payment to arrive.
     *
     * `activeUsers` is the count seats are enforced on: every account that isn't
     * deleted, a blocked one included. `periodEndsAt` is the next payment date except in
     * `plus-past-due`, where it ends a period nobody paid for yet
     * (Company::$subscriptionPeriodEndsAt).
     */
    #[Route('/api/admin/billing', name: 'billing_overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $company = $this->requireCloudAdmin($request)->getCompany();

        // Read-only from here on — see AuthSession::closeForReading()'s docblock.
        $this->authSession->closeForReading($request);

        $paymentAvailable = $this->billingProvider->isConfigured();
        $supportEmail = trim($this->supportEmail);
        $seats = $this->seatLimitChecker->usage($company);

        return new JsonResponse([
            'state' => BillingState::of($company, $paymentAvailable)->value,
            'planTier' => $company->getPlanTier(),
            'seatLimit' => $seats->limit,
            'activeUsers' => $seats->people,
            'pendingInvites' => $seats->pendingInvites,
            'periodEndsAt' => $company->getSubscriptionPeriodEndsAt()?->format(\DATE_ATOM),
            'cancelsAt' => $company->getSubscriptionCancelsAt()?->format(\DATE_ATOM),
            // Something to open the customer portal for, and a portal to open.
            'hasInvoices' => $paymentAvailable && null !== $company->getStripeCustomerId(),
            'freeSeatLimit' => $this->plan->seatLimit(Plan::FREE),
            'plusSeatLimit' => $this->plan->seatLimit(Plan::PLUS),
            'supportEmail' => '' !== $supportEmail ? $supportEmail : null,
        ]);
    }

    /**
     * What Plus costs, for the Plan page to show before the admin clicks. Its own
     * route, so that the polled overview above never waits for Stripe. A page without
     * a price is still usable (Checkout shows it), so Stripe not answering, refusing, or
     * holding a price the page can't word is a plain `null`, never an error status.
     */
    #[Route('/api/admin/billing/plus-price', name: 'billing_plus_price', methods: ['GET'])]
    public function plusPrice(Request $request): JsonResponse
    {
        $this->requireCloudAdmin($request);

        // Before Stripe is asked, or the tab's other requests wait for its answer
        // behind the session lock — see AuthSession::closeForReading()'s docblock.
        $this->authSession->closeForReading($request);

        try {
            $price = $this->billingProvider->retrievePlusPrice();
        } catch (BillingNotConfiguredException) {
            return JsonResponse::fromJsonString('null');
        } catch (BillingProviderException $e) {
            // An error, not a warning: the default logger prints nothing below it, and
            // a STRIPE_PRICE_ID that names no usable price would otherwise go unseen.
            $this->logger->error('The Plus price could not be read from Stripe: {reason}', ['reason' => $e->getMessage()]);

            return JsonResponse::fromJsonString('null');
        }

        return new JsonResponse([
            'amount' => $price->amount,
            'currency' => $price->currency,
            'interval' => $price->interval,
            'taxBehavior' => $price->taxBehavior,
        ]);
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
        $company = $this->requireCloudAdmin($request)->getCompany();

        // canBuyPlus() refuses this case too: asked first only for its own message.
        if ($company->hasPayingSubscription()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.billing_already_subscribed')], 409);
        }
        if (!$company->canBuyPlus()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.billing_plan_not_purchasable')], 409);
        }

        try {
            try {
                $url = $this->checkoutUrl($company);
            } catch (BillingCustomerMissingException) {
                $url = $this->checkoutUrl($company, reuseCustomer: false);
                // Forgotten only now that Stripe made a session without it. A secret key
                // of another account also answers "no such customer", and then fails
                // above on the price: the stored ids must survive that mistake.
                $this->logger->warning('Stripe no longer has customer {customer} of company {company}; the checkout starts a new one.', [
                    'customer' => $company->getStripeCustomerId(),
                    'company' => $company->getId(),
                ]);
                $company->forgetStripeCustomer();
                $this->entityManager->flush();
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

    /**
     * Opens Stripe's customer portal for the company's Stripe customer: the card, the
     * invoices and cancelling are all done there, the app has no forms of its own for
     * them. Also for a company that paid once and is on Free again (its invoices), and
     * for one on a plan set by hand whose old subscription still runs.
     */
    #[Route('/api/admin/billing/portal-session', name: 'billing_portal_session', methods: ['POST'])]
    public function createPortalSession(Request $request): JsonResponse
    {
        $user = $this->requireCloudAdmin($request);
        $company = $user->getCompany();
        $customerId = $company->getStripeCustomerId();

        // First, so that the answer says what is wrong with the server before what is
        // missing on the company.
        if (!$this->billingProvider->isConfigured()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.billing_not_configured')], 503);
        }
        if (null === $customerId) {
            return new JsonResponse(['error' => $this->translator->trans('errors.billing_no_customer')], 409);
        }

        // Nothing below touches the session, and Stripe may take seconds to answer:
        // see AuthSession::closeForReading()'s docblock.
        $this->authSession->closeForReading($request);

        try {
            $url = $this->billingProvider->createPortalSession($customerId, $this->planPageUrl().'?portal=return', $user->getLocale());
        } catch (BillingNotConfiguredException) {
            return new JsonResponse(['error' => $this->translator->trans('errors.billing_not_configured')], 503);
        } catch (BillingCustomerMissingException) {
            // Not "try again later": it fails the same way every time, and nothing
            // here cures it (a checkout forgets the id, but a company that still pays
            // is refused one). An error, so that the operator sees it: the customer was
            // deleted, or the secret key is another account's.
            $this->logger->error('Stripe has no customer {customer}, stored on company {company}: its portal can\'t be opened.', [
                'customer' => $customerId,
                'company' => $company->getId(),
            ]);

            return new JsonResponse(['error' => $this->translator->trans('errors.billing_customer_missing')], 409);
        } catch (BillingProviderException $e) {
            // Thrown and 503 for the same reasons as in createCheckoutSession().
            throw new ServiceUnavailableHttpException(null, $this->translator->trans('errors.billing_portal_failed'), $e);
        }

        return new JsonResponse(['url' => $url]);
    }

    /** A self-hosted instance sells nothing: to it every route of the Plan page is as good as absent. */
    private function requireCloudAdmin(Request $request): User
    {
        if (!$this->cloudMode) {
            throw new NotFoundHttpException($this->translator->trans('errors.billing_not_configured'));
        }

        return $this->requireAdmin($request);
    }

    private function planPageUrl(): string
    {
        return rtrim($this->frontendBaseUrl, '/').'/admin/plan';
    }

    /** Stripe sends the admin back to the Plan page either way. */
    private function checkoutUrl(Company $company, bool $reuseCustomer = true): string
    {
        $planPage = $this->planPageUrl();

        return $this->billingProvider->createCheckoutSession(
            $company,
            successUrl: $planPage.'?checkout=success',
            cancelUrl: $planPage.'?checkout=cancelled',
            reuseCustomer: $reuseCustomer,
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
