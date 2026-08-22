<?php

namespace App\Billing;

use App\Entity\Company;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\Webhook;

/**
 * Phase D of private/cloud-service-plan.md (not tracked in git) — real usage of the
 * official `stripe/stripe-php` SDK, written correctly per Stripe's documented API shape,
 * but **never run against a real Stripe account** (no test-mode keys were available
 * while building this — see docs/history.md's own Phase D entry). Unlike everything else in
 * this codebase, this class does not carry the "verified for real" guarantee the rest of
 * the project holds itself to. Two things are structurally different in how much
 * confidence to place in each:
 *
 * - constructWebhookEvent() is pure local cryptography (HMAC-SHA256 signature
 *   verification, JSON parsing) with no network call — genuinely covered by a real test
 *   (StripeBillingProviderTest) using a correctly-signed payload built by hand with the
 *   same algorithm Stripe documents, not a live API call. This part can be trusted.
 * - createCheckoutSession() makes a real network call to Stripe's API
 *   (`$stripe->checkout->sessions->create()`) — this cannot be exercised at all without
 *   live credentials, and must be verified for real (a genuine test-mode checkout, a
 *   genuine webhook delivery) before this is ever relied on for real billing.
 */
class StripeBillingProvider implements BillingProviderInterface
{
    /**
     * Statuses this app doesn't have its own name for (incomplete, incomplete_expired,
     * unpaid, paused, and any future addition to Stripe's own vocabulary) all map to
     * 'canceled' — erring toward suspending an uncertain status is the safe direction,
     * not leaving a non-paying company active on an unrecognized status.
     */
    private const array KNOWN_SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due'];

    public function __construct(
        private readonly string $stripeSecretKey,
        private readonly string $stripeWebhookSecret,
        private readonly string $stripePriceId,
    ) {
    }

    public function createCheckoutSession(Company $company, string $successUrl, string $cancelUrl): string
    {
        if ('' === $this->stripeSecretKey || '' === $this->stripePriceId) {
            throw new BillingNotConfiguredException('STRIPE_SECRET_KEY/STRIPE_PRICE_ID are not set.');
        }

        $stripe = new StripeClient($this->stripeSecretKey);
        $session = $stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'line_items' => [['price' => $this->stripePriceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            // The one field that ties a real Stripe checkout back to a Company —
            // read again in checkout.session.completed, see fromCheckoutSession() below.
            'client_reference_id' => $company->getId(),
        ]);

        $url = $session->url;
        if (null === $url) {
            // Stripe's own SDK types this nullable; genuinely shouldn't happen for a
            // freshly created session, but there's nothing sensible to redirect to if it did.
            throw new \RuntimeException('Stripe did not return a checkout URL.');
        }

        return $url;
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): WebhookEvent
    {
        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $this->stripeWebhookSecret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            throw new \RuntimeException('Invalid Stripe webhook payload/signature.', 0, $e);
        }

        return match ($event->type) {
            'checkout.session.completed' => $this->fromCheckoutSession($event),
            'customer.subscription.updated', 'customer.subscription.deleted' => $this->fromSubscription($event),
            default => new WebhookEvent($event->type, null, null, null, null),
        };
    }

    private function fromCheckoutSession(Event $event): WebhookEvent
    {
        $session = $event->data->object;
        \assert($session instanceof Session);

        $customerId = $session->customer;
        $subscriptionId = $session->subscription;

        return new WebhookEvent(
            type: $event->type,
            companyId: $session->client_reference_id,
            stripeCustomerId: \is_string($customerId) ? $customerId : null,
            stripeSubscriptionId: \is_string($subscriptionId) ? $subscriptionId : null,
            // Stripe only sends this event once checkout genuinely succeeded — 'active'
            // here reflects that fact, not a guess (a possible trial is a real gap, see
            // this class's own docblock: mapStripeStatus() below is never consulted for
            // this specific event, only for later customer.subscription.* events).
            subscriptionStatus: 'active',
        );
    }

    private function fromSubscription(Event $event): WebhookEvent
    {
        $subscription = $event->data->object;
        \assert($subscription instanceof Subscription);

        $customerId = $subscription->customer;

        return new WebhookEvent(
            type: $event->type,
            companyId: null,
            stripeCustomerId: \is_string($customerId) ? $customerId : null,
            stripeSubscriptionId: $subscription->id,
            subscriptionStatus: $this->mapStripeStatus($subscription->status),
        );
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return \in_array($stripeStatus, self::KNOWN_SUBSCRIPTION_STATUSES, true) ? $stripeStatus : 'canceled';
    }
}
