<?php

namespace App\Tests\Functional;

use App\Billing\Plan;
use App\Billing\StripeBillingProvider;
use App\Billing\SubscriptionSnapshot;
use App\Entity\Company;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;
use App\Tests\Support\FakeBillingProvider;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StripeSignature;
use App\Tests\Support\StubStripeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The billing routes: who may ask for a checkout session, what the Plan page is told
 * (the overview and the price of Plus), what the webhook accepts, and what a
 * subscription event does to a company. Which subscription means what is
 * Company::applySubscription()'s rule, tested in CompanyTest; here it is the route,
 * finding the company, and Stripe not answering.
 *
 * The tests run against the real StripeBillingProvider. Where Stripe has to answer,
 * StubStripeHttpClient does (the webhook tests), or FakeBillingProvider stands in for
 * the whole provider (the checkout tests that need to see whether it was called).
 */
class BillingControllerTest extends ApiTestCase
{
    use CleansUpCompanies;

    private const array COMPANY_TABLES = ['invite_records', 'activation_tokens', 'users'];

    private const string WEBHOOK_SECRET = 'whsec_test_secret';

    private const string CHECKOUT = '/api/admin/billing/checkout-session';

    private const string OVERVIEW = '/api/admin/billing';

    private const string PLUS_PRICE = '/api/admin/billing/plus-price';

    public function testOverviewRequiresALogin(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();

        self::assertSame(401, $this->jsonRequest($client, 'GET', self::OVERVIEW)['status']);
    }

    public function testOverviewIsForCompanyAdminsOnly(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('overview-employee'), company: $this->makeCompany('Employee Co'));

        $result = $this->jsonRequest($client, 'GET', self::OVERVIEW);

