<?php

namespace App\Tests\Functional;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;

class PasswordResetControllerTest extends ApiTestCase
{
    public function testRequestReturnsOkRegardlessOfWhetherTheEmailExists(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'POST', '/api/password-reset', [
            'email' => $this->uniqueEmail('reset-no-such-user'),
        ]);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['ok']);
    }

    public function testRequestIssuesATokenForAKnownAccount(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset-request');
        $this->activateUser($client, $email);

        $result = $this->jsonRequest($client, 'POST', '/api/password-reset', ['email' => $email]);
        self::assertSame(200, $result['status']);

        $token = $this->entityManager()->getRepository(PasswordResetToken::class)->findOneBy(['email' => $email]);
        self::assertNotNull($token);
        self::assertTrue($token->isUsable());
    }

    public function testRequestDoesNotIssueATokenForABlockedAccount(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset-blocked-request');
        $user = $this->activateUser($client, $email);

        $entity = $this->entityManager()->find(User::class, $user['id']);
        \assert($entity instanceof User);
        $entity->setBlocked(true);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'POST', '/api/password-reset', ['email' => $email]);
        self::assertSame(200, $result['status'], 'still returns ok, so blocked status is not enumerable');

        $token = $this->entityManager()->getRepository(PasswordResetToken::class)->findOneBy(['email' => $email]);
        self::assertNull($token);
    }

    public function testLookupReturns404ForAnUnknownToken(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'GET', '/api/password-reset-tokens/bogus-token');

        self::assertSame(404, $result['status']);
        self::assertSame('Invalid or expired password reset link.', $result['json']['error']);
    }

    public function testLookupReturnsTheEmailForAValidToken(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset-lookup');
        $rawToken = $this->issueResetToken($email);

        $result = $this->jsonRequest($client, 'GET', "/api/password-reset-tokens/{$rawToken}");

        self::assertSame(200, $result['status']);
        self::assertSame($email, $result['json']['email']);
    }

    public function testCompleteRejectsMissingFields(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset-missing-fields');
        $this->activateUser($client, $email);
        $rawToken = $this->issueResetToken($email);

        $result = $this->jsonRequest($client, 'POST', "/api/password-reset-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('x', 44),
        ]);

        self::assertSame(400, $result['status']);
    }

    public function testCompleteUpdatesCredentialsAndLogsIn(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset-complete');
        $this->activateUser($client, $email);
        $this->jsonRequest($client, 'POST', '/api/logout');
        $rawToken = $this->issueResetToken($email);

        $result = $this->jsonRequest($client, 'POST', "/api/password-reset-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('x', 44),
            'publicKey' => str_repeat('y', 44),
            'encryptedPrivateKey' => str_repeat('z', 44),
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame($email, $result['json']['email']);

        // Logged in as a side effect of completing the reset.
        $me = $this->jsonRequest($client, 'GET', '/api/me');
        self::assertSame(200, $me['status']);
        self::assertSame(str_repeat('y', 44), $me['json']['publicKey']);

        // The old authKey ("a" x 44, see ApiTestCase::activateUser()) no longer works.
        $this->jsonRequest($client, 'POST', '/api/logout');
        $oldLogin = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('a', 44),
        ]);
        self::assertSame(401, $oldLogin['status']);

        // The new authKey does.
        $newLogin = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('x', 44),
        ]);
        self::assertSame(200, $newLogin['status']);
    }

    public function testATokenCannotBeCompletedTwice(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset-single-use');
        $this->activateUser($client, $email);
        $rawToken = $this->issueResetToken($email);

        $body = [
            'authKey' => str_repeat('x', 44),
            'publicKey' => str_repeat('y', 44),
            'encryptedPrivateKey' => str_repeat('z', 44),
        ];

        $first = $this->jsonRequest($client, 'POST', "/api/password-reset-tokens/{$rawToken}/complete", $body);
        self::assertSame(200, $first['status']);

        $second = $this->jsonRequest($client, 'POST', "/api/password-reset-tokens/{$rawToken}/complete", $body);
        self::assertSame(404, $second['status']);
    }

    public function testCompleteRejectsABlockedAccount(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset-complete-blocked');
        $user = $this->activateUser($client, $email);
        $rawToken = $this->issueResetToken($email);

        $entity = $this->entityManager()->find(User::class, $user['id']);
        \assert($entity instanceof User);
        $entity->setBlocked(true);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'POST', "/api/password-reset-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('x', 44),
            'publicKey' => str_repeat('y', 44),
            'encryptedPrivateKey' => str_repeat('z', 44),
        ]);

        self::assertSame(403, $result['status']);
        self::assertSame('This account has been blocked.', $result['json']['error']);
    }

    public function testRequestIsRateLimitedAfterTooManyAttempts(): void
    {
        $client = static::createClient();

        // Configured limit: 5/hour (config/packages/rate_limiter.php).
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->jsonRequest($client, 'POST', '/api/password-reset', [
                'email' => $this->uniqueEmail("reset-rate-limit-{$i}"),
            ]);
            self::assertSame(200, $result['status'], "attempt {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'POST', '/api/password-reset', [
            'email' => $this->uniqueEmail('reset-rate-limit-overflow'),
        ]);

        self::assertSame(429, $limited['status']);
    }

    public function testCompleteIsRateLimitedAfterTooManyAttempts(): void
    {
        $client = static::createClient();

        // Rate-limit consumption happens before token lookup, so bogus tokens are fine
        // here — the configured limit (10/minute, config/packages/rate_limiter.php).
        for ($i = 0; $i < 10; ++$i) {
            $result = $this->jsonRequest($client, 'POST', "/api/password-reset-tokens/bogus-token-{$i}/complete", [
                'authKey' => str_repeat('x', 44),
                'publicKey' => str_repeat('y', 44),
                'encryptedPrivateKey' => str_repeat('z', 44),
            ]);
            self::assertSame(404, $result['status'], "attempt {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'POST', '/api/password-reset-tokens/bogus-token-overflow/complete', [
            'authKey' => str_repeat('x', 44),
            'publicKey' => str_repeat('y', 44),
            'encryptedPrivateKey' => str_repeat('z', 44),
        ]);

        self::assertSame(429, $limited['status']);
    }

    private function issueResetToken(string $email): string
    {
        [$token, $rawToken] = PasswordResetToken::issue($email);
        $this->entityManager()->persist($token);
        $this->entityManager()->flush();

        return $rawToken;
    }
}
