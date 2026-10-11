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
 * Phase D of private/cloud-service-plan.md (not tracked in git) — the official
 * `stripe/stripe-php` SDK. Only partly verified, so don't treat billing as proven: one
 * manual run against a Stripe sandbox (GitHub issue #283) covered the first two points
 * below, nothing has run against a live-mode account, and BillingController's
 * checkout-session route wasn't part of that run (its webhook route was).
 *
 * - createCheckoutSession() created a real Checkout session, which was then paid with a
 *   Stripe test card.
 * - constructWebhookEvent() accepted the real `checkout.session.completed` delivery that
 *   followed. Its signature check is also covered by StripeBillingProviderTest, with a
 *   payload signed by hand using the algorithm Stripe documents.
 * - `customer.subscription.updated`/`customer.subscription.deleted`: this class's
 *   parsing of them is covered by that unit test only. No real delivery of either has
 *   been handled yet.
 *
 * A known gap: `checkout.session.completed` is always recorded as 'active', also for a
 * subscription that starts with a trial and for a delayed payment method (a bank debit)
 * that hasn't paid yet (see fromCheckoutSession()).
 *
 * An account with Managed Payments on refuses to create a session unless the product has
 * a tax code — see docs/deployment.md's "Stripe billing" section.
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
            // Stripe sends this event once the customer completed checkout, so it is
            // recorded as 'active'. A trial or a delayed payment method that hasn't paid
            // yet is a real gap, see this class's own docblock: mapStripeStatus() below
            // is never consulted for this event, only for later customer.subscription.*
            // events.
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
