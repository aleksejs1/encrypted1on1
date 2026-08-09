<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

/**
 * REGISTRATION_MODE in .env is "invite" (any authenticated user may invite),
 * inherited unchanged by the test environment (.env.test doesn't override
 * it). Exercising the "admin_only" branch would need a real container
 * rebuild with a different env value — the same reason Phase 6g's manual
 * verification needed a real `docker compose up --force-recreate` between
 * configs, not something a single PHPUnit process can cheaply replicate.
 * Deliberately deferred, not silently skipped.
 */
class InviteControllerTest extends ApiTestCase
{
    public function testInviteRequiresAuthentication(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('invite-target')]);

        self::assertSame(401, $result['status']);
    }

    public function testAnyAuthenticatedUserCanInviteWhenModeIsInvite(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('invite-sender'));

        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('invite-target')]);

        self::assertSame(201, $result['status']);
    }

    public function testInviteRejectsMissingEmail(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('invite-sender-missing'));

        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => '']);

        self::assertSame(400, $result['status']);
    }

    public function testInviteRejectsAnEmailThatAlreadyHasAnAccount(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('invite-sender-dup'));
        $existingEmail = $this->uniqueEmail('invite-existing');

        $other = $this->secondClient();
        $this->activateUser($other, $existingEmail);

        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $existingEmail]);

        self::assertSame(400, $result['status']);
        self::assertSame('That email already has an account.', $result['json']['error']);
    }

    public function testInviteIsRateLimitedAfterTooManyInvites(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('invite-rate-limit-sender'));

        // The configured limit (10/hour, config/packages/rate_limiter.php) — each of
        // these is a normal successful invite, not yet rate-limited.
        for ($i = 0; $i < 10; ++$i) {
            $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail("invite-rate-limit-target-{$i}")]);
            self::assertSame(201, $result['status'], "invite {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('invite-rate-limit-overflow')]);

        self::assertSame(429, $limited['status']);
    }
}
