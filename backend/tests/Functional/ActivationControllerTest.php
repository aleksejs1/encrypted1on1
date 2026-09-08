<?php

namespace App\Tests\Functional;

use App\Entity\ActivationToken;
use App\Entity\InviteRecord;
use App\Tests\Support\ApiTestCase;

class ActivationControllerTest extends ApiTestCase
{
    public function testLookupReturns404ForAnUnknownToken(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'GET', '/api/activation-tokens/bogus-token');

        self::assertSame(404, $result['status']);
        self::assertSame('Invalid or expired activation link.', $result['json']['error']);
    }

    public function testLookupReturnsTheEmailForAValidToken(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('activation-lookup');
        $rawToken = $this->issueToken($email);

        $result = $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$rawToken}");

        self::assertSame(200, $result['status']);
        self::assertSame($email, $result['json']['email']);
    }

    public function testCompleteRejectsMissingFields(): void
    {
        $client = static::createClient();
        $rawToken = $this->issueToken($this->uniqueEmail('activation-missing-fields'));

        $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
        ]);

        self::assertSame(400, $result['status']);
    }

    public function testCompleteCreatesAndLogsInANonAdminUserByDefault(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('activation-complete');
        $user = $this->activateUser($client, $email);

        self::assertSame($email, $user['email']);
        self::assertFalse($user['isAdmin']);

        $me = $this->jsonRequest($client, 'GET', '/api/me');
        self::assertSame(200, $me['status']);
        self::assertSame($user['id'], $me['json']['id']);
    }

    public function testCompleteDefaultsDisplayNameToEmptyStringWhenOmitted(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('activation-no-name'));

        $me = $this->jsonRequest($client, 'GET', '/api/me');

        self::assertSame('', $me['json']['displayName']);
    }

    public function testCompleteSetsAndTrimsTheProvidedDisplayName(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('activation-with-name'), displayName: '  Alex Morgan  ');

        $me = $this->jsonRequest($client, 'GET', '/api/me');

        self::assertSame('Alex Morgan', $me['json']['displayName']);
    }

    public function testCompleteRejectsADisplayNameLongerThan255Characters(): void
    {
        $client = static::createClient();
        $rawToken = $this->issueToken($this->uniqueEmail('activation-name-too-long'));

        $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
            'displayName' => str_repeat('x', 256),
        ]);

        self::assertSame(400, $result['status']);
    }

    public function testCompleteGrantsAdminWhenTheTokenWasIssuedWithIt(): void
    {
        $client = static::createClient();
        $user = $this->activateUser($client, $this->uniqueEmail('activation-admin'), admin: true);

        self::assertTrue($user['isAdmin']);
    }

    public function testATokenCannotBeCompletedTwice(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('activation-single-use');
        $rawToken = $this->issueToken($email);

        $first = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);
        self::assertSame(200, $first['status']);

        $second = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);
        self::assertSame(404, $second['status']);
    }

    public function testCompletingASecondValidTokenForAnAlreadyRegisteredEmailReturns404NotA500(): void
    {
        // Two still-usable tokens for the same email — e.g. a resent invite, or two
        // concurrent completions of the same token racing past the isUsable() check
        // before either commits. Whichever completes second must hit User::$email's
        // unique constraint and get the same "invalid or expired" outcome as
        // testATokenCannotBeCompletedTwice, not an uncaught 500.
        $client = static::createClient();
        $email = $this->uniqueEmail('activation-race');
        $firstRawToken = $this->issueToken($email);
        $secondRawToken = $this->issueToken($email);

        $first = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$firstRawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);
        self::assertSame(200, $first['status']);

        $second = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$secondRawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);
        self::assertSame(404, $second['status']);
        self::assertSame('Invalid or expired activation link.', $second['json']['error']);
    }

    public function testCompleteIsRateLimitedAfterTooManyAttempts(): void
    {
        $client = static::createClient();

        // Rate-limit consumption happens before token lookup, so bogus tokens are fine
        // here — the configured limit (10/minute, config/packages/rate_limiter.php).
        for ($i = 0; $i < 10; ++$i) {
            $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/bogus-token-{$i}/complete", [
                'authKey' => str_repeat('a', 44),
                'publicKey' => str_repeat('b', 44),
                'encryptedPrivateKey' => str_repeat('c', 44),
            ]);
            self::assertSame(404, $result['status'], "attempt {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'POST', '/api/activation-tokens/bogus-token-overflow/complete', [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);

        self::assertSame(429, $limited['status']);
    }

    /**
     * GitHub issue #24: a token issued alongside a matching InviteRecord (mirroring
     * InviteController::create()) gets that record's acceptedAt stamped on real
     * completion — proving the coupling described in InviteRecord's own docblock,
     * not just the pure aggregator-style unit the entity's own status() getter covers.
     */
    public function testCompletingATokenStampsItsMatchingInviteRecordAsAccepted(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('activation-invite-accept');
        [$rawToken, $inviteRecordId] = $this->issueInvite($email);

        $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);
        self::assertSame(200, $result['status']);

        $inviteRecord = $this->entityManager()->find(InviteRecord::class, $inviteRecordId);
        self::assertNotNull($inviteRecord);
        self::assertNotNull($inviteRecord->getAcceptedAt());
        self::assertSame('accepted', $inviteRecord->status(new \DateTimeImmutable()));
    }

    /**
     * The CLI bootstrap / cloud company-creation completions have no matching
     * InviteRecord at all (see ActivationController::complete()'s own comment) — this
     * proves that's a normal, silent no-op, not a 500.
     */
    public function testCompletingATokenWithNoMatchingInviteRecordStillSucceeds(): void
    {
        $client = static::createClient();
        $rawToken = $this->issueToken($this->uniqueEmail('activation-no-invite-record'));

        $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);

        self::assertSame(200, $result['status']);
    }

    private function issueToken(string $email): string
    {
        [$token, $rawToken] = ActivationToken::issue($email, $this->singleCompanyProvider()->get());
        $this->entityManager()->persist($token);
        $this->entityManager()->flush();

        return $rawToken;
    }

    /** @return array{0: string, 1: string} raw token, InviteRecord id */
    private function issueInvite(string $email): array
    {
        $company = $this->singleCompanyProvider()->get();
        [$token, $rawToken] = ActivationToken::issue($email, $company);
        $this->entityManager()->persist($token);
        $inviteRecord = new InviteRecord($token->getId(), $email, $company, null, $token->getExpiresAt());
        $this->entityManager()->persist($inviteRecord);
        $this->entityManager()->flush();

        return [$rawToken, $inviteRecord->getId()];
    }
}
