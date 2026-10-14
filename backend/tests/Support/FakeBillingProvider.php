<?php

namespace App\Tests\Support;

use App\Billing\BillingProviderInterface;
use App\Billing\SubscriptionSnapshot;
use App\Billing\WebhookEvent;
use App\Entity\Company;

/**
 * Stands in for Stripe where a test needs BillingController to get an answer from it:
 * the real provider can only refuse (not configured) or call Stripe.
 */
final class FakeBillingProvider implements BillingProviderInterface
{
    public const string CHECKOUT_URL = 'https://checkout.stripe.test/c/pay/cs_test_fake';

    /** @var list<array{companyId: string, successUrl: string, cancelUrl: string}> */
    public array $checkoutSessions = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckoutSession(Company $company, string $successUrl, string $cancelUrl, bool $reuseCustomer = true): string
    {
        $this->checkoutSessions[] = ['companyId' => $company->getId(), 'successUrl' => $successUrl, 'cancelUrl' => $cancelUrl];

        return self::CHECKOUT_URL;
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): WebhookEvent
    {
        throw new \LogicException('No test sends a webhook through the fake provider.');
    }

    public function retrieveSubscription(string $subscriptionId): SubscriptionSnapshot
    {
        throw new \LogicException('No test reads a subscription through the fake provider yet.');
    }
}
