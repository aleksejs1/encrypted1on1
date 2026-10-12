<?php

namespace App\Tests\Functional;

use App\Billing\StripeBillingProvider;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FakeBillingProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * What both billing routes do before any Stripe call, and around it: who may ask for a
 * checkout session, and what the webhook accepts. How a webhook event changes a company
 * is deliberately not covered here: that logic is being replaced (GitHub issue #286,
 * step B3), and gets its tests there.
 *
 * Everything runs against the real StripeBillingProvider except the one test that needs
 * Stripe to answer.
 */
class BillingControllerTest extends ApiTestCase
{
    private const string WEBHOOK_SECRET = 'whsec_test_secret';

    public function testCheckoutSessionRequiresALogin(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'POST', '/api/billing/checkout-session');

        self::assertSame(401, $result['status']);
    }

    public function testCheckoutSessionRequiresACsrfToken(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('billing-csrf'), admin: true);

        $client->request('POST', '/api/billing/checkout-session');

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCheckoutSessionIsForCompanyAdminsOnly(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('billing-employee'));

        $result = $this->jsonRequest($client, 'POST', '/api/billing/checkout-session');

        self::assertSame(403, $result['status']);
        self::assertSame('Admin only.', $result['json']['error']);
    }

    public function testCheckoutSessionIsRefusedOutsideCloudMode(): void
    {
        $this->withEnv('CLOUD_MODE', '0');
        $client = static::createClient();
        $fake = $this->useFakeProvider($client);
        $this->activateUser($client, $this->uniqueEmail('billing-self-hosted'), admin: true);

        $result = $this->jsonRequest($client, 'POST', '/api/billing/checkout-session');

        self::assertSame(400, $result['status']);
        self::assertSame([], $fake->checkoutSessions);
    }

    public function testCheckoutSessionAnswers503WhenBillingIsNotConfigured(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $this->withEnv('STRIPE_SECRET_KEY', '');
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('billing-unconfigured'), admin: true);

        $result = $this->jsonRequest($client, 'POST', '/api/billing/checkout-session');

        self::assertSame(503, $result['status']);
        self::assertSame('Billing is not configured on this instance.', $result['json']['error']);
    }

    public function testCheckoutSessionReturnsTheUrlForTheAdminsOwnCompany(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $fake = $this->useFakeProvider($client);
        $this->activateUser($client, $this->uniqueEmail('billing-admin'), admin: true);

        $result = $this->jsonRequest($client, 'POST', '/api/billing/checkout-session');

        self::assertSame(200, $result['status']);
        self::assertSame(['url' => FakeBillingProvider::CHECKOUT_URL], $result['json']);
        self::assertCount(1, $fake->checkoutSessions);
        self::assertSame($this->singleCompanyProvider()->get()->getId(), $fake->checkoutSessions[0]['companyId']);
        self::assertStringStartsWith('http://localhost:5173/', $fake->checkoutSessions[0]['successUrl']);
        self::assertStringStartsWith('http://localhost:5173/', $fake->checkoutSessions[0]['cancelUrl']);
    }

    public function testWebhookRejectsABadSignature(): void
    {
        $this->withEnv('STRIPE_WEBHOOK_SECRET', self::WEBHOOK_SECRET);
        $client = static::createClient();
        $payload = $this->eventPayload();

        $this->postWebhook($client, $payload, $this->sign($payload, 'another-secret'));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testWebhookRejectsARequestWithoutASignature(): void
    {
        $this->withEnv('STRIPE_WEBHOOK_SECRET', self::WEBHOOK_SECRET);
        $client = static::createClient();

        $this->postWebhook($client, $this->eventPayload(), null);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    /**
     * Stripe has no session and no CSRF token: the route is exempt by its name,
     * `billing_webhook` (CsrfProtectionListener), and the signature is what protects it.
     */
    public function testWebhookAcceptsASignedRequestWithoutACsrfTokenOrASession(): void
    {
        $this->withEnv('STRIPE_WEBHOOK_SECRET', self::WEBHOOK_SECRET);
        $client = static::createClient();
        $payload = $this->eventPayload();

        $this->postWebhook($client, $payload, $this->sign($payload, self::WEBHOOK_SECRET));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('{"received":true}', $client->getResponse()->getContent());
    }

    /**
     * jsonRequest() fetches a CSRF token first, and the browser would then boot a fresh
     * kernel, without the fake, for the request under test: hence disableReboot().
     */
    private function useFakeProvider(KernelBrowser $client): FakeBillingProvider
    {
        $client->disableReboot();
        $fake = new FakeBillingProvider();
        static::getContainer()->set(StripeBillingProvider::class, $fake);

        return $fake;
    }

    private function postWebhook(KernelBrowser $client, string $payload, ?string $signature): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $signature) {
            $headers['HTTP_STRIPE_SIGNATURE'] = $signature;
        }

        $client->request('POST', '/api/billing/webhook', server: $headers, content: $payload);
    }

    /** An event type the app ignores: accepted, and nothing to apply. */
    private function eventPayload(): string
    {
        return json_encode([
            'id' => 'evt_test',
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_test', 'object' => 'invoice']],
        ], \JSON_THROW_ON_ERROR);
    }

    /** The scheme Stripe documents, as in StripeBillingProviderTest::signPayload(). */
    private function sign(string $payload, string $secret): string
    {
        $timestamp = time();

        return \sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', "{$timestamp}.{$payload}", $secret));
    }
}
