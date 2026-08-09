<?php

namespace App\Tests\Functional;

use App\Entity\ActivationToken;
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

    private function issueToken(string $email): string
    {
        [$token, $rawToken] = ActivationToken::issue($email);
        $this->entityManager()->persist($token);
        $this->entityManager()->flush();

        return $rawToken;
    }
}