        self::assertSame(403, $result['status']);
        self::assertSame('Admin only.', $result['json']['error']);
    }

    public function testOverviewDoesNotExistOutsideCloudMode(): void
    {
        $this->withEnv('CLOUD_MODE', '0');
        $client = static::createClient();

        self::assertSame(404, $this->jsonRequest($client, 'GET', self::OVERVIEW)['status']);

        $this->activateUser($client, $this->uniqueEmail('overview-self-hosted'), admin: true);
        $result = $this->jsonRequest($client, 'GET', self::OVERVIEW);

        self::assertSame(404, $result['status']);
        self::assertSame('Billing is not configured on this instance.', $result['json']['error']);
    }

    /** The whole answer, for a company that never paid: its own seats, not another company's. */
    public function testOverviewOfAFreeCompany(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $this->withEnv('DEFAULT_SEAT_LIMIT', '50');
        $this->withEnv('PLUS_SEAT_LIMIT', '500');
        $this->withEnv('SUPPORT_EMAIL', ' support@example.com ');
        $this->configureStripe();
        $client = static::createClient();
        $company = $this->makeCompany('Free Co');
        $this->activateUser($client, $this->uniqueEmail('overview-free'), admin: true, company: $company);
        self::assertSame(201, $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('overview-invitee')])['status']);
        $this->activateUser($this->secondClient(), $this->uniqueEmail('overview-bystander'), admin: true, company: $this->makeCompany('Bystander Co'));

        $result = $this->jsonRequest($client, 'GET', self::OVERVIEW);

        self::assertSame(200, $result['status']);
        self::assertSame([
            'state' => 'free',
            'planTier' => 'free',
            // The limit stored on the company, which is not the plan's current number.
            'seatLimit' => 100,
            'activeUsers' => 1,
            'pendingInvites' => 1,
            'periodEndsAt' => null,
            'cancelsAt' => null,
            'hasInvoices' => false,
            'freeSeatLimit' => 50,
            'plusSeatLimit' => 500,
            'supportEmail' => 'support@example.com',
        ], $result['json']);
    }

    public function testOverviewOfACompanyWhoseSubscriptionIsEnding(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        // Empty unless an operator sets it; a stray space is empty too.
        $this->withEnv('SUPPORT_EMAIL', ' ');
        $this->configureStripe();
        $client = static::createClient();
        $company = $this->makeCompany('Ending Co');
        $periodEnd = new \DateTimeImmutable('2026-11-11T09:30:00+00:00');
        $company->applySubscription(new SubscriptionSnapshot('sub_own', 'cus_own', 'active', $periodEnd, $periodEnd, null), new Plan(100, 1000));
        $this->entityManager()->flush();
        $this->activateUser($client, $this->uniqueEmail('overview-ending'), admin: true, company: $company);

        $json = $this->jsonRequest($client, 'GET', self::OVERVIEW)['json'];

        self::assertSame('plus-ending', $json['state']);
        self::assertSame('plus', $json['planTier']);
        self::assertSame(1000, $json['seatLimit']);
        self::assertSame($periodEnd->getTimestamp(), (new \DateTimeImmutable($json['periodEndsAt']))->getTimestamp());
        self::assertSame($periodEnd->getTimestamp(), (new \DateTimeImmutable($json['cancelsAt']))->getTimestamp());
        self::assertTrue($json['hasInvoices']);
        self::assertNull($json['supportEmail']);
    }

    public function testOverviewOfACompanyWhoseLastPaymentFailed(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $this->configureStripe();
        $client = static::createClient();
        $company = $this->makeCompany('Past Due Co');
        $company->applySubscription(new SubscriptionSnapshot('sub_own', 'cus_own', 'past_due', new \DateTimeImmutable('2026-11-11'), null, null), new Plan(100, 1000));
        $this->entityManager()->flush();
        $this->activateUser($client, $this->uniqueEmail('overview-past-due'), admin: true, company: $company);

        $json = $this->jsonRequest($client, 'GET', self::OVERVIEW)['json'];

        self::assertSame('plus-past-due', $json['state']);
        self::assertSame('plus', $json['planTier']);
        self::assertNotNull($json['periodEndsAt']);
        self::assertNull($json['cancelsAt']);
        self::assertTrue($json['hasInvoices']);
    }

    /** One setting missing is "not configured": nothing to buy, and no portal to open for a former payer. */
    #[DataProvider('stripeSettings')]
    public function testOverviewWithoutStripeOffersNoPaymentAndNoInvoices(string $missing): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $this->configureStripe(except: $missing);
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('overview-unconfigured'), admin: true, company: $this->lapsedCompany('Lapsed Co'));

        $json = $this->jsonRequest($client, 'GET', self::OVERVIEW)['json'];

        self::assertSame('free-unavailable', $json['state']);
        self::assertFalse($json['hasInvoices']);
    }

    /** Free with a Stripe customer: the page links to the earlier invoices. */
    public function testOverviewOfAFormerPayerHasInvoices(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $this->configureStripe();
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('overview-lapsed'), admin: true, company: $this->lapsedCompany('Lapsed Co'));

        $json = $this->jsonRequest($client, 'GET', self::OVERVIEW)['json'];

        self::assertSame('free', $json['state']);
        self::assertSame(100, $json['seatLimit']);
        self::assertTrue($json['hasInvoices']);
    }

    public function testOverviewOfAPlanSetByHandWithoutASeatLimit(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $this->configureStripe();
        $client = static::createClient();
        $company = new Company('Enterprise Co', planTier: 'enterprise');
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();
        $this->activateUser($client, $this->uniqueEmail('overview-enterprise'), admin: true, company: $company);

        $json = $this->jsonRequest($client, 'GET', self::OVERVIEW)['json'];

        self::assertSame('custom', $json['state']);
        self::assertSame('enterprise', $json['planTier']);
        self::assertNull($json['seatLimit']);
        self::assertSame(1, $json['activeUsers']);
    }

    public function testPlusPriceRequiresALogin(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();

        self::assertSame(401, $this->jsonRequest($client, 'GET', self::PLUS_PRICE)['status']);
    }

    public function testPlusPriceIsForCompanyAdminsOnly(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        [$client, $stripe] = $this->webhookClient();
        $this->activateUser($client, $this->uniqueEmail('price-employee'), company: $this->makeCompany('Employee Co'));

        $result = $this->jsonRequest($client, 'GET', self::PLUS_PRICE);

        self::assertSame(403, $result['status']);
        self::assertSame([], $stripe->requests);
    }

    public function testPlusPriceDoesNotExistOutsideCloudMode(): void
    {
        $this->withEnv('CLOUD_MODE', '0');
        [$client, $stripe] = $this->webhookClient();
        $this->activateUser($client, $this->uniqueEmail('price-self-hosted'), admin: true);

        $result = $this->jsonRequest($client, 'GET', self::PLUS_PRICE);

        self::assertSame(404, $result['status']);
        self::assertSame([], $stripe->requests);
    }

    public function testPlusPriceIsThePriceStripeHas(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        [$client, $stripe] = $this->webhookClient();
        $this->activateUser($client, $this->uniqueEmail('price-admin'), admin: true, company: $this->makeCompany('Asking Co'));
        $json = file_get_contents(__DIR__.'/../Fixtures/stripe/price-monthly.json');
        self::assertIsString($json);
        $stripe->willAnswer(200, $json);

        $result = $this->jsonRequest($client, 'GET', self::PLUS_PRICE);

        self::assertSame(200, $result['status']);
        self::assertSame(['amount' => 2000, 'currency' => 'eur', 'interval' => 'month', 'taxBehavior' => 'exclusive'], $result['json']);
        self::assertSame('https://api.stripe.com/v1/prices/price_whatever', $stripe->requests[0]['url']);
    }

    /** No price is no error to the page, which says the price is on the payment page. The operator's log gets one. */
    public function testPlusPriceIsNullWhenStripeDoesNotAnswerInTime(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        [$client, $stripe] = $this->webhookClient();
        $logger = $this->recordLogs();
        $this->activateUser($client, $this->uniqueEmail('price-timeout'), admin: true, company: $this->makeCompany('Waiting Co'));
        $stripe->willNotAnswer();

        $client->request('GET', self::PLUS_PRICE);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('null', $client->getResponse()->getContent());
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        $errors = $logger->atLeast('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('The Plus price could not be read from Stripe: Stripe did not return the price: Operation timed out', $errors[0]);
    }

    public function testPlusPriceIsNullWhenStripeIsNotConfigured(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $this->configureStripe(except: 'STRIPE_WEBHOOK_SECRET');
        $client = static::createClient();
        $client->disableReboot();
        $logger = $this->recordLogs();
        $this->activateUser($client, $this->uniqueEmail('price-unconfigured'), admin: true, company: $this->makeCompany('Unconfigured Co'));

        $client->request('GET', self::PLUS_PRICE);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('null', $client->getResponse()->getContent());
        self::assertSame([], $logger->atLeast('warning'));
    }

    public function testCheckoutSessionRequiresALogin(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(401, $result['status']);
    }

    public function testCheckoutSessionRequiresACsrfToken(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('billing-csrf'), admin: true);

        $client->request('POST', self::CHECKOUT);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame('{"error":"Invalid CSRF token."}', $client->getResponse()->getContent());
    }

    public function testCheckoutSessionIsForCompanyAdminsOnly(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $fake = $this->useFakeProvider($client);
        $this->activateUser($client, $this->uniqueEmail('billing-employee'));

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(403, $result['status']);
        self::assertSame('Admin only.', $result['json']['error']);
        self::assertSame([], $fake->checkoutSessions);
    }

    /** A self-hosted instance sells nothing, to an admin or to anybody else (a request without a CSRF token still gets its 403 first). */
    public function testCheckoutSessionDoesNotExistOutsideCloudMode(): void
    {
        $this->withEnv('CLOUD_MODE', '0');
        $client = static::createClient();
        $fake = $this->useFakeProvider($client);

        self::assertSame(404, $this->jsonRequest($client, 'POST', self::CHECKOUT)['status']);

        $this->activateUser($client, $this->uniqueEmail('billing-self-hosted'), admin: true);
        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(404, $result['status']);
        self::assertSame('Billing is not configured on this instance.', $result['json']['error']);
        self::assertSame([], $fake->checkoutSessions);
    }

    public function testTheOldCheckoutPathIsGone(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('billing-old-path'), admin: true);

        self::assertSame(404, $this->jsonRequest($client, 'POST', '/api/billing/checkout-session')['status']);
    }

    /**
     * All three settings, the webhook secret included: without it the admin would pay
     * and no event could ever move the company to Plus.
     */
    #[DataProvider('stripeSettings')]
    public function testCheckoutSessionAnswers503UnlessEveryStripeSettingIsSet(string $missing): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $this->configureStripe(except: $missing);
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('billing-unconfigured'), admin: true, company: $this->makeCompany('Unconfigured Co'));

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(503, $result['status']);
        self::assertSame('Billing is not configured on this instance.', $result['json']['error']);
    }

    /** @return list<array{string}> */
    public static function stripeSettings(): array
    {
        return [['STRIPE_SECRET_KEY'], ['STRIPE_PRICE_ID'], ['STRIPE_WEBHOOK_SECRET']];
    }

    public function testCheckoutSessionReturnsTheUrlForTheAdminsOwnCompany(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $fake = $this->useFakeProvider($client);
        $company = $this->makeCompany('Buying Co');
        $this->makeCompany('Bystander Co');
        $this->activateUser($client, $this->uniqueEmail('billing-admin'), admin: true, company: $company);

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(200, $result['status']);
        self::assertSame(['url' => FakeBillingProvider::CHECKOUT_URL], $result['json']);
        self::assertCount(1, $fake->checkoutSessions);
        self::assertSame($company->getId(), $fake->checkoutSessions[0]['companyId']);
        // Both lead back to the app's Plan page (FRONTEND_URL), which tells them apart.
        self::assertSame('http://localhost:5173/admin/plan?checkout=success', $fake->checkoutSessions[0]['successUrl']);
        self::assertSame('http://localhost:5173/admin/plan?checkout=cancelled', $fake->checkoutSessions[0]['cancelUrl']);
    }

    /** #285, item 7: a second checkout made a second subscription, which billed as well. */
    #[DataProvider('payingStatuses')]
    public function testCheckoutSessionIsRefusedWhileTheCompanysSubscriptionPays(string $status): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $fake = $this->useFakeProvider($client);
        $company = $this->makeCompany('Subscribed Co');
        $company->applySubscription(new SubscriptionSnapshot('sub_own', 'cus_own', $status, null, null, null), new Plan(100, 1000));
        $this->entityManager()->flush();
        $this->activateUser($client, $this->uniqueEmail('billing-subscribed'), admin: true, company: $company);

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(409, $result['status']);
        self::assertSame('This company already has an active subscription.', $result['json']['error']);
        self::assertSame([], $fake->checkoutSessions);
    }

    /** @return list<array{string}> */
    public static function payingStatuses(): array
    {
        return [['active'], ['trialing'], ['past_due']];
    }

    public function testACompanyWhoseSubscriptionEndedCanBuyAgain(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $fake = $this->useFakeProvider($client);
        $company = $this->makeCompany('Lapsed Co');
        $plan = new Plan(100, 1000);
        $company->applySubscription(new SubscriptionSnapshot('sub_own', 'cus_own', 'active', null, null, null), $plan);
        $company->applySubscription(new SubscriptionSnapshot('sub_own', 'cus_own', 'canceled', null, null, null), $plan);
        $this->entityManager()->flush();
        $this->activateUser($client, $this->uniqueEmail('billing-lapsed'), admin: true, company: $company);

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(200, $result['status']);
        self::assertCount(1, $fake->checkoutSessions);
    }

    /** The webhook leaves a hand-set plan alone, so the payment would buy nothing. */
    public function testCheckoutSessionIsRefusedForAPlanSetByHand(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $fake = $this->useFakeProvider($client);
        $company = new Company('Enterprise Co', seatLimit: 5000, planTier: 'enterprise');
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();
        $this->activateUser($client, $this->uniqueEmail('billing-enterprise'), admin: true, company: $company);

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(409, $result['status']);
        self::assertSame("This company's plan is set by agreement and can't be bought here.", $result['json']['error']);
        self::assertSame([], $fake->checkoutSessions);
    }

    /**
     * 503 with the app's own JSON: a 502 would read as "the backend is unreachable" in
     * the frontend. Stripe's own words go to the log
     * (JsonExceptionListener), not to the admin.
     */
    public function testCheckoutSessionAnswers503AndLogsWhenStripeRefuses(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        [$client, $stripe] = $this->webhookClient();
        $logger = $this->recordLogs();
        $company = $this->makeCompany('Refused Co');
        $this->activateUser($client, $this->uniqueEmail('billing-refused'), admin: true, company: $company);
        $stripe->willAnswer(400, '{"error":{"type":"invalid_request_error","message":"The product tax code is missing."}}');

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(503, $result['status']);
        self::assertSame(['error' => "The checkout couldn't be started. Try again later; if it keeps failing, contact support."], $result['json']);
        $errors = $logger->atLeast('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('API error 503 on POST billing_checkout_session', $errors[0]);
        self::assertStringContainsString('The product tax code is missing.', $errors[0]);
    }

    /** No limit is set by hand as well, and Plus would add nothing to it. */
    public function testCheckoutSessionIsRefusedForACompanyWithoutASeatLimit(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $fake = $this->useFakeProvider($client);
        $company = new Company('Unlimited Co');
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();
        $this->activateUser($client, $this->uniqueEmail('billing-unlimited'), admin: true, company: $company);

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(409, $result['status']);
        self::assertSame([], $fake->checkoutSessions);
    }

    public function testCheckoutSessionAsksStripeForASubscriptionOfTheConfiguredPrice(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        [$client, $stripe] = $this->webhookClient();
        $company = $this->makeCompany('Asking Co');
        $this->activateUser($client, $this->uniqueEmail('billing-asking'), admin: true, company: $company);
        $stripe->willAnswer(200, '{"id":"cs_test_1","object":"checkout.session","url":"https://checkout.stripe.com/c/pay/cs_test_1"}');

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(['url' => 'https://checkout.stripe.com/c/pay/cs_test_1'], $result['json']);
        self::assertCount(1, $stripe->requests);
        self::assertSame('post', $stripe->requests[0]['method']);
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $stripe->requests[0]['url']);
        self::assertSame('subscription', $stripe->requests[0]['params']['mode']);
        self::assertSame([['price' => 'price_whatever', 'quantity' => 1]], $stripe->requests[0]['params']['line_items']);
        self::assertSame($company->getId(), $stripe->requests[0]['params']['client_reference_id']);
    }

    /**
     * The customer was deleted in the Stripe dashboard. The id is forgotten and the
     * checkout goes on without it, so the admin isn't stuck with a company that can
     * never buy again.
     */
    public function testCheckoutSessionForgetsAStripeCustomerThatIsGone(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        [$client, $stripe] = $this->webhookClient();
        $logger = $this->recordLogs();
        $company = $this->lapsedCompany('Lost Customer Co');
        $this->activateUser($client, $this->uniqueEmail('billing-lost-customer'), admin: true, company: $company);
        $stripe->willAnswer(400, '{"error":{"type":"invalid_request_error","code":"resource_missing","param":"customer","message":"No such customer: \'cus_gone\'"}}');
        $stripe->willAnswer(200, '{"id":"cs_test_1","object":"checkout.session","url":"https://checkout.stripe.com/c/pay/cs_test_1"}');

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(200, $result['status']);
        self::assertSame(['url' => 'https://checkout.stripe.com/c/pay/cs_test_1'], $result['json']);
        self::assertCount(2, $stripe->requests);
        self::assertSame('cus_gone', $stripe->requests[0]['params']['customer']);
        self::assertArrayNotHasKey('customer', $stripe->requests[1]['params']);
        self::assertNull($this->reload($company)->getStripeCustomerId());
        // The subscription id stays: a late event of the old subscription still finds its company.
        self::assertSame('sub_old', $this->reload($company)->getStripeSubscriptionId());
        self::assertSame([], $logger->atLeast('error'));
        self::assertCount(1, $logger->atLeast('warning'));
        self::assertStringContainsString('cus_gone', $logger->atLeast('warning')[0]);
        self::assertStringContainsString($company->getId(), $logger->atLeast('warning')[0]);
    }

    /**
     * A secret key of another Stripe account knows none of the customers, and not the
     * price either. The customer id is kept, or one wrong key would wipe every company's.
     */
    public function testTheCustomerIsKeptWhenStripeRefusesTheSessionWithoutItToo(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        [$client, $stripe] = $this->webhookClient();
        // Recorded, not only checked: an error line on stderr stops Infection's first test run.
        $logger = $this->recordLogs();
        $company = $this->lapsedCompany('Twice Refused Co');
        $this->activateUser($client, $this->uniqueEmail('billing-twice-refused'), admin: true, company: $company);
        $stripe->willAnswer(400, '{"error":{"type":"invalid_request_error","code":"resource_missing","param":"customer","message":"No such customer"}}');
        $stripe->willAnswer(400, '{"error":{"type":"invalid_request_error","message":"The product tax code is missing."}}');

        $result = $this->jsonRequest($client, 'POST', self::CHECKOUT);

        self::assertSame(503, $result['status']);
        self::assertCount(2, $stripe->requests);
        self::assertSame('cus_gone', $this->reload($company)->getStripeCustomerId());
        self::assertCount(1, $logger->atLeast('error'));
        self::assertStringContainsString('The product tax code is missing.', $logger->atLeast('error')[0]);
    }

    public function testWebhookRejectsABadSignature(): void
    {
        $this->withEnv('STRIPE_WEBHOOK_SECRET', self::WEBHOOK_SECRET);
        $client = static::createClient();
        $payload = $this->eventPayload();

        $this->postWebhook($client, $payload, StripeSignature::header($payload, 'another-secret'));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testWebhookRejectsEverythingWhenNoWebhookSecretIsConfigured(): void
    {
        $this->withEnv('STRIPE_WEBHOOK_SECRET', '');
        $client = static::createClient();
        $payload = $this->eventPayload();

        $this->postWebhook($client, $payload, StripeSignature::header($payload, ''));

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

        $this->postWebhook($client, $payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('{"received":true}', $client->getResponse()->getContent());
    }

    public function testAPaidCheckoutMovesTheCompanyItWasStartedForToPlus(): void
    {
        [$client, $stripe] = $this->webhookClient();
        $company = $this->makeCompany('Checkout Co');
        $stripe->willAnswer(200, $this->subscriptionJson('sub_new', 'active'));

        $this->deliver($client, $this->checkoutEvent('sub_new', $company->getId()));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $company = $this->reload($company);
        self::assertSame(Plan::PLUS, $company->getPlanTier());
        self::assertSame(1000, $company->getSeatLimit());
        self::assertSame('active', $company->getSubscriptionStatus());
        self::assertSame('sub_new', $company->getStripeSubscriptionId());
        self::assertSame('cus_fixture1', $company->getStripeCustomerId());
        self::assertNotNull($company->getSubscriptionPeriodEndsAt());
        self::assertSame('https://api.stripe.com/v1/subscriptions/sub_new', $stripe->requests[0]['url']);
    }

    /** The first event of a new subscription is `customer.subscription.created`, before the checkout's own. */
    public function testASubscriptionIsMatchedByItsCompanyTag(): void
    {
        [$client, $stripe] = $this->webhookClient();
        $company = $this->makeCompany('Tagged Co');
        $stripe->willAnswer(200, $this->subscriptionJson('sub_tagged', 'active', companyId: $company->getId()));

        $this->deliver($client, $this->subscriptionEvent('customer.subscription.created', 'sub_tagged'));

        self::assertSame(Plan::PLUS, $this->reload($company)->getPlanTier());
    }

    /**
     * The event says `active`; Stripe, asked now, says the subscription has ended. And
     * the other way round. What Stripe says now is what counts, so the order events
     * arrive in doesn't matter.
     */
    public function testTheSubscriptionsCurrentStateWinsOverTheEvent(): void
    {
        [$client, $stripe] = $this->webhookClient();
        $company = $this->makeCompany('Late Events Co');
        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'active', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.created', 'sub_own'));

        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'canceled', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.updated', 'sub_own', statusInEvent: 'active'));
        self::assertSame(Plan::FREE, $this->reload($company)->getPlanTier());
        self::assertSame('canceled', $this->reload($company)->getSubscriptionStatus());

        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'active', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.deleted', 'sub_own', statusInEvent: 'canceled'));
        self::assertSame(Plan::PLUS, $this->reload($company)->getPlanTier());
    }

    public function testAStoredSubscriptionKeepsItsCompanyWhenItsTagIsChanged(): void
    {
        [$client, $stripe] = $this->webhookClient();
        $company = $this->makeCompany('Owner Co');
        $other = $this->makeCompany('Other Co');
        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'active', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.created', 'sub_own'));

        // Somebody edited the tag in the Stripe dashboard.
        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'past_due', companyId: $other->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.updated', 'sub_own'));

        self::assertSame('past_due', $this->reload($company)->getSubscriptionStatus());
        self::assertNull($this->reload($other)->getStripeSubscriptionId());
        self::assertSame(Plan::FREE, $this->reload($other)->getPlanTier());
    }

    /** #285, item 2: the customer id used to be enough to take over a company's subscription. */
    public function testAnotherSubscriptionOfTheSameCustomerDoesNotChangeTheCompany(): void
    {
        [$client, $stripe] = $this->webhookClient();
        $logger = $this->recordLogs();
        $company = $this->makeCompany('Same Customer Co');
        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'active', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.created', 'sub_own'));

        // Same customer (cus_fixture1), no tag: made by hand in the dashboard.
        $stripe->willAnswer(200, $this->subscriptionJson('sub_by_hand', 'canceled'));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.deleted', 'sub_by_hand'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $company = $this->reload($company);
        self::assertSame('sub_own', $company->getStripeSubscriptionId());
        self::assertSame(Plan::PLUS, $company->getPlanTier());
        $errors = $logger->atLeast('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('sub_by_hand', $errors[0]);
        self::assertStringContainsString('belongs to no company', $errors[0]);
        self::assertStringContainsString('(canceled)', $errors[0]);
        self::assertStringNotContainsString('{', $errors[0]);
    }

    public function testALateEventOfAnOldSubscriptionIsIgnored(): void
    {
        [$client, $stripe] = $this->webhookClient();
        $logger = $this->recordLogs();
        $company = $this->makeCompany('Resubscribed Co');
        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'active', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.created', 'sub_own'));

        $stripe->willAnswer(200, $this->subscriptionJson('sub_old', 'canceled', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.deleted', 'sub_old'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('sub_own', $this->reload($company)->getStripeSubscriptionId());
        self::assertSame(Plan::PLUS, $this->reload($company)->getPlanTier());
        self::assertSame([], $logger->atLeast('error'));
        self::assertCount(1, $logger->atLeast('warning'));
        self::assertStringContainsString('sub_old (canceled) for company '.$company->getId(), $logger->atLeast('warning')[0]);
        self::assertStringContainsString('(sub_own)', $logger->atLeast('warning')[0]);
    }

    public function testASecondPayingSubscriptionIsLoggedAsAnErrorAndChangesNothing(): void
    {
        [$client, $stripe] = $this->webhookClient();
        $logger = $this->recordLogs();
        $company = $this->makeCompany('Paid Twice Co');
        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'active', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.created', 'sub_own'));

        $stripe->willAnswer(200, $this->subscriptionJson('sub_second', 'active', companyId: $company->getId()));
        $this->deliver($client, $this->checkoutEvent('sub_second', $company->getId()));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('sub_own', $this->reload($company)->getStripeSubscriptionId());
        $errors = $logger->atLeast('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('two paying Stripe subscriptions', $errors[0]);
        self::assertStringContainsString('sub_own', $errors[0]);
        self::assertStringContainsString('sub_second', $errors[0]);
        self::assertStringContainsString($company->getId(), $errors[0]);
        self::assertStringNotContainsString('{', $errors[0]);
    }

    public function testNotPayingNeverSuspendsAndPayingNeverLiftsASuspension(): void
    {
        [$client, $stripe] = $this->webhookClient();
        $company = $this->makeCompany('Suspension Co');
        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'active', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.created', 'sub_own'));

        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'unpaid', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.updated', 'sub_own'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('canceled', $this->reload($company)->getSubscriptionStatus());
        self::assertFalse($this->reload($company)->isSuspended());

        $suspended = $this->reload($company);
        $suspended->suspend();
        $this->entityManager()->flush();
        $stripe->willAnswer(200, $this->subscriptionJson('sub_own', 'active', companyId: $company->getId()));
        $this->deliver($client, $this->subscriptionEvent('customer.subscription.updated', 'sub_own'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('active', $this->reload($company)->getSubscriptionStatus());
        self::assertTrue($this->reload($company)->isSuspended());
    }

    /** Stripe delivers the event again later; nothing was changed in between. */
    public function testWebhookAnswers500WhenStripeDoesNotReturnTheSubscription(): void
    {
        [$client, $stripe] = $this->webhookClient();
        $logger = $this->recordLogs();
        $company = $this->makeCompany('Outage Co');
        $stripe->willAnswer(502, '<html>Bad Gateway</html>');

        $this->deliver($client, $this->checkoutEvent('sub_new', $company->getId()));

        self::assertSame(500, $client->getResponse()->getStatusCode());
        self::assertSame(Plan::FREE, $this->reload($company)->getPlanTier());
        self::assertNull($this->reload($company)->getStripeSubscriptionId());
        self::assertCount(1, $logger->atLeast('error'));
    }

    public function testAnEventTheAppDoesNotActOnAsksStripeNothing(): void
    {
        [$client, $stripe] = $this->webhookClient();

        $this->deliver($client, $this->eventPayload());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], $stripe->requests);
    }

    /**
     * A client whose provider is the real one, with StubStripeHttpClient answering in
     * Stripe's place. The kernel is not rebooted between requests, or the next one would
     * get a provider of its own.
     *
     * @return array{KernelBrowser, StubStripeHttpClient}
     */
    private function webhookClient(): array
    {
        $this->withEnv('DEFAULT_SEAT_LIMIT', '100');
        $this->withEnv('PLUS_SEAT_LIMIT', '1000');
        $client = static::createClient();
        $client->disableReboot();
        $stripe = new StubStripeHttpClient();
        static::getContainer()->set(StripeBillingProvider::class, new StripeBillingProvider('sk_test_whatever', self::WEBHOOK_SECRET, 'price_whatever', $stripe));

        return [$client, $stripe];
    }

    /** The real provider with every Stripe setting set, or all but one. No test with these values lets it reach Stripe. */
    private function configureStripe(?string $except = null): void
    {
        foreach (self::stripeSettings() as [$name]) {
            $this->withEnv($name, $name === $except ? '' : 'set');
        }
    }

    private function recordLogs(): RecordingLogger
    {
        $logger = new RecordingLogger();
        static::getContainer()->set('logger', $logger);

        return $logger;
    }

    private function makeCompany(string $name): Company
    {
        $company = new Company($name, seatLimit: 100);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();

        return $company;
    }

    /** A company that subscribed and stopped: on Free again, with its Stripe customer `cus_gone` stored. */
    private function lapsedCompany(string $name): Company
    {
        $company = $this->makeCompany($name);
        $plan = new Plan(100, 1000);
        $company->applySubscription(new SubscriptionSnapshot('sub_old', 'cus_gone', 'active', null, null, null), $plan);
        $company->applySubscription(new SubscriptionSnapshot('sub_old', 'cus_gone', 'canceled', null, null, null), $plan);
        $this->entityManager()->flush();

        return $company;
    }

    /** The kernel is not rebooted, so the entity manager still holds what it loaded: read the row again. */
    private function reload(Company $company): Company
    {
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(Company::class, $company->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function deliver(KernelBrowser $client, string $payload): void
    {
        $this->postWebhook($client, $payload, StripeSignature::header($payload, self::WEBHOOK_SECRET));
    }

    private function checkoutEvent(string $subscriptionId, string $companyId): string
    {
        return $this->event('checkout.session.completed', ['id' => 'cs_test', 'object' => 'checkout.session', 'subscription' => $subscriptionId, 'client_reference_id' => $companyId]);
    }

    private function subscriptionEvent(string $type, string $subscriptionId, string $statusInEvent = 'active'): string
    {
        return $this->event($type, ['id' => $subscriptionId, 'object' => 'subscription', 'status' => $statusInEvent, 'customer' => 'cus_fixture1']);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function event(string $type, array $object): string
    {
        return json_encode(['id' => 'evt_test', 'object' => 'event', 'api_version' => '2024-06-20', 'created' => time(), 'type' => $type, 'data' => ['object' => $object]], \JSON_THROW_ON_ERROR);
    }

    /** What Stripe answers to a retrieve: a subscription saved from the sandbox, with these three fields set. */
    private function subscriptionJson(string $id, string $status, ?string $companyId = null): string
    {
        $json = file_get_contents(__DIR__.'/../Fixtures/stripe/subscription-active-untagged.json');
        self::assertIsString($json);
        $subscription = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($subscription);
        $subscription['id'] = $id;
        $subscription['status'] = $status;
        $subscription['metadata'] = null === $companyId ? [] : ['company_id' => $companyId];

        return json_encode($subscription, \JSON_THROW_ON_ERROR);
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
}
