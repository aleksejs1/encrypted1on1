<?php

namespace App\Tests\Unit\Entity;

use App\Entity\PasswordResetToken;
use PHPUnit\Framework\TestCase;

class PasswordResetTokenTest extends TestCase
{
    public function testIssueProducesAUsableTokenAndADistinctRawToken(): void
    {
        [$token, $rawToken] = PasswordResetToken::issue('someone@example.com');

        self::assertTrue($token->isUsable());
        self::assertSame('someone@example.com', $token->getEmail());
        self::assertNotEmpty($rawToken);
    }

    public function testTokenIsNoLongerUsableAfterBeingMarkedUsed(): void
    {
        [$token] = PasswordResetToken::issue('someone@example.com');
        self::assertTrue($token->isUsable());

        $token->markUsed();

        self::assertFalse($token->isUsable());
    }

    public function testTokenIsNotUsableOnceExpired(): void
    {
        $token = new PasswordResetToken(hash('sha256', 'raw'), 'someone@example.com', new \DateTimeImmutable('-1 hour'));

        self::assertFalse($token->isUsable());
    }
}
