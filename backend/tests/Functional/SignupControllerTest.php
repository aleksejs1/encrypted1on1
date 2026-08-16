<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

/**
 * REGISTRATION_MODE in .env is "invite", inherited unchanged by the test environment
 * (.env.test doesn't override it) — same situation InviteControllerTest already
 * documents for its own "admin_only" gap. That means the domain-mode happy path
 * (a matching-domain email issuing a real token and sending a real signup email),
 * the domain-restriction rejection, and the enumeration-safety branch (an
 * already-registered email still returning {"ok": true} without a second token) are
 * all unreachable here — POST /api/signup 400s on the mode check before any of that
 * code runs. Exercising them needs a real container rebuilt with
 * REGISTRATION_MODE=domain, the same "docker compose up --force-recreate between
 * configs" Phase 6g's own manual verification needed. Deliberately deferred, not
 * silently skipped — verified manually against a real rebuilt container instead (see
 * the plan file's Verification section). This also covers signup()'s own seat-limit
 * check (Phase D) — SeatLimitTest exercises the exact same shared SeatLimitChecker
 * through InviteController instead, the fully reachable path; the two call sites are
 * visually identical one-liners, and this specific branch was verified manually
 * alongside the rest of domain mode, not left untested.
 */
class SignupControllerTest extends ApiTestCase
{
    public function testRegistrationInfoReflectsTheBoundConfig(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'GET', '/api/registration-info');

        self::assertSame(200, $result['status']);
        self::assertSame('invite', $result['json']['registrationMode']);
        self::assertSame('', $result['json']['allowedEmailDomain']);
        self::assertFalse($result['json']['cloudMode']);
    }

    public function testSignupIsRejectedWhenModeIsNotDomain(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'POST', '/api/signup', ['email' => $this->uniqueEmail('signup-not-open')]);

        self::assertSame(400, $result['status']);
        self::assertSame('Self-registration is not currently open.', $result['json']['error']);
    }

    public function testSignupIsRateLimitedAfterTooManyAttempts(): void
    {
        $client = static::createClient();

        // Configured limit: 5/hour (config/packages/rate_limiter.php). Every attempt
        // here 400s on the mode check (not open in this test env), but rate-limit
        // consumption happens before that check, so each still counts.
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->jsonRequest($client, 'POST', '/api/signup', ['email' => $this->uniqueEmail("signup-rate-limit-{$i}")]);
            self::assertSame(400, $result['status'], "attempt {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'POST', '/api/signup', ['email' => $this->uniqueEmail('signup-rate-limit-overflow')]);

        self::assertSame(429, $limited['status']);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
    }
}
