<?php

namespace App\Tests\Functional;

use App\Billing\Plan;
use App\Entity\ActivationToken;
use App\Entity\Company;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;

/**
 * CLOUD_MODE is off in .env and so in the test environment; a test that needs the
 * route open turns it on for itself with withEnv().
 */
class CompanyControllerTest extends ApiTestCase
{
    use CleansUpCompanies;

    private const array COMPANY_TABLES = ['activation_tokens'];

    public function testCreateCompanyIsRejectedWhenCloudModeIsDisabled(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'POST', '/api/companies', [
            'name' => 'Acme Inc',
            'adminEmail' => $this->uniqueEmail('company-create'),
        ]);

        self::assertSame(400, $result['status']);
        self::assertSame('Company sign-up is not available on this instance.', $result['json']['error']);
    }

    public function testCreateCompanyStartsOnTheFreePlanWithItsSeatLimit(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $this->withEnv('DEFAULT_SEAT_LIMIT', '42');
        $client = static::createClient();
        $email = $this->uniqueEmail('company-create-cloud');

        $result = $this->jsonRequest($client, 'POST', '/api/companies', [
            'name' => 'Acme Inc',
            'adminEmail' => $email,
        ]);

        $token = $this->createdCompanyToken($email);
        self::assertSame(200, $result['status']);
        self::assertSame(['ok' => true], $result['json']);
        self::assertNotNull($token);
        self::assertTrue($token->grantsAdmin());
        $company = $token->getCompany();
        self::assertSame('Acme Inc', $company->getName());
        self::assertSame(Plan::FREE, $company->getPlanTier());
        self::assertSame(42, $company->getSeatLimit());
    }

    /** The answer is the same, so it doesn't reveal the account; no second company or token appears. */
    public function testCreateCompanyForAnAlreadyRegisteredEmailCreatesNothing(): void
    {
        $this->withEnv('CLOUD_MODE', '1');
        $client = static::createClient();
        $email = $this->uniqueEmail('company-create-existing');
        $this->activateUser($client, $email);
        $companiesBefore = $this->entityManager()->getRepository(Company::class)->count([]);

        $result = $this->jsonRequest($this->secondClient(), 'POST', '/api/companies', [
            'name' => 'Acme Inc',
            'adminEmail' => $email,
        ]);

        $token = $this->createdCompanyToken($email);
        self::assertSame(200, $result['status']);
        self::assertSame(['ok' => true], $result['json']);
        self::assertNull($token);
        self::assertSame($companiesBefore, $this->entityManager()->getRepository(Company::class)->count([]));
    }

    public function testThePlanSeatLimitsComeFromTheEnvironment(): void
    {
        $this->withEnv('DEFAULT_SEAT_LIMIT', '7');
        $this->withEnv('PLUS_SEAT_LIMIT', '77');
        static::createClient();

        $plan = static::getContainer()->get(Plan::class);
        self::assertInstanceOf(Plan::class, $plan);
        self::assertSame(7, $plan->seatLimit(Plan::FREE));
        self::assertSame(77, $plan->seatLimit(Plan::PLUS));
    }

    /**
     * The unused token POST /api/companies issued to `$email`, if it did, with its
     * company registered for cleanup before the caller asserts anything.
     */
    private function createdCompanyToken(string $email): ?ActivationToken
    {
        $token = $this->entityManager()->getRepository(ActivationToken::class)->findOneBy(['email' => $email, 'usedAt' => null]);
        if (null !== $token) {
            $this->createdCompanyIds[] = $token->getCompany()->getId();
        }

        return $token;
    }

    public function testCreateCompanyRejectsBlankName(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'POST', '/api/companies', [
            'name' => '   ',
            'adminEmail' => $this->uniqueEmail('company-blank-name'),
        ]);

        self::assertSame(400, $result['status']);
        self::assertArrayHasKey('violations', $result['json']);
    }

    public function testCreateCompanyIsRateLimitedAfterTooManyAttempts(): void
    {
        $client = static::createClient();

        // Configured limit: 5/hour (config/packages/rate_limiter.php). Every attempt
        // here 400s on the CLOUD_MODE check (disabled in this test env), but rate-limit
        // consumption happens before that check, so each still counts.
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->jsonRequest($client, 'POST', '/api/companies', [
                'name' => 'Acme Inc',
                'adminEmail' => $this->uniqueEmail("company-rate-limit-{$i}"),
            ]);
            self::assertSame(400, $result['status'], "attempt {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'POST', '/api/companies', [
            'name' => 'Acme Inc',
            'adminEmail' => $this->uniqueEmail('company-rate-limit-overflow'),
        ]);

        self::assertSame(429, $limited['status']);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
    }
}
