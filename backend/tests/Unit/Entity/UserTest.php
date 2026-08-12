<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testConstructorDefaultsToEnglishLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc');

        self::assertSame('en', $user->getLocale());
    }

    public function testConstructorAcceptsASupportedLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', locale: 'ru');

        self::assertSame('ru', $user->getLocale());
    }

    public function testConstructorSilentlyFallsBackToEnglishForAnUnsupportedLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', locale: 'fr');

        self::assertSame('en', $user->getLocale());
    }

    public function testSetLocaleAcceptsASupportedLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc');

        $user->setLocale('lv');

        self::assertSame('lv', $user->getLocale());
    }

    public function testSetLocaleThrowsForAnUnsupportedLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc');

        $this->expectException(\InvalidArgumentException::class);
        $user->setLocale('fr');
    }

    public function testChangePasswordUpdatesAuthHashAndEncryptedPrivateKeyOnly(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc');

        $user->changePassword('new-hash', 'new-enc');

        self::assertSame('new-hash', $user->getAuthHash());
        self::assertSame('new-enc', $user->getEncryptedPrivateKey());
        // The keypair itself is untouched — no anketa re-sharing consequence.
        self::assertSame('pub', $user->getPublicKey());
        self::assertNull($user->getPublicKeyUpdatedAt());
    }
}
