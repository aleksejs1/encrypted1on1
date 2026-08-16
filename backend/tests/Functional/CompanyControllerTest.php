<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

/**
 * CLOUD_MODE in .env is "0" (off), inherited unchanged by the test environment
 * (.env.test doesn't override it) — same situation InviteControllerTest already
 * documents for its own "admin_only" gap. That means the happy path (a real company +
 * admin activation token being created), the missing-field validation, and the
 * enumeration-safety branch (an already-registered email still returning {"ok": true}
 * without a second company/token) are all unreachable here — POST /api/companies
 * 400s on the CLOUD_MODE check before any of that code runs. Deliberately deferred,
 * not silently skipped — verified manually against a real rebuilt container with
 * CLOUD_MODE=1 instead (see private/cloud-service-plan.md, not tracked in git).
 */
class CompanyControllerTest extends ApiTestCase
{
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
