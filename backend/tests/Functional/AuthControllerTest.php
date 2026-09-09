<?php

namespace App\Tests\Functional;

use App\Entity\ActivationToken;
use App\Entity\Company;
use App\Entity\InviteRecord;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Uid\Uuid;

class AuthControllerTest extends ApiTestCase
{
    public function testMeReturns401WhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'GET', '/api/me');

        self::assertSame(401, $result['status']);
        self::assertSame('Not authenticated.', $result['json']['error']);
    }

    public function testMeReturnsTheCurrentUserAfterActivation(): void
    {
        $client = static::createClient();
        $user = $this->activateUser($client, $this->uniqueEmail('auth-me'));

        $result = $this->jsonRequest($client, 'GET', '/api/me');

        self::assertSame(200, $result['status']);
        self::assertSame($user['id'], $result['json']['id']);
        self::assertSame($user['email'], $result['json']['email']);
        self::assertSame('', $result['json']['displayName']);
        self::assertArrayHasKey('publicKey', $result['json']);
        self::assertArrayHasKey('encryptedPrivateKey', $result['json']);
        self::assertFalse($result['json']['isDemo']);
    }

    public function testLoginSucceedsWithTheCredentialsSetAtActivation(): void
    {
        $email = $this->uniqueEmail('auth-login-ok');
        $client = static::createClient();
        $this->activateUser($client, $email);
        // Activation logs in immediately; log out so login() starts from a clean session.
        $this->jsonRequest($client, 'POST', '/api/logout');

        $result = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('a', 44), // matches ApiTestCase::activateUser()
        ]);

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('publicKey', $result['json']);
    }

    public function testLoginFailsWithTheWrongPassword(): void
    {
        $email = $this->uniqueEmail('auth-login-bad');
        $client = static::createClient();
        $this->activateUser($client, $email);
        $this->jsonRequest($client, 'POST', '/api/logout');

        $result = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('z', 44),
        ]);

        self::assertSame(401, $result['status']);
        self::assertSame('Invalid email or password.', $result['json']['error']);
    }

    public function testLoginFailsForAnUnknownEmail(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $this->uniqueEmail('no-such-user'),
            'authKey' => str_repeat('a', 44),
        ]);

        self::assertSame(401, $result['status']);
    }

    public function testLoginRejectsABlockedAccountAfterProvingTheCorrectPassword(): void
    {
        $email = $this->uniqueEmail('auth-login-blocked');
        $client = static::createClient();
        $user = $this->activateUser($client, $email);
        $this->jsonRequest($client, 'POST', '/api/logout');

        $entity = $this->entityManager()->find(User::class, $user['id']);
        \assert($entity instanceof User);
        $entity->setBlocked(true);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('a', 44),
        ]);

        self::assertSame(403, $result['status']);
        self::assertSame('This account has been blocked.', $result['json']['error']);
    }

    public function testStateChangingRequestWithoutACsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'x@example.com', 'authKey' => str_repeat('a', 44)], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testErrorMessagesAreTranslatedByTheXLocaleHeader(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'GET', '/api/me', extraHeaders: ['HTTP_X_LOCALE' => 'ru']);

        self::assertSame('Вы не авторизованы.', $result['json']['error']);
    }

    public function testSetLocaleUpdatesTheStoredLocale(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-locale'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/locale', ['locale' => 'es']);

        self::assertSame(200, $result['status']);
        self::assertSame('es', $result['json']['locale']);
    }

    public function testSetLocaleRejectsAnUnsupportedCode(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-locale-bad'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/locale', ['locale' => 'it']);

        self::assertSame(400, $result['status']);
    }

    public function testSetDisplayNameRequiresAuthentication(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'PUT', '/api/me/display-name', ['displayName' => 'Alex Morgan']);

        self::assertSame(401, $result['status']);
    }

    public function testSetDisplayNameUpdatesTheStoredValueAndTrimsWhitespace(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-name-ok'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/display-name', ['displayName' => '  Alex Morgan  ']);

        self::assertSame(200, $result['status']);
        self::assertSame('Alex Morgan', $result['json']['displayName']);

        $me = $this->jsonRequest($client, 'GET', '/api/me');
        self::assertSame('Alex Morgan', $me['json']['displayName']);
    }

    public function testSetDisplayNameStripsBidiOverrideAndZeroWidthCharacters(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-name-bidi'));

        // U+202E (RIGHT-TO-LEFT OVERRIDE) and U+200B (ZERO WIDTH SPACE) — left in, this
        // value is echoed verbatim into the counterpart-picker another user clicks to
        // choose who to share a new anketa with, so a bidi override could visually
        // reorder it to impersonate a different real name there.
        $result = $this->jsonRequest($client, 'PUT', '/api/me/display-name', ['displayName' => "Alex\u{202E} Morgan\u{200B}"]);

        self::assertSame(200, $result['status']);
        self::assertSame('Alex Morgan', $result['json']['displayName']);
    }

    public function testSetDisplayNameAcceptsAnEmptyStringToClearIt(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-name-clear'), displayName: 'Alex Morgan');

        $result = $this->jsonRequest($client, 'PUT', '/api/me/display-name', ['displayName' => '']);

        self::assertSame(200, $result['status']);
        self::assertSame('', $result['json']['displayName']);
    }

    public function testSetDisplayNameRejectsANonStringValue(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-name-bad-type'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/display-name', ['displayName' => 42]);

        self::assertSame(400, $result['status']);
    }

    public function testSetDisplayNameRejectsATooLongValue(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-name-too-long'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/display-name', ['displayName' => str_repeat('x', 256)]);

        self::assertSame(400, $result['status']);
    }

    /**
     * The 255-char cap must count characters, not bytes — a Cyrillic name is 2 bytes
     * per character in UTF-8, so a byte-length check would wrongly reject a name well
     * under the real limit (this app explicitly supports Russian and Latvian display
     * names, per the demo fixture's own seeded names).
     */
    public function testSetDisplayNameAcceptsA200CharacterMultiByteName(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-name-multibyte'));
        $name = str_repeat('Ж', 200); // 200 chars, 400 bytes in UTF-8

        $result = $this->jsonRequest($client, 'PUT', '/api/me/display-name', ['displayName' => $name]);

        self::assertSame(200, $result['status']);
        self::assertSame($name, $result['json']['displayName']);
    }

    public function testLogoutEndsTheSession(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-logout'));

        $logout = $this->jsonRequest($client, 'POST', '/api/logout');
        self::assertSame(200, $logout['status']);

        $me = $this->jsonRequest($client, 'GET', '/api/me');
        self::assertSame(401, $me['status']);
    }

    public function testLoginIsRateLimitedAfterTooManyAttempts(): void
    {
        $client = static::createClient();

        // The configured limit (20/minute, config/packages/rate_limiter.php) — each of
        // these is a normal invalid-credentials rejection, not yet rate-limited.
        for ($i = 0; $i < 20; ++$i) {
            $result = $this->jsonRequest($client, 'POST', '/api/login', [
                'email' => 'nobody@example.com',
                'authKey' => str_repeat('z', 44),
            ]);
            self::assertSame(401, $result['status'], "attempt {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => 'nobody@example.com',
            'authKey' => str_repeat('z', 44),
        ]);

        self::assertSame(429, $limited['status']);
        self::assertSame('Too many requests. Please try again later.', $limited['json']['error']);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
    }

    public function testChangePasswordRequiresAuthentication(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'PUT', '/api/me/password', [
            'currentAuthKey' => str_repeat('a', 44),
            'newAuthKey' => str_repeat('x', 44),
            'newEncryptedPrivateKey' => str_repeat('y', 44),
        ]);

        self::assertSame(401, $result['status']);
    }

    public function testChangePasswordRejectsMissingFields(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('change-password-missing'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/password', [
            'currentAuthKey' => str_repeat('a', 44),
        ]);

        self::assertSame(400, $result['status']);
    }

    public function testChangePasswordRejectsTheWrongCurrentPassword(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('change-password-wrong-current'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/password', [
            'currentAuthKey' => str_repeat('z', 44), // activateUser() sets it to "a" x 44
            'newAuthKey' => str_repeat('x', 44),
            'newEncryptedPrivateKey' => str_repeat('y', 44),
        ]);

        self::assertSame(401, $result['status']);
        self::assertSame('Incorrect current password.', $result['json']['error']);
    }

    public function testChangePasswordSucceedsAndSwapsWhichAuthKeyLogsIn(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('change-password-ok');
        $this->activateUser($client, $email);

        $result = $this->jsonRequest($client, 'PUT', '/api/me/password', [
            'currentAuthKey' => str_repeat('a', 44),
            'newAuthKey' => str_repeat('x', 44),
            'newEncryptedPrivateKey' => str_repeat('y', 44),
        ]);
        self::assertSame(200, $result['status']);

        // publicKey is untouched — no anketa re-sharing consequence for this flow.
        $me = $this->jsonRequest($client, 'GET', '/api/me');
        self::assertSame(str_repeat('y', 44), $me['json']['encryptedPrivateKey']);

        $this->jsonRequest($client, 'POST', '/api/logout');

        $oldLogin = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('a', 44),
        ]);
        self::assertSame(401, $oldLogin['status']);

        $newLogin = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('x', 44),
        ]);
        self::assertSame(200, $newLogin['status']);
    }

    public function testMeIncludesMeetingRemindersEnabledDefaultingToTrue(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-me-reminders-default'));

        $result = $this->jsonRequest($client, 'GET', '/api/me');

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['meetingRemindersEnabled']);
    }

    public function testMeReflectsIsDemoWhenSet(): void
    {
        $client = static::createClient();
        $user = $this->activateUser($client, $this->uniqueEmail('auth-me-demo'));

        $entity = $this->entityManager()->find(User::class, $user['id']);
        \assert($entity instanceof User);
        $entity->setDemo(true);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'GET', '/api/me');

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['isDemo']);
    }

    public function testSetNotificationPreferencesRequiresAuthentication(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'PUT', '/api/me/notification-preferences', ['meetingRemindersEnabled' => false]);

        self::assertSame(401, $result['status']);
    }

    public function testSetNotificationPreferencesRejectsANonBooleanValue(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-notif-prefs-bad'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/notification-preferences', ['meetingRemindersEnabled' => 'nope']);

        self::assertSame(400, $result['status']);
    }

    public function testSetNotificationPreferencesUpdatesTheStoredValue(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-notif-prefs-ok'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/notification-preferences', ['meetingRemindersEnabled' => false]);
        self::assertSame(200, $result['status']);
        self::assertFalse($result['json']['meetingRemindersEnabled']);

        $me = $this->jsonRequest($client, 'GET', '/api/me');
        self::assertFalse($me['json']['meetingRemindersEnabled']);
    }

    public function testChangePasswordIsRateLimitedAfterTooManyAttempts(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('change-password-rate-limit'));

        // Configured limit: 5/hour (config/packages/rate_limiter.php). Wrong current
        // password each time — rate-limit consumption happens before that check, so a
        // 401 here still counts as "not yet rate-limited."
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->jsonRequest($client, 'PUT', '/api/me/password', [
                'currentAuthKey' => str_repeat('z', 44),
                'newAuthKey' => str_repeat('x', 44),
                'newEncryptedPrivateKey' => str_repeat('y', 44),
            ]);
            self::assertSame(401, $result['status'], "attempt {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'PUT', '/api/me/password', [
            'currentAuthKey' => str_repeat('z', 44),
            'newAuthKey' => str_repeat('x', 44),
            'newEncryptedPrivateKey' => str_repeat('y', 44),
        ]);

        self::assertSame(429, $limited['status']);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
    }

    public function testDeleteAccountRequiresAuthentication(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('a', 44)]);

        self::assertSame(401, $result['status']);
    }

    public function testDeleteAccountRejectsAMissingCurrentAuthKey(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('delete-account-missing'));

        $result = $this->jsonRequest($client, 'DELETE', '/api/me', []);

        self::assertSame(400, $result['status']);
    }

    public function testDeleteAccountRejectsTheWrongCurrentPassword(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('delete-account-wrong-password'));

        $result = $this->jsonRequest($client, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('z', 44)]);

        self::assertSame(401, $result['status']);
        self::assertSame('Incorrect current password.', $result['json']['error']);
    }

    public function testDeleteAccountSucceedsAndTheOldAuthKeyStopsWorking(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('delete-account-ok');
        $this->activateUser($client, $email);

        $result = $this->jsonRequest($client, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('a', 44)]);
        self::assertSame(200, $result['status']);

        // Logged out as a side effect of deletion.
        $me = $this->jsonRequest($client, 'GET', '/api/me');
        self::assertSame(401, $me['status']);

        $login = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('a', 44),
        ]);
        self::assertSame(401, $login['status']);
    }

    /**
     * AccountDeleter::delete() scrubs InviteRecord.email for this exact address — the
     * same "delete means delete" anonymization User::delete() already does for the
     * users table itself (GitHub issue #24: without this, a deleted user's real
     * address would keep sitting in the invite-history table for the rest of its
     * retention window). Uses the real activation flow (shared id between the
     * ActivationToken and its InviteRecord, completed for real) so the InviteRecord
     * being scrubbed is genuinely the one that produced this account — not just any
     * row sharing its email, which is exactly the distinction
     * testDeleteAccountDoesNotScrubAStillPendingDuplicateInviteAtTheSameCompany below
     * exists to draw.
     */
    public function testDeleteAccountScrubsItsOwnAcceptedInviteRecordEmail(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('delete-account-invite-scrub');
        [$rawToken, $inviteRecordId] = $this->issueInvite($email);
        $activate = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);
        self::assertSame(200, $activate['status']);

        $result = $this->jsonRequest($client, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('a', 44)]);
        self::assertSame(200, $result['status']);

        $persisted = $this->entityManager()->find(InviteRecord::class, $inviteRecordId);
        self::assertNotNull($persisted);
        self::assertNotSame($email, $persisted->getEmail());
        self::assertStringEndsWith('@deleted.invalid', $persisted->getEmail());
    }

    /**
     * Nothing stops a duplicate re-invite to the same address before the first is
     * accepted (InviteController::create() only checks for an existing User row) — a
     * still-pending one sharing this exact email at the same company must survive,
     * since its own ActivationToken is still genuinely completable by someone else.
     */
    public function testDeleteAccountDoesNotScrubAStillPendingDuplicateInviteAtTheSameCompany(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('delete-account-duplicate-invite');
        $company = $this->singleCompanyProvider()->get();
        [$rawToken, $acceptedInviteId] = $this->issueInvite($email);
        $duplicateInviteId = Uuid::v7()->toRfc4122();
        $duplicateInvite = new InviteRecord($duplicateInviteId, $email, $company, null, new \DateTimeImmutable('+1 day'));
        $this->entityManager()->persist($duplicateInvite);
        $this->entityManager()->flush();

        $activate = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);
        self::assertSame(200, $activate['status']);
        $this->jsonRequest($client, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('a', 44)]);

        self::assertNotSame($email, $this->entityManager()->find(InviteRecord::class, $acceptedInviteId)?->getEmail(), 'the accepted invite that produced this account must still be scrubbed');
        self::assertSame($email, $this->entityManager()->find(InviteRecord::class, $duplicateInviteId)?->getEmail(), 'a still-pending duplicate invite must survive untouched');
    }

    /**
     * The company/email scope on the accepted-or-expired scrub query must apply to
     * BOTH branches of that OR, not just the accepted one — an unparenthesized DQL
     * "acceptedAt IS NOT NULL OR expiresAt <= :now" would combine with AND-ed
     * email/company clauses as "(email AND company AND accepted) OR (expired)", with
     * the second disjunct carrying no email/company filter at all. This test uses an
     * *expired* row at an unrelated company/email specifically to exercise that
     * branch — testDeleteAccountDoesNotScrubAnUnrelatedInviteAtAnotherCompany below
     * only covers a still-pending row, which the OR's first branch already excludes
     * regardless of this bug.
     */
    public function testDeleteAccountDoesNotScrubAnExpiredInviteAtAnotherCompany(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('delete-account-expired-cross-company');
        $ownCompany = $this->singleCompanyProvider()->get();
        $otherCompany = $this->makeCompany('Delete Scrub Expired Isolation Co');
        $unrelatedExpiredId = Uuid::v7()->toRfc4122();
        $unrelatedExpired = new InviteRecord($unrelatedExpiredId, $this->uniqueEmail('delete-account-expired-unrelated'), $otherCompany, null, new \DateTimeImmutable('-1 minute'));
        $this->entityManager()->persist($unrelatedExpired);
        $this->entityManager()->flush();
        $unrelatedExpiredEmail = $unrelatedExpired->getEmail();

        $this->activateUser($client, $email, company: $ownCompany);
        $result = $this->jsonRequest($client, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('a', 44)]);
        self::assertSame(200, $result['status']);

        self::assertSame($unrelatedExpiredEmail, $this->entityManager()->find(InviteRecord::class, $unrelatedExpiredId)?->getEmail(), 'an expired invite at an unrelated company, with an unrelated email, must survive this account\'s deletion untouched');
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

    /**
     * Unlike users.email, activation_tokens/invite_records carry no cross-company
     * uniqueness constraint — an unrelated, still-pending invite at a different
     * company can share this exact email address (GitHub issue #24). Deleting this
     * account must not reach into that other tenant's InviteRecord row.
     */
    public function testDeleteAccountDoesNotScrubAnUnrelatedInviteAtAnotherCompany(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('delete-account-cross-company');
        // Resolved before makeCompany() below creates a second row — SingleCompanyProvider
        // (which activateUser()'s default company resolution uses) refuses to guess once
        // more than one company exists.
        $ownCompany = $this->singleCompanyProvider()->get();
        $otherCompany = $this->makeCompany('Delete Scrub Isolation Co');
        $otherCompanyInviteId = Uuid::v7()->toRfc4122();
        $otherCompanyInvite = new InviteRecord($otherCompanyInviteId, $email, $otherCompany, null, new \DateTimeImmutable('+1 day'));
        $this->entityManager()->persist($otherCompanyInvite);
        $this->entityManager()->flush();

        $this->activateUser($client, $email, company: $ownCompany);
        $result = $this->jsonRequest($client, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('a', 44)]);
        self::assertSame(200, $result['status']);

        $persisted = $this->entityManager()->find(InviteRecord::class, $otherCompanyInviteId);
        self::assertNotNull($persisted);
        self::assertSame($email, $persisted->getEmail(), 'a pending invite at an unrelated company must survive this account\'s deletion untouched');
    }

    public function testDeleteAccountIsRateLimitedAfterTooManyAttempts(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('delete-account-rate-limit'));

        // Configured limit: 5/hour. Wrong current password each time — rate-limit
        // consumption happens before that check, so a 401 here still counts as
        // "not yet rate-limited."
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->jsonRequest($client, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('z', 44)]);
            self::assertSame(401, $result['status'], "attempt {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('z', 44)]);

        self::assertSame(429, $limited['status']);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
    }

    /** @var list<string> */
    private array $createdCompanyIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdCompanyIds) {
            $connection = $this->entityManager()->getConnection();
            $placeholders = implode(',', array_fill(0, \count($this->createdCompanyIds), '?'));
            $connection->executeStatement("DELETE FROM invite_records WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM activation_tokens WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM users WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM companies WHERE id IN ({$placeholders})", $this->createdCompanyIds);
        }

        parent::tearDown();
    }

    private function makeCompany(string $name): Company
    {
        $company = new Company($name);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();

        return $company;
    }
}
