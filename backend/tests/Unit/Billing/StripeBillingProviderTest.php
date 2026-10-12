<?php

namespace App\Tests\Unit\Billing;

use App\Billing\BillingNotConfiguredException;
use App\Billing\BillingProviderException;
use App\Billing\StripeBillingProvider;
use App\Entity\Company;
use App\Tests\Support\StripeSignature;
use App\Tests\Support\StubStripeHttpClient;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\CurlClient;
use Stripe\Subscription;

/**
 * StripeBillingProvider without a Stripe account and without the network: webhook
 * signature verification (local HMAC, see StripeSignature) and the parsing of the three
 * event types; the not-configured refusals; reading a subscription, with
 * StubStripeHttpClient answering in Stripe's place and with subscription objects saved
 * from the sandbox; the limits given to the SDK's HTTP client.
 */
class StripeBillingProviderTest extends TestCase
{
    private const string WEBHOOK_SECRET = 'whsec_test_secret';

    /** The SDK's HTTP client and its limits are per process: put back what a test changed. */
    protected function tearDown(): void
    {
        $http = CurlClient::instance();
        \assert($http instanceof CurlClient);
        $http->setTimeout(CurlClient::DEFAULT_TIMEOUT);
        $http->setConnectTimeout(CurlClient::DEFAULT_CONNECT_TIMEOUT);
        ApiRequestor::setHttpClient($http);

        parent::tearDown();
    }

    private function providerWith(StubStripeHttpClient $http): StripeBillingProvider
    {
        return new StripeBillingProvider('sk_test_whatever', self::WEBHOOK_SECRET, 'price_whatever', $http);
    }

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

    public function testRetrieveSubscriptionThrowsWhenNotConfigured(): void
    {
        $this->expectException(BillingNotConfiguredException::class);
        (new StripeBillingProvider('', '', ''))->retrieveSubscription('sub_test123');
    }

    public function testRetrieveSubscriptionAsksStripeAndReturnsTheSnapshot(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(200, $this->fixtureJson('subscription-canceled-tagged'));

        $snapshot = $this->providerWith($http)->retrieveSubscription('sub_fixture1');

        self::assertCount(1, $http->requests);
        self::assertSame('get', $http->requests[0]['method']);
        self::assertSame('https://api.stripe.com/v1/subscriptions/sub_fixture1', $http->requests[0]['url']);
        self::assertSame('canceled', $snapshot->status);
        self::assertSame('0199d3a0-0000-7000-8000-000000000001', $snapshot->companyId);
        self::assertSame(1794449998, $snapshot->periodEndsAt?->getTimestamp());
    }

    public function testRetrieveSubscriptionWrapsAStripeRefusal(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(404, '{"error":{"type":"invalid_request_error","code":"resource_missing","message":"No such subscription: \'sub_gone\'"}}');

        try {
            $this->providerWith($http)->retrieveSubscription('sub_gone');
            self::fail('Expected a BillingProviderException.');
        } catch (BillingProviderException $e) {
            self::assertStringContainsString('No such subscription', $e->getMessage());
            self::assertInstanceOf(ApiErrorException::class, $e->getPrevious());
        }
    }

    public function testRetrieveSubscriptionWrapsAnAnswerThatIsNotStripes(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(502, '<html><body>Bad Gateway</body></html>');

        $this->expectException(BillingProviderException::class);
        $this->providerWith($http)->retrieveSubscription('sub_fixture1');
    }

    public function testSnapshotOfAnActiveSubscriptionWithoutACompanyTag(): void
    {
        $snapshot = StripeBillingProvider::snapshotOf($this->subscriptionFixture('subscription-active-untagged'));

        self::assertSame('sub_fixture1', $snapshot->id);
        self::assertSame('cus_fixture1', $snapshot->customerId);
        self::assertSame('active', $snapshot->status);
        // The period end is on the subscription item; the subscription has no such field.
        self::assertSame(1794412367, $snapshot->periodEndsAt?->getTimestamp());
        self::assertNull($snapshot->cancelsAt);
        self::assertNull($snapshot->companyId);
    }

    public function testSnapshotOfASubscriptionWithAScheduledCancellation(): void
    {
        $snapshot = StripeBillingProvider::snapshotOf($this->subscriptionFixture('subscription-cancellation-scheduled'));

        self::assertSame('active', $snapshot->status);
        self::assertSame(1794412367, $snapshot->cancelsAt?->getTimestamp());
        self::assertSame(1794412367, $snapshot->periodEndsAt?->getTimestamp());
    }

    public function testSnapshotOfAnEndedSubscriptionKeepsItsCompanyTag(): void
    {
        $snapshot = StripeBillingProvider::snapshotOf($this->subscriptionFixture('subscription-canceled-tagged'));

        self::assertSame('canceled', $snapshot->status);
        self::assertSame('0199d3a0-0000-7000-8000-000000000001', $snapshot->companyId);
        self::assertSame(1794449998, $snapshot->periodEndsAt?->getTimestamp());
        self::assertNull($snapshot->cancelsAt);
    }

    public function testSnapshotDatesAreInTheDefaultTimezone(): void
    {
        $snapshot = StripeBillingProvider::snapshotOf($this->subscriptionFixture('subscription-cancellation-scheduled'));

        self::assertSame(date_default_timezone_get(), $snapshot->cancelsAt?->getTimezone()->getName());
        self::assertSame(date_default_timezone_get(), $snapshot->periodEndsAt?->getTimezone()->getName());
    }

    public function testSnapshotOfASubscriptionWithoutItemsOrACustomerId(): void
    {
        $subscription = Subscription::constructFrom([
            'id' => 'sub_bare',
            'object' => 'subscription',
            'status' => 'incomplete',
            'customer' => ['id' => 'cus_expanded', 'object' => 'customer'],
            'cancel_at' => null,
            'items' => ['object' => 'list', 'data' => []],
            'metadata' => ['company_id' => ''],
        ]);

        $snapshot = StripeBillingProvider::snapshotOf($subscription);

        self::assertSame('incomplete', $snapshot->status);
        self::assertNull($snapshot->customerId);
        self::assertNull($snapshot->periodEndsAt);
        self::assertNull($snapshot->companyId);
    }

    /**
     * The SDK's HTTP client is one per process, so what client() sets is what the next
     * request uses.
     */
    public function testTheStripeClientGetsShortTimeouts(): void
    {
        (new \ReflectionMethod(StripeBillingProvider::class, 'client'))->invoke($this->provider());

        $http = ApiRequestor::httpClient();
        self::assertInstanceOf(CurlClient::class, $http);
        self::assertSame(10, $http->getTimeout());
        self::assertSame(5, $http->getConnectTimeout());
    }

    /** A subscription object saved from the Stripe sandbox, ids replaced (tests/Fixtures/stripe). */
    private function subscriptionFixture(string $name): Subscription
    {
        $data = json_decode($this->fixtureJson($name), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return Subscription::constructFrom($data);
    }

    private function fixtureJson(string $name): string
    {
        $json = file_get_contents(__DIR__.'/../../Fixtures/stripe/'.$name.'.json');
        self::assertIsString($json);

        return $json;
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
