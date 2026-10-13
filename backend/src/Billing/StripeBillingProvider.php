<?php

namespace App\Billing;

use App\Entity\Company;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Stripe\Exception\ExceptionInterface as StripeException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\Webhook;

/**
 * The official `stripe/stripe-php` SDK. Verified against a Stripe sandbox only, and not
 * in every branch: docs/deployment.md's "Stripe billing" section says what the runs
 * showed and what they didn't cover (GitHub issues #283, #287, #293, #298). Of the
 * refusals only two were seen: a customer Stripe doesn't have (createCheckoutSession())
 * and an unknown subscription id (retrieveSubscription()). Nothing has run against a
 * live-mode account. The webhook signature check is also covered by
 * StripeBillingProviderTest, with a payload signed by hand using the algorithm Stripe
 * documents.
 *
 * An event says only which subscription it is about; what the subscription means for a
 * company is Company::applySubscription()'s business, not this class's.
 *
 * An account with Managed Payments on refuses to create a session unless the product has
 * a tax code — see docs/deployment.md's "Stripe billing" section.
 */
class StripeBillingProvider implements BillingProviderInterface
{
    private const array SUBSCRIPTION_EVENTS = ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'];

    /**
     * Stripe refuses less than 30 minutes "from Checkout Session creation", by its own
     * clock: five minutes more, so a server clock that runs behind doesn't get every
     * session refused.
     */
    private const int SESSION_LIFETIME_SECONDS = 35 * 60;

    private const int TIMEOUT_SECONDS = 10;
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly string $stripeSecretKey,
        private readonly string $stripeWebhookSecret,
        private readonly string $stripePriceId,
        /** For tests: an HTTP client that answers in Stripe's place. */
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function createCheckoutSession(Company $company, string $successUrl, string $cancelUrl, bool $reuseCustomer = true): string
    {
        // The webhook secret too: without it the payment would be taken and the webhook
        // that moves the company to Plus would refuse every event.
        if ('' === $this->stripeSecretKey || '' === $this->stripePriceId || '' === $this->stripeWebhookSecret) {
            throw new BillingNotConfiguredException('STRIPE_SECRET_KEY, STRIPE_PRICE_ID and STRIPE_WEBHOOK_SECRET are not all set.');
        }

        $customerId = $reuseCustomer ? $company->getStripeCustomerId() : null;

        try {
            $session = $this->client()->checkout->sessions->create([
                'mode' => 'subscription',
                'line_items' => [['price' => $this->stripePriceId, 'quantity' => 1]],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                // Ties the checkout back to a Company: read again from
                // checkout.session.completed, see constructWebhookEvent() below.
                'client_reference_id' => $company->getId(),
                // The same tie on the subscription itself, where every later event of
                // it finds the company (snapshotOf()), the first one included: that is
                // customer.subscription.created, which arrives before the checkout's own.
                'subscription_data' => ['metadata' => ['company_id' => $company->getId()]],
                // An open Checkout page can be paid until it expires, also after the
                // company bought through another one. Stripe's default is 24 hours.
                'expires_at' => time() + self::SESSION_LIFETIME_SECONDS,
                // Without it every purchase makes a new Stripe customer, and the
                // customer portal shows none of the earlier invoices.
                ...(null !== $customerId ? ['customer' => $customerId] : []),
            ]);
        } catch (StripeException $e) {
            throw self::checkoutRefusal($e, $customerId);
        }

        $url = $session->url;
        if (null === $url) {
            // Stripe's own SDK types this nullable; genuinely shouldn't happen for a
            // freshly created session, but there's nothing sensible to redirect to if it did.
            throw new BillingProviderException('Stripe did not return a checkout URL.');
        }

        return $url;
    }

    /** "No such customer" is told apart only when a customer was sent: forgetting it is then the cure. */
    private static function checkoutRefusal(StripeException $e, ?string $customerId): BillingProviderException
    {
        $message = 'Stripe did not create the Checkout session: '.$e->getMessage();
        $customerIsGone = null !== $customerId
            && $e instanceof InvalidRequestException
            && 'resource_missing' === $e->getStripeCode()
            && 'customer' === $e->getStripeParam();

        return $customerIsGone ? new BillingCustomerMissingException($message, 0, $e) : new BillingProviderException($message, 0, $e);
    }

    public function retrieveSubscription(string $subscriptionId): SubscriptionSnapshot
    {
        if ('' === $this->stripeSecretKey) {
            throw new BillingNotConfiguredException('STRIPE_SECRET_KEY is not set.');
        }

        try {
            return self::snapshotOf($this->client()->subscriptions->retrieve($subscriptionId));
        } catch (StripeException $e) {
            // Every exception of the SDK: a refusal, no answer in time, and also an
            // answer that isn't Stripe's JSON (a proxy's error page during an outage).
            throw new BillingProviderException('Stripe did not return the subscription: '.$e->getMessage(), 0, $e);
        }
    }

    /** Public for its test, which feeds it subscription objects saved from the sandbox. */
    public static function snapshotOf(Subscription $subscription): SubscriptionSnapshot
    {
        $customerId = $subscription->customer;
        // Since API version 2025-03-31 the period is on the subscription's items, not on
        // the subscription. This app sells one price, so there is one item.
        $periodEnd = $subscription->items->data[0]->current_period_end ?? null;
        $companyId = $subscription->metadata['company_id'] ?? null;

        return new SubscriptionSnapshot(
            id: $subscription->id,
            customerId: \is_string($customerId) ? $customerId : null,
            status: $subscription->status,
            periodEndsAt: self::dateOf($periodEnd),
            cancelsAt: self::dateOf($subscription->cancel_at),
            companyId: \is_string($companyId) && '' !== $companyId ? $companyId : null,
        );
    }

    private static function dateOf(mixed $timestamp): ?\DateTimeImmutable
    {
        if (!\is_int($timestamp)) {
            return null;
        }

        // In PHP's default timezone, like every other date this app stores.
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * The SDK's own limits are 80 seconds for an answer and 30 for a connection, which
     * would hold a worker, and Stripe's webhook delivery, for that long. Its HTTP client
     * is one per process and keeps its connection between requests, so the limits are
     * set on that shared client every time, not on a new one.
     */
    private function client(): StripeClient
    {
        $http = $this->httpClient;
        if (null === $http) {
            $http = CurlClient::instance();
            \assert($http instanceof CurlClient);
            $http->setTimeout(self::TIMEOUT_SECONDS);
            $http->setConnectTimeout(self::CONNECT_TIMEOUT_SECONDS);
        }
        ApiRequestor::setHttpClient($http);

        return new StripeClient($this->stripeSecretKey);
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): WebhookEvent
    {
        // Without a secret anybody could sign a payload: nothing is verifiable then.
        if ('' === $this->stripeWebhookSecret) {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET is not set.');
        }

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $this->stripeWebhookSecret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            throw new \RuntimeException('Invalid Stripe webhook payload/signature.', 0, $e);
        }

        $object = $event->data->object;

        return match (true) {
            'checkout.session.completed' === $event->type && $object instanceof Session => new WebhookEvent(
                $event->type,
                \is_string($object->subscription) ? $object->subscription : null,
                $object->client_reference_id,
            ),
            \in_array($event->type, self::SUBSCRIPTION_EVENTS, true) && $object instanceof Subscription => new WebhookEvent($event->type, $object->id),
            default => new WebhookEvent($event->type, null),
        };
    }
}
