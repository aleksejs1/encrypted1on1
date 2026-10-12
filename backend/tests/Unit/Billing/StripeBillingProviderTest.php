<?php

namespace App\Tests\Unit\Billing;

use App\Billing\BillingNotConfiguredException;
use App\Billing\StripeBillingProvider;
use App\Entity\Company;
use App\Tests\Support\StripeSignature;
use PHPUnit\Framework\TestCase;

/**
 * The part of StripeBillingProvider that can be tested without a Stripe account (see
 * that class's own docblock): webhook signature verification, the parsing of the three
 * event types, and the not-configured refusal. Signature verification is pure local
 * HMAC-SHA256 cryptography, no network call: see StripeSignature for the scheme.
 */
class StripeBillingProviderTest extends TestCase
{
    private const string WEBHOOK_SECRET = 'whsec_test_secret';

    private function provider(): StripeBillingProvider
    {
        return new StripeBillingProvider('sk_test_whatever', self::WEBHOOK_SECRET, 'price_whatever');
    }

    public function testRejectsAnInvalidSignature(): void
    {
        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => []]], \JSON_THROW_ON_ERROR);

        $this->expectException(\RuntimeException::class);
        $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, 'wrong-secret'));
    }

    public function testRejectsATamperedPayload(): void
    {
        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => []]], \JSON_THROW_ON_ERROR);
        $signature = StripeSignature::header($payload, self::WEBHOOK_SECRET);

        $this->expectException(\RuntimeException::class);
        // Same valid signature, different payload — must not verify.
        $this->provider()->constructWebhookEvent($payload.' ', $signature);
    }

    public function testParsesASubscriptionUpdatedEventWithAKnownStatus(): void
    {
        $payload = $this->subscriptionEventPayload('customer.subscription.updated', 'active');

        $event = $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertSame('customer.subscription.updated', $event->type);
        self::assertNull($event->companyId);
        self::assertSame('cus_test123', $event->stripeCustomerId);
        self::assertSame('sub_test123', $event->stripeSubscriptionId);
        self::assertSame('active', $event->subscriptionStatus);
    }

    public function testMapsAnUnrecognizedStripeStatusToCanceled(): void
    {
        // 'unpaid' is a real Stripe subscription status this app has no dedicated name
        // for — see StripeBillingProvider's own docblock for why it maps to 'canceled'.
        $payload = $this->subscriptionEventPayload('customer.subscription.updated', 'unpaid');

        $event = $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertSame('canceled', $event->subscriptionStatus);
    }

    public function testParsesASubscriptionDeletedEvent(): void
    {
        $payload = $this->subscriptionEventPayload('customer.subscription.deleted', 'canceled');

        $event = $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertSame('customer.subscription.deleted', $event->type);
        self::assertSame('canceled', $event->subscriptionStatus);
    }

    public function testParsesACheckoutSessionCompletedEventWithTheCompanyIdAndActiveStatus(): void
    {
        $payload = json_encode([
            'id' => 'evt_2',
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test123',
                    'object' => 'checkout.session',
                    'client_reference_id' => 'company-uuid-here',
                    'customer' => 'cus_test123',
                    'subscription' => 'sub_test123',
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $event = $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertSame('checkout.session.completed', $event->type);
        self::assertSame('company-uuid-here', $event->companyId);
        self::assertSame('cus_test123', $event->stripeCustomerId);
        self::assertSame('sub_test123', $event->stripeSubscriptionId);
        self::assertSame('active', $event->subscriptionStatus);
    }

    public function testIgnoresAnUnrecognizedEventTypeWithoutThrowing(): void
    {
        $payload = json_encode([
            'id' => 'evt_3',
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_test123', 'object' => 'invoice']],
        ], \JSON_THROW_ON_ERROR);

        $event = $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertSame('invoice.paid', $event->type);
        self::assertNull($event->companyId);
        self::assertNull($event->subscriptionStatus);
    }

    public function testCreateCheckoutSessionThrowsWhenNotConfigured(): void
    {
        $provider = new StripeBillingProvider('', '', '');

        $this->expectException(BillingNotConfiguredException::class);
        $provider->createCheckoutSession(new Company('Acme'), 'https://example.com/ok', 'https://example.com/cancel');
    }

    private function subscriptionEventPayload(string $type, string $status): string
    {
        return json_encode([
            'id' => 'evt_1',
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'type' => $type,
            'data' => [
                'object' => [
                    'id' => 'sub_test123',
                    'object' => 'subscription',
                    'customer' => 'cus_test123',
                    'status' => $status,
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
    }
}
