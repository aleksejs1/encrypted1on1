<?php

namespace App\Tests\Functional;

use App\Entity\ActivationToken;
use App\Entity\InviteRecord;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

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
        // GitHub issue #169: the same state lookup() reports for a used link.
        self::assertSame(409, $second['status']);
        self::assertSame('already_activated', $second['json']['code']);
    }

    public function testASecondValidTokenForAnAlreadyRegisteredEmailReadsAsAlreadyActivated(): void
    {
        // Two still-usable tokens for the same email — e.g. a resent invite. Once one is
        // completed, the other reads as already activated (GitHub issue #169), on lookup
        // and on complete, the same outcome as testATokenCannotBeCompletedTwice. (Two
        // truly concurrent completions instead hit User::$email's unique constraint in
        // complete(), which answers the same, not a 500.)
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
        self::assertSame(409, $second['status']);
        self::assertSame('already_activated', $second['json']['code']);

        $lookup = $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$secondRawToken}");
        self::assertSame([409, 'already_activated'], [$lookup['status'], $lookup['json']['code']]);
    }

    /**
     * Two truly concurrent completions for one address both pass linkState() before
     * either commits. Simulated by inserting the competing account right before this
     * request's flush: the loser hits User::$email's unique constraint and answers 409
     * already_activated, not a 500.
     */
    public function testACompletionThatLosesARaceForTheAddressIsAlreadyActivated(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $email = $this->uniqueEmail('activation-concurrent');
        $rawToken = $this->issueToken($email);
        $company = $this->singleCompanyProvider()->get();
        $connection = $this->entityManager()->getConnection();
        $listener = new class($connection, $email, $company->getId()) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly string $email, private readonly string $companyId)
            {
            }

            public function onFlush(): void
            {
                if ($this->done) {
                    return;
                }
                $this->done = true;
                $this->connection->insert('users', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'email' => $this->email,
                    'authHash' => 'x',
                    'publicKey' => 'x',
                    'encryptedPrivateKey' => 'x',
                    'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'isAdmin' => 0,
                    'isBlocked' => 0,
                    'locale' => 'en',
                    'meetingRemindersEnabled' => 1,
                    'isDemo' => 0,
                    'company_id' => $this->companyId,
                    'isPlatformAdmin' => 0,
                    'displayName' => '',
                ]);
            }
        };
        $this->entityManager()->getEventManager()->addEventListener([Events::onFlush], $listener);

        try {
            $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
                'authKey' => str_repeat('a', 44),
                'publicKey' => str_repeat('b', 44),
                'encryptedPrivateKey' => str_repeat('c', 44),
            ]);
        } finally {
            $this->entityManager()->getEventManager()->removeEventListener([Events::onFlush], $listener);
        }

        self::assertSame(409, $result['status']);
        self::assertSame('already_activated', $result['json']['code']);
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
