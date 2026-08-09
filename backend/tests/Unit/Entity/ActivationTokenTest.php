<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ActivationToken;
use PHPUnit\Framework\TestCase;

class ActivationTokenTest extends TestCase
{
    public function testIssueProducesAUsableTokenAndADistinctRawToken(): void
    {
        [$token, $rawToken] = ActivationToken::issue('someone@example.com');

        self::assertTrue($token->isUsable());
        self::assertSame('someone@example.com', $token->getEmail());
        self::assertFalse($token->grantsAdmin());
        self::assertNotEmpty($rawToken);
    }

    public function testIssueWithGrantsAdminTrue(): void
    {
        [$token] = ActivationToken::issue('admin@example.com', true);

        self::assertTrue($token->grantsAdmin());
    }

    public function testTokenIsNoLongerUsableAfterBeingMarkedUsed(): void
    {
        [$token] = ActivationToken::issue('someone@example.com');
        self::assertTrue($token->isUsable());

        $token->markUsed();

        self::assertFalse($token->isUsable());
    }

    public function testTokenIsNotUsableOnceExpired(): void
    {
        $token = new ActivationToken(hash('sha256', 'raw'), 'someone@example.com', false, new \DateTimeImmutable('-1 hour'));

        self::assertFalse($token->isUsable());
    }
}
