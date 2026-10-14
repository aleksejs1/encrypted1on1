<?php

namespace App\Tests\Unit\Billing;

use App\Billing\BillingCustomerMissingException;
use App\Billing\BillingNotConfiguredException;
use App\Billing\BillingProviderException;
use App\Billing\Plan;
use App\Billing\PlusPrice;
use App\Billing\StripeBillingProvider;
use App\Billing\SubscriptionSnapshot;
use App\Entity\Company;
use App\Tests\Support\StripeSignature;
use App\Tests\Support\StubStripeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
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
 * from the sandbox; reading the price, with a price object saved from the sandbox too;
 * the limits given to the SDK's HTTP client.
 */
class StripeBillingProviderTest extends TestCase
{
    private const string WEBHOOK_SECRET = 'whsec_test_secret';

    private const string SESSION_JSON = '{"id":"cs_test_1","object":"checkout.session","url":"https://checkout.stripe.com/c/pay/cs_test_1"}';

    private const string MISSING_CUSTOMER_JSON = '{"error":{"type":"invalid_request_error","code":"resource_missing","param":"customer","message":"No such customer: \'cus_gone\'"}}';

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

    public function testRejectsEveryPayloadWhenNoWebhookSecretIsConfigured(): void
    {
        $payload = $this->subscriptionEventPayload('customer.subscription.updated', 'active');

        $this->expectException(\RuntimeException::class);
        // Signed with the empty secret, which is what an unconfigured instance has.
        (new StripeBillingProvider('', '', ''))->constructWebhookEvent($payload, StripeSignature::header($payload, ''));
    }

