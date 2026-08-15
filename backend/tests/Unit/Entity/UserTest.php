<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    private function testCompany(): Company
    {
        return new Company('Test Co');
    }

    public function testConstructorDefaultsToEnglishLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', $this->testCompany());

        self::assertSame('en', $user->getLocale());
    }

    public function testConstructorAcceptsASupportedLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', $this->testCompany(), locale: 'ru');

        self::assertSame('ru', $user->getLocale());
    }

    public function testConstructorSilentlyFallsBackToEnglishForAnUnsupportedLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', $this->testCompany(), locale: 'fr');

        self::assertSame('en', $user->getLocale());
    }

    public function testSetLocaleAcceptsASupportedLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', $this->testCompany());

        $user->setLocale('lv');

        self::assertSame('lv', $user->getLocale());
    }

    public function testSetLocaleThrowsForAnUnsupportedLocale(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', $this->testCompany());

        $this->expectException(\InvalidArgumentException::class);
        $user->setLocale('fr');
    }

    public function testChangePasswordUpdatesAuthHashAndEncryptedPrivateKeyOnly(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', $this->testCompany());

        $user->changePassword('new-hash', 'new-enc');

        self::assertSame('new-hash', $user->getAuthHash());
        self::assertSame('new-enc', $user->getEncryptedPrivateKey());
        // The keypair itself is untouched — no anketa re-sharing consequence.
        self::assertSame('pub', $user->getPublicKey());
        self::assertNull($user->getPublicKeyUpdatedAt());
    }

    public function testMeetingRemindersDefaultToEnabled(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', $this->testCompany());

        self::assertTrue($user->wantsMeetingReminders());
    }

    public function testSetMeetingRemindersEnabledRoundTrips(): void
    {
        $user = new User('a@example.com', 'hash', 'pub', 'enc', $this->testCompany());

        $user->setMeetingRemindersEnabled(false);

        self::assertFalse($user->wantsMeetingReminders());
    }

    public function testDeleteScrubsIdentifyingFieldsAndForcesSafeDefaults(): void
    {
        $user = new User('a@example.com', 'original-hash', 'pub', 'original-enc', $this->testCompany(), isAdmin: true);
        $id = $user->getId();

        $user->delete();

        self::assertSame(sprintf('deleted-%s@deleted.invalid', $id), $user->getEmail());
        self::assertNotSame('original-hash', $user->getAuthHash());
        self::assertSame('', $user->getEncryptedPrivateKey());
        self::assertFalse($user->isAdmin());
        self::assertTrue($user->isBlocked());
        self::assertFalse($user->wantsMeetingReminders());
        self::assertNotNull($user->getDeletedAt());
        // Deliberately untouched — see User::delete()'s docblock for why (would crash a
        // live counterpart's client-side archive/reseal flow otherwise).
        self::assertSame('pub', $user->getPublicKey());
    }
}