    public function testRejectsATamperedPayload(): void
    {
        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => []]], \JSON_THROW_ON_ERROR);
        $signature = StripeSignature::header($payload, self::WEBHOOK_SECRET);

        $this->expectException(\RuntimeException::class);
        // Same valid signature, different payload — must not verify.
        $this->provider()->constructWebhookEvent($payload.' ', $signature);
    }

    #[TestWith(['customer.subscription.created'])]
    #[TestWith(['customer.subscription.updated'])]
    #[TestWith(['customer.subscription.deleted'])]
    public function testASubscriptionEventNamesItsSubscriptionAndNothingElse(string $type): void
    {
        $payload = $this->subscriptionEventPayload($type, 'past_due');

        $event = $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertSame($type, $event->type);
        self::assertSame('sub_test123', $event->subscriptionId);
        self::assertNull($event->checkoutCompanyId);
    }

    public function testACompletedCheckoutNamesItsSubscriptionAndTheCompanyItWasStartedFor(): void
    {
        $payload = $this->checkoutEventPayload(['subscription' => 'sub_test123', 'client_reference_id' => 'company-uuid-here']);

        $event = $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertSame('checkout.session.completed', $event->type);
        self::assertSame('sub_test123', $event->subscriptionId);
        self::assertSame('company-uuid-here', $event->checkoutCompanyId);
    }

    public function testACompletedCheckoutWithoutASubscriptionIsNotActedOn(): void
    {
        // A one-time payment session, which this app never creates.
        $payload = $this->checkoutEventPayload(['subscription' => null, 'client_reference_id' => 'company-uuid-here']);

        $event = $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertNull($event->subscriptionId);
    }

    public function testAnEventTypeTheAppDoesNotActOnNamesNoSubscription(): void
    {
        $payload = json_encode([
            'id' => 'evt_3',
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_test123', 'object' => 'invoice', 'subscription' => 'sub_test123']],
        ], \JSON_THROW_ON_ERROR);

        $event = $this->provider()->constructWebhookEvent($payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertSame('invoice.paid', $event->type);
        self::assertNull($event->subscriptionId);
        self::assertNull($event->checkoutCompanyId);
    }

    /** @param array{string, string, string} $settings */
    #[DataProvider('incompleteSettings')]
    public function testCreateCheckoutSessionThrowsUnlessEverySettingIsSet(array $settings): void
    {
        $http = new StubStripeHttpClient();
        $provider = new StripeBillingProvider($settings[0], $settings[1], $settings[2], $http);

        try {
            $provider->createCheckoutSession(new Company('Acme'), 'https://example.com/ok', 'https://example.com/cancel');
            self::fail('Expected a BillingNotConfiguredException.');
        } catch (BillingNotConfiguredException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return array<string, array{array{string, string, string}}> */
    public static function incompleteSettings(): array
    {
        return [
            'nothing' => [['', '', '']],
            'no secret key' => [['', self::WEBHOOK_SECRET, 'price_whatever']],
            'no webhook secret' => [['sk_test_whatever', '', 'price_whatever']],
            'no price' => [['sk_test_whatever', self::WEBHOOK_SECRET, '']],
        ];
    }

    public function testCreateCheckoutSessionWrapsAStripeRefusal(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(400, '{"error":{"type":"invalid_request_error","message":"No such price: \'prod_123\'"}}');

        try {
            $this->providerWith($http)->createCheckoutSession(new Company('Acme'), 'https://example.com/ok', 'https://example.com/cancel');
            self::fail('Expected a BillingProviderException.');
        } catch (BillingProviderException $e) {
            self::assertStringContainsString('No such price', $e->getMessage());
            self::assertInstanceOf(ApiErrorException::class, $e->getPrevious());
        }
    }

    public function testCreateCheckoutSessionTagsTheSubscriptionAndExpiresSoon(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(200, self::SESSION_JSON);
        $company = new Company('Acme');
        $before = time();

        $url = $this->providerWith($http)->createCheckoutSession($company, 'https://example.com/ok', 'https://example.com/cancel');

        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $url);
        self::assertCount(1, $http->requests);
        $params = $http->requests[0]['params'];
        self::assertSame('subscription', $params['mode']);
        self::assertSame([['price' => 'price_whatever', 'quantity' => 1]], $params['line_items']);
        self::assertSame('https://example.com/ok', $params['success_url']);
        self::assertSame('https://example.com/cancel', $params['cancel_url']);
        self::assertSame($company->getId(), $params['client_reference_id']);
        self::assertSame(['metadata' => ['company_id' => $company->getId()]], $params['subscription_data']);
        // Stripe's minimum is 30 minutes; a little more, never an hour.
        self::assertGreaterThan($before + 30 * 60, $params['expires_at']);
        self::assertLessThanOrEqual(time() + 40 * 60, $params['expires_at']);
        // A company that never bought has no customer to reuse: Stripe makes one.
        self::assertArrayNotHasKey('customer', $params);
    }

    public function testCreateCheckoutSessionReusesTheCompanysStripeCustomer(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(200, self::SESSION_JSON);

        $this->providerWith($http)->createCheckoutSession($this->companyWithCustomer('cus_known'), 'https://example.com/ok', 'https://example.com/cancel');

        self::assertSame('cus_known', $http->requests[0]['params']['customer']);
    }

    public function testCreateCheckoutSessionSaysWhenTheStoredCustomerIsGone(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(400, self::MISSING_CUSTOMER_JSON);

        $this->expectException(BillingCustomerMissingException::class);
        $this->providerWith($http)->createCheckoutSession($this->companyWithCustomer('cus_gone'), 'https://example.com/ok', 'https://example.com/cancel');
    }

    /** Something else missing (the price, say) is an ordinary refusal: retrying without the customer wouldn't help. */
    #[TestWith(['{"error":{"type":"invalid_request_error","code":"resource_missing","param":"line_items[0][price]","message":"No such price"}}'])]
    #[TestWith(['{"error":{"type":"invalid_request_error","code":"parameter_invalid","param":"customer","message":"Invalid customer"}}'])]
    public function testAnotherRefusalWithACustomerIsNotAMissingCustomer(string $answer): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(400, $answer);

        try {
            $this->providerWith($http)->createCheckoutSession($this->companyWithCustomer('cus_known'), 'https://example.com/ok', 'https://example.com/cancel');
            self::fail('Expected a BillingProviderException.');
        } catch (BillingProviderException $e) {
            self::assertNotInstanceOf(BillingCustomerMissingException::class, $e);
        }
    }

    /** Without a stored customer there is nothing to forget, whatever Stripe says is missing. */
    public function testAMissingCustomerAnswerWithoutAStoredCustomerIsAnOrdinaryRefusal(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(400, self::MISSING_CUSTOMER_JSON);

        try {
            $this->providerWith($http)->createCheckoutSession(new Company('Acme'), 'https://example.com/ok', 'https://example.com/cancel');
            self::fail('Expected a BillingProviderException.');
        } catch (BillingProviderException $e) {
            self::assertNotInstanceOf(BillingCustomerMissingException::class, $e);
        }
    }

    private function companyWithCustomer(string $customerId): Company
    {
        $company = new Company('Acme', seatLimit: 100);
        $company->applySubscription(new SubscriptionSnapshot('sub_old', $customerId, 'active', null, null, null), new Plan(100, 1000));
        $company->applySubscription(new SubscriptionSnapshot('sub_old', $customerId, 'canceled', null, null, null), new Plan(100, 1000));

        return $company;
    }

    public function testCreateCheckoutSessionRefusesASessionWithoutAUrl(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(200, '{"id":"cs_test_1","object":"checkout.session","url":null}');

        $this->expectException(BillingProviderException::class);
        $this->providerWith($http)->createCheckoutSession(new Company('Acme'), 'https://example.com/ok', 'https://example.com/cancel');
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

    public function testRetrievePlusPriceThrowsUnlessEverySettingIsSet(): void
    {
        $http = new StubStripeHttpClient();

        try {
            new StripeBillingProvider('sk_test_whatever', '', 'price_whatever', $http)->retrievePlusPrice();
            self::fail('Expected a BillingNotConfiguredException.');
        } catch (BillingNotConfiguredException) {
            self::assertSame([], $http->requests);
        }
    }

    public function testRetrievePlusPriceAsksStripeForTheConfiguredPrice(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(200, $this->fixtureJson('price-monthly'));

        $price = $this->providerWith($http)->retrievePlusPrice();

        self::assertCount(1, $http->requests);
        self::assertSame('get', $http->requests[0]['method']);
        self::assertSame('https://api.stripe.com/v1/prices/price_whatever', $http->requests[0]['url']);
        self::assertSame(2000, $price->amount);
        self::assertSame('eur', $price->currency);
        self::assertSame('month', $price->interval);
        self::assertSame('exclusive', $price->taxBehavior);
    }

    public function testAPriceWithoutATaxBehaviorIsUnspecified(): void
    {
        $price = $this->plusPriceFrom(['tax_behavior' => null]);

        self::assertSame('unspecified', $price->taxBehavior);
    }

    public function testAPriceThatIncludesTaxSaysSo(): void
    {
        self::assertSame('inclusive', $this->plusPriceFrom(['tax_behavior' => 'inclusive'])->taxBehavior);
    }

    /**
     * Nothing the page could print as "20 per month": a tiered price, a one-time one,
     * one charged every three months, per unit of usage or per ten units, and an
     * archived one.
     *
     * @param array<string, mixed> $changes
     */
    #[TestWith([['unit_amount' => null, 'billing_scheme' => 'tiered']])]
    #[TestWith([['recurring' => null, 'type' => 'one_time']])]
    #[TestWith([['recurring' => ['interval' => 'month', 'interval_count' => 3, 'usage_type' => 'licensed']]])]
    #[TestWith([['recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'metered']]])]
    #[TestWith([['transform_quantity' => ['divide_by' => 10, 'round' => 'up']]])]
    #[TestWith([['active' => false]])]
    public function testRetrievePlusPriceRefusesAPriceThatIsNotAFlatAmountPerPeriod(array $changes): void
    {
        try {
            $this->plusPriceFrom($changes);
            self::fail('Expected a BillingProviderException.');
        } catch (BillingProviderException $e) {
            self::assertSame('Price price_whatever is archived, or is not a flat amount per single day, week, month or year.', $e->getMessage());
        }
    }

    public function testRetrievePlusPriceRefusesAnAnswerWithAFieldMissing(): void
    {
        try {
            $this->plusPriceFrom(['currency' => null]);
            self::fail('Expected a BillingProviderException.');
        } catch (BillingProviderException $e) {
            self::assertSame('Stripe returned price price_whatever with a field missing.', $e->getMessage());
            self::assertInstanceOf(\TypeError::class, $e->getPrevious());
        }
    }

    public function testRetrievePlusPriceWrapsAStripeRefusal(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(404, '{"error":{"type":"invalid_request_error","code":"resource_missing","message":"No such price: \'price_whatever\'"}}');

        try {
            $this->providerWith($http)->retrievePlusPrice();
            self::fail('Expected a BillingProviderException.');
        } catch (BillingProviderException $e) {
            self::assertSame("Stripe did not return the price: No such price: 'price_whatever'", $e->getMessage());
            self::assertInstanceOf(ApiErrorException::class, $e->getPrevious());
        }
    }

    public function testRetrievePlusPriceWrapsNoAnswerInTime(): void
    {
        $http = new StubStripeHttpClient();
        $http->willNotAnswer();

        $this->expectException(BillingProviderException::class);
        $this->providerWith($http)->retrievePlusPrice();
    }

    /** A page waits for the price: three seconds, connecting included, and the next call has its own limits back. */
    public function testThePriceIsReadWithAShorterTimeout(): void
    {
        $http = new StubStripeHttpClient();
        $http->willAnswer(200, $this->fixtureJson('price-monthly'));
        $http->willAnswer(200, $this->fixtureJson('subscription-canceled-tagged'));
        $curl = CurlClient::instance();
        self::assertInstanceOf(CurlClient::class, $curl);

        $this->providerWith($http)->retrievePlusPrice();
        self::assertSame(3, $curl->getTimeout());
        self::assertSame(3, $curl->getConnectTimeout());

        $this->providerWith($http)->retrieveSubscription('sub_fixture1');
        self::assertSame(10, $curl->getTimeout());
        self::assertSame(5, $curl->getConnectTimeout());
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function plusPriceFrom(array $changes): PlusPrice
    {
        $price = json_decode($this->fixtureJson('price-monthly'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($price);
        $http = new StubStripeHttpClient();
        $http->willAnswer(200, json_encode($changes + $price, \JSON_THROW_ON_ERROR));

        return $this->providerWith($http)->retrievePlusPrice();
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

    /**
     * @param array<string, mixed> $session
     */
    private function checkoutEventPayload(array $session): string
    {
        return json_encode([
            'id' => 'evt_2',
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test123', 'object' => 'checkout.session', 'customer' => 'cus_test123'] + $session],
        ], \JSON_THROW_ON_ERROR);
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
