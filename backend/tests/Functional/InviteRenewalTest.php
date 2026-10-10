<?php

namespace App\Tests\Functional;

use App\Controller\ActivationController;
use App\Entity\ActivationToken;
use App\Entity\Company;
use App\Entity\InviteRecord;
use App\Entity\User;
use App\Invite\InviteRenewal;
use App\Notification\InvitationNotifier;
use App\Security\AuthSession;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GitHub issue #169: what an activation link that can't be completed tells its holder
 * (GET /api/activation-tokens/{token}), and the "Request new invitation" endpoint.
 * Each test works in a company of its own, so which admins get the request is exact.
 */
class InviteRenewalTest extends ApiTestCase
{
    use CleansUpCompanies;

    /** Children first, for the foreign keys (CleansUpCompanies). */
    private const array COMPANY_TABLES = ['invite_records', 'activation_tokens', 'users'];

    public function testLookupOfAnUnknownTokenIs404WithACode(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'GET', '/api/activation-tokens/bogus-token');

        self::assertSame(404, $result['status']);
        self::assertSame('invalid_token', $result['json']['code']);
    }

    public function testLookupOfAUsedTokenIs409AlreadyActivated(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal used');
        [$rawToken] = $this->issueInvite($company, null, $this->uniqueEmail('renewal-used'), '+1 day');
        $complete = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);
        self::assertSame(200, $complete['status']);

        $result = $this->jsonRequest($this->secondClient(), 'GET', "/api/activation-tokens/{$rawToken}");

        self::assertSame(409, $result['status']);
        self::assertSame('already_activated', $result['json']['code']);
        self::assertSame('This account is already active. Please log in.', $result['json']['error']);
    }

    public function testLookupOfAnExpiredInviteOffersARenewal(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal available');
        $inviter = $this->makeUser($company, 'renewal-inviter');
        [$rawToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-invitee'));

        $result = $this->jsonRequest($this->secondClient(), 'GET', "/api/activation-tokens/{$rawToken}");

        self::assertSame(410, $result['status']);
        self::assertSame('expired', $result['json']['code']);
        self::assertSame('available', $result['json']['renewal']);
        self::assertArrayNotHasKey('email', $result['json']);
    }

    /** Re-invited and activated through the newer link: the old one now says so. */
    public function testLookupOfAnExpiredLinkWhoseEmailHasSinceRegisteredIs409(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal registered since');
        $email = $this->uniqueEmail('renewal-registered');
        [$rawToken] = $this->issueInvite($company, null, $email);
        $this->activateUser($this->secondClient(), $email, company: $company);

        $result = $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$rawToken}");

        self::assertSame(409, $result['status']);
        self::assertSame('already_activated', $result['json']['code']);
    }

    public function testLookupOfAnExpiredLinkWithANewerUsableInviteIsReissued(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal reissued');
        $inviter = $this->makeUser($company, 'renewal-reissued-inviter');
        $email = $this->uniqueEmail('renewal-reissued');
        [$oldToken] = $this->issueInvite($company, $inviter, $email);
        $this->issueInvite($company, $inviter, $email, '+1 day');

        $result = $this->jsonRequest($this->secondClient(), 'GET', "/api/activation-tokens/{$oldToken}");

        self::assertSame(410, $result['status']);
        self::assertSame('reissued', $result['json']['renewal']);
    }

    /** A newer invite to the same address at another company is a different invite. */
    public function testANewerInviteAtAnotherCompanyDoesNotCountAsReissued(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal tenant A');
        $otherCompany = $this->makeCompany('Renewal tenant B');
        $inviter = $this->makeUser($company, 'renewal-tenant-inviter');
        $email = $this->uniqueEmail('renewal-tenant');
        [$oldToken] = $this->issueInvite($company, $inviter, $email);
        $this->issueInvite($otherCompany, null, $email, '+1 day');

        $result = $this->jsonRequest($this->secondClient(), 'GET', "/api/activation-tokens/{$oldToken}");

        self::assertSame('available', $result['json']['renewal']);
    }

    /** CLI bootstrap and cloud company creation write no InviteRecord: nobody to ask. */
    public function testLookupOfAnExpiredTokenWithNoInviteRecordHasNoRenewal(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal none');
        $rawToken = $this->issueToken($company, $this->uniqueEmail('renewal-none'), '-1 hour');

        $result = $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$rawToken}");

        self::assertSame(410, $result['status']);
        self::assertSame('none', $result['json']['renewal']);
    }

    /**
     * CLOUD_MODE is off in the test environment (see CompanyControllerTest), so this
     * builds the controller with it on: an expired company-creation link (an admin
     * token with no InviteRecord) points back to company creation, and an expired
     * domain-mode self-registration doesn't point to the sign-up that Cloud turns off.
     */
    public function testOnCloudExpiredLinksPointToCompanyCreationNotSignup(): void
    {
        static::createClient();
        $company = $this->makeCompany('Renewal cloud');
        $company->updateSettings('domain', '');
        $this->entityManager()->flush();
        $adminToken = bin2hex(random_bytes(32));
        $this->entityManager()->persist(new ActivationToken(hash('sha256', $adminToken), $this->uniqueEmail('renewal-cloud-admin'), $company, true, new \DateTimeImmutable('-1 hour')));
        $this->entityManager()->flush();
        $memberToken = $this->issueToken($company, $this->uniqueEmail('renewal-cloud-member'), '-1 hour');
        [$signupToken] = $this->issueInvite($company, null, $this->uniqueEmail('renewal-cloud-signup'));
        $container = self::getContainer();
        $authSession = $container->get(AuthSession::class);
        $translator = $container->get('translator');
        $notifier = $container->get(InvitationNotifier::class);
        $completeLimiter = $container->get('limiter.activation_complete');
        $renewalLimiter = $container->get('limiter.invite_renewal_request');
        \assert($authSession instanceof AuthSession && $translator instanceof TranslatorInterface && $notifier instanceof InvitationNotifier);
        \assert($completeLimiter instanceof RateLimiterFactory && $renewalLimiter instanceof RateLimiterFactory);
        $tokens = $this->entityManager()->getRepository(ActivationToken::class);
        $invites = $this->entityManager()->getRepository(InviteRecord::class);
        $controller = new ActivationController(
            $this->entityManager(),
            $authSession,
            $translator,
            $tokens,
            $invites,
            new InviteRenewal($this->entityManager(), $tokens, $invites, true),
            $notifier,
            true,
            $completeLimiter,
            $renewalLimiter,
        );

        $renewal = fn (string $token) => json_decode((string) $controller->lookup($token)->getContent(), true)['renewal'];

        self::assertSame('create_company', $renewal($adminToken));

        // Starting again on Cloud makes a new company; the old link then says so.
        $restarted = $this->makeCompany('Renewal cloud restarted');
        $adminEmail = $this->entityManager()->getRepository(ActivationToken::class)->findOneBy(['tokenHash' => hash('sha256', $adminToken)])?->getEmail();
        \assert(\is_string($adminEmail));
        $this->issueToken($restarted, $adminEmail, '+1 day');
        self::assertSame('reissued', $renewal($adminToken));
        self::assertSame('none', $renewal($memberToken));
        self::assertSame('none', $renewal($signupToken));
    }

    /** Activated through another, still-valid invite: the remaining link says so. */
    public function testAStillValidLinkToAnAddressThatHasActivatedReadsAsAlreadyActivated(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal second link');
        $inviter = $this->makeUser($company, 'renewal-second-link-inviter');
        $email = $this->uniqueEmail('renewal-second-link');
        [$firstToken] = $this->issueInvite($company, $inviter, $email, '+1 day');
        [$secondToken] = $this->issueInvite($company, $inviter, $email, '+1 day');
        $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$secondToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);

        $result = $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$firstToken}");

        self::assertSame([409, 'already_activated'], [$result['status'], $result['json']['code']]);
    }

    public function testAnExpiredSelfRegistrationPointsBackToSignupOnlyInDomainMode(): void
    {
        $client = static::createClient();
        $domainCompany = $this->makeCompany('Renewal domain');
        $domainCompany->updateSettings('domain', '');
        $inviteCompany = $this->makeCompany('Renewal invite mode');
        $this->entityManager()->flush();
        [$domainToken] = $this->issueInvite($domainCompany, null, $this->uniqueEmail('renewal-signup'));
        [$inviteToken] = $this->issueInvite($inviteCompany, null, $this->uniqueEmail('renewal-signup-closed'));

        self::assertSame('signup', $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$domainToken}")['json']['renewal']);
        self::assertSame('none', $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$inviteToken}")['json']['renewal']);
    }

    /**
     * A self-registration whose company has since closed sign-up: the page can't send
     * the person back to sign-up, so the admins are asked, and their list offers
     * Re-send for the row, the same as for an invite.
     */
    public function testAnExpiredSelfRegistrationAfterSignupClosedGoesToTheAdmins(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal signup closed');
        $adminData = $this->activateUser($client, $this->uniqueEmail('renewal-signup-closed-admin'), admin: true, company: $company);
        [$rawToken, $inviteRecordId] = $this->issueInvite($company, null, $this->uniqueEmail('renewal-signup-closed'));

        $lookup = $this->jsonRequest($this->secondClient(), 'GET', "/api/activation-tokens/{$rawToken}");
        self::assertSame('available', $lookup['json']['renewal']);
        self::assertTrue($this->adminListRow($client, $inviteRecordId)['resendable']);

        $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame($adminData['email'], $message->getTo()[0]->getAddress());
    }

    /**
     * The token decides the company, not the session: someone still logged in to one
     * company who opens a link into another sees that link's real state.
     */
    public function testALinkIntoAnotherCompanyWorksForAVisitorLoggedInElsewhere(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('renewal-visitor'), company: $this->makeCompany('Renewal visitor home'));
        $company = $this->makeCompany('Renewal visitor target');
        $inviter = $this->makeUser($company, 'renewal-visitor-inviter');
        $email = $this->uniqueEmail('renewal-visitor-invitee');
        [$usableToken] = $this->issueInvite($company, $inviter, $email, '+1 day');
        [$expiredToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-visitor-expired'));

        $usable = $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$usableToken}");
        $expired = $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$expiredToken}");

        self::assertSame([200, $email], [$usable['status'], $usable['json']['email']]);
        self::assertSame([410, 'available'], [$expired['status'], $expired['json']['renewal']]);
    }

    /**
     * The allowed domain changed since: neither signing up again nor an admin's re-send
     * would accept the address, so nothing is offered, for a self-registration or an
     * invite alike, and the admin list agrees.
     */
    #[DataProvider('registrationModes')]
    public function testAnAddressOutsideTheAllowedDomainCannotBeRenewed(string $mode): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal domain moved');
        $adminData = $this->activateUser($client, sprintf('renewal-admin-%s@new.example', bin2hex(random_bytes(4))), admin: true, company: $company);
        $admin = $this->entityManager()->find(User::class, $adminData['id']);
        \assert($admin instanceof User);
        $company = $this->entityManager()->find(Company::class, $company->getId());
        \assert($company instanceof Company);
        $company->updateSettings($mode, 'new.example');
        $this->entityManager()->flush();
        [$selfRegistered, $selfRegisteredRow] = $this->issueInvite($company, null, sprintf('renewal-%s@old.example', bin2hex(random_bytes(4))));
        [$invited, $invitedRow] = $this->issueInvite($company, $admin, sprintf('renewal-%s@old.example', bin2hex(random_bytes(4))));

        self::assertSame('none', $this->jsonRequest($this->secondClient(), 'GET', "/api/activation-tokens/{$selfRegistered}")['json']['renewal']);
        self::assertSame('none', $this->jsonRequest($this->secondClient(), 'GET', "/api/activation-tokens/{$invited}")['json']['renewal']);
        self::assertFalse($this->adminListRow($client, $selfRegisteredRow)['resendable']);
        self::assertFalse($this->adminListRow($client, $invitedRow)['resendable']);
    }

    /** @return iterable<string, array{string}> */
    public static function registrationModes(): iterable
    {
        yield 'domain' => ['domain'];
        yield 'invite' => ['invite'];
    }

    /**
     * Sign-up only checked the domain suffix; an address that isn't a plain email never
     * goes into the admins' inbox.
     */
    public function testASelfRegistrationWithAMalformedAddressIsNotSentToTheAdmins(): void
    {
        static::createClient();
        $company = $this->makeCompany('Renewal malformed');
        $this->makeUser($company, 'renewal-malformed-admin', admin: true);
        [$rawToken] = $this->issueInvite($company, null, 'Reset your password at https://evil.example now x@corp.example');

        $result = $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");

        self::assertSame([410, 'none'], [$result['status'], $result['json']['renewal']]);
        self::assertEmailCount(0);
    }

    /** A suspended company's admins can't log in to re-send anything. */
    public function testNothingIsRenewableInASuspendedCompany(): void
    {
        static::createClient();
        $company = $this->makeCompany('Renewal suspended');
        $inviter = $this->makeUser($company, 'renewal-suspended-inviter', admin: true);
        [$rawToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-suspended'));
        $company = $this->entityManager()->find(Company::class, $company->getId());
        \assert($company instanceof Company);
        $company->suspend();
        $this->entityManager()->flush();

        $result = $this->jsonRequest($this->secondClient(), 'GET', "/api/activation-tokens/{$rawToken}");

        self::assertSame('none', $result['json']['renewal']);
    }

    public function testRequestingARenewalEmailsTheInviterOnceAndStartsTheCooldown(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal request');
        $inviter = $this->makeUser($company, 'renewal-request-inviter', locale: 'de');
        $inviteeEmail = $this->uniqueEmail('renewal-request');
        [$rawToken, $inviteRecordId] = $this->issueInvite($company, $inviter, $inviteeEmail);
        $invitee = $this->secondClient();

        $result = $this->jsonRequest($invitee, 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");

        self::assertSame(200, $result['status']);
        self::assertSame('requested', $result['json']['renewal']);
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame($inviter->getEmail(), $message->getTo()[0]->getAddress());
        self::assertSame("{$inviteeEmail} bittet um eine neue Einladung zu encrypted1on1", $message->getSubject());
        self::assertStringContainsString('/account?invite='.rawurlencode($inviteeEmail), (string) $message->getTextBody());
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(InviteRecord::class, $inviteRecordId)?->getRenewalRequestedAt());

        $again = $this->jsonRequest($invitee, 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");
        self::assertSame([410, 'requested'], [$again['status'], $again['json']['renewal']]);
        self::assertEmailCount(0);

        $lookup = $this->jsonRequest($invitee, 'GET', "/api/activation-tokens/{$rawToken}");
        self::assertSame('requested', $lookup['json']['renewal']);
    }

    public function testARenewalCanBeRequestedAgainOnceTheCooldownHasPassed(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal cooldown');
        $inviter = $this->makeUser($company, 'renewal-cooldown-inviter');
        [$rawToken, $inviteRecordId] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-cooldown'));
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE invite_records SET renewalRequestedAt = ? WHERE id = ?',
            [(new \DateTimeImmutable(sprintf('-%d hours -1 minute', InviteRecord::RENEWAL_COOLDOWN_HOURS)))->format('Y-m-d H:i:s'), $inviteRecordId],
        );

        $result = $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");

        self::assertSame(200, $result['status']);
        self::assertEmailCount(1);
    }

    public function testADeletedInvitersRequestGoesToTheCompanyAdmins(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal deleted inviter');
        $inviter = $this->makeUser($company, 'renewal-deleted-inviter');
        $adminA = $this->makeUser($company, 'renewal-admin-a', admin: true);
        $adminB = $this->makeUser($company, 'renewal-admin-b', admin: true);
        $deletedAdmin = $this->makeUser($company, 'renewal-admin-deleted', admin: true);
        $this->makeUser($this->makeCompany('Renewal other admins'), 'renewal-admin-elsewhere', admin: true);
        [$rawToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-deleted'));
        $this->deleteUser($inviter);
        $this->deleteUser($deletedAdmin);

        $result = $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");

        self::assertSame(200, $result['status']);
        $recipients = array_map(fn ($message) => $message instanceof Email ? $message->getTo()[0]->getAddress() : null, self::getMailerMessages());
        sort($recipients);
        $expected = [$adminA->getEmail(), $adminB->getEmail()];
        sort($expected);
        self::assertSame($expected, $recipients);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertStringContainsString('/admin/invites', (string) $message->getTextBody());
    }

    /**
     * Outside REGISTRATION_MODE=invite a non-admin has no invite form any more (the
     * company switched modes after they invited), so the admins are asked instead.
     */
    #[DataProvider('nonInviteModes')]
    public function testANonAdminInviterOutsideInviteModeIsSkippedForTheAdmins(string $mode): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal admin only');
        $company->updateSettings($mode, '');
        $this->entityManager()->flush();
        $inviter = $this->makeUser($company, 'renewal-admin-only-inviter');
        $admin = $this->makeUser($company, 'renewal-admin-only-admin', admin: true);
        [$rawToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-admin-only'));

        $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame($admin->getEmail(), $message->getTo()[0]->getAddress());
    }

    /** @return iterable<string, array{string}> */
    public static function nonInviteModes(): iterable
    {
        yield 'admin_only' => ['admin_only'];
        yield 'domain' => ['domain'];
    }

    public function testAnAdminInviterIsAskedInAnyMode(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal admin inviter');
        $company->updateSettings('admin_only', '');
        $this->entityManager()->flush();
        $inviter = $this->makeUser($company, 'renewal-admin-inviter', admin: true);
        $this->makeUser($company, 'renewal-other-admin', admin: true);
        [$rawToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-admin-inviter'));

        $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame($inviter->getEmail(), $message->getTo()[0]->getAddress());
    }

    public function testWithNobodyToAskTheRequestIsRefusedAndNotClaimed(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal nobody');
        $inviter = $this->makeUser($company, 'renewal-nobody-inviter');
        [$rawToken, $inviteRecordId] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-nobody'));
        $this->deleteUser($inviter);

        $result = $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");

        self::assertSame(410, $result['status']);
        self::assertSame('none', $result['json']['renewal']);
        self::assertEmailCount(0);
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(InviteRecord::class, $inviteRecordId)?->getRenewalRequestedAt());
    }

    /** A failed send must not leave the invitee told "sent", nor locked out for 24h. */
    public function testAFailedSendIs503AndReleasesTheClaim(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal send failure');
        $inviter = $this->makeUser($company, 'renewal-send-failure-inviter');
        [$rawToken, $inviteRecordId] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-send-failure'));

        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('smtp down'));
        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorInterface);
        $client->disableReboot();
        self::getContainer()->set(InvitationNotifier::class, new InvitationNotifier($mailer, $translator, 'https://example.com', 'noreply@example.com'));

        // The failure is error_log()ged; kept off STDERR, which Infection's initial test
        // run treats as a failure and stops on (same as AnketaNotifierTest).
        $log = tempnam(sys_get_temp_dir(), 'renewal-log');
        $previousLog = ini_set('error_log', (string) $log);
        try {
            $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");
        } finally {
            ini_set('error_log', false === $previousLog ? '' : $previousLog);
            @unlink((string) $log);
        }

        self::assertSame(503, $result['status']);
        self::assertSame('send_failed', $result['json']['code']);
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(InviteRecord::class, $inviteRecordId)?->getRenewalRequestedAt());
    }

    public function testRequestingARenewalForALinkThatNeedsNoneReturnsItsState(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal not needed');
        $inviter = $this->makeUser($company, 'renewal-not-needed-inviter');
        [$usableToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-usable'), '+1 day');
        $noRecordToken = $this->issueToken($company, $this->uniqueEmail('renewal-no-record'), '-1 hour');
        $invitee = $this->secondClient();

        $usable = $this->jsonRequest($invitee, 'POST', "/api/activation-tokens/{$usableToken}/request-renewal");
        $unknown = $this->jsonRequest($invitee, 'POST', '/api/activation-tokens/bogus-token/request-renewal');
        $noRecord = $this->jsonRequest($invitee, 'POST', "/api/activation-tokens/{$noRecordToken}/request-renewal");

        self::assertSame([409, 'not_expired'], [$usable['status'], $usable['json']['code']]);
        self::assertSame([404, 'invalid_token'], [$unknown['status'], $unknown['json']['code']]);
        self::assertSame([410, 'none'], [$noRecord['status'], $noRecord['json']['renewal']]);
        self::assertEmailCount(0);
    }

    /** Blocked accounts can't log in, so they can't re-invite either. */
    public function testABlockedInviterAndBlockedAdminsAreSkipped(): void
    {
        static::createClient();
        $company = $this->makeCompany('Renewal blocked');
        $inviter = $this->makeUser($company, 'renewal-blocked-inviter');
        $admin = $this->makeUser($company, 'renewal-blocked-admin-active', admin: true);
        $blockedAdmin = $this->makeUser($company, 'renewal-blocked-admin', admin: true);
        $inviter->setBlocked(true);
        $blockedAdmin->setBlocked(true);
        $this->entityManager()->flush();
        [$rawToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-blocked'));

        $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame($admin->getEmail(), $message->getTo()[0]->getAddress());
    }

    /** No button for a request nobody could act on (the lookup checks recipients too). */
    public function testWithNobodyToAskTheLookupHasNoRenewal(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal nobody lookup');
        $inviter = $this->makeUser($company, 'renewal-nobody-lookup-inviter');
        [$rawToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-nobody-lookup'));
        $this->deleteUser($inviter);

        $result = $this->jsonRequest($client, 'GET', "/api/activation-tokens/{$rawToken}");

        self::assertSame([410, 'none'], [$result['status'], $result['json']['renewal']]);
    }

    /**
     * A renewal belongs to the address: whichever old link is clicked, it's recorded on
     * the newest invite (the row the admin re-sends from), and one cooldown covers all
     * of that address's links.
     */
    public function testARequestFromAnOlderLinkIsRecordedOnTheNewestInviteOnce(): void
    {
        static::createClient();
        $company = $this->makeCompany('Renewal older link');
        $inviter = $this->makeUser($company, 'renewal-older-inviter');
        $email = $this->uniqueEmail('renewal-older');
        [$oldToken, $oldId] = $this->issueInvite($company, $inviter, $email, '-2 days');
        $this->backdate($oldId, '-3 days');
        [$newToken, $newId] = $this->issueInvite($company, $inviter, $email);
        $invitee = $this->secondClient();

        $first = $this->jsonRequest($invitee, 'POST', "/api/activation-tokens/{$oldToken}/request-renewal");
        $second = $this->jsonRequest($invitee, 'POST', "/api/activation-tokens/{$newToken}/request-renewal");

        self::assertSame(200, $first['status']);
        self::assertSame([410, 'requested'], [$second['status'], $second['json']['renewal']]);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(InviteRecord::class, $newId)?->getRenewalRequestedAt());
        self::assertNull($this->entityManager()->find(InviteRecord::class, $oldId)?->getRenewalRequestedAt());
    }

    /** A form opened while the link worked and submitted after it expired. */
    public function testCompletingAnExpiredLinkReturnsItsState(): void
    {
        static::createClient();
        $company = $this->makeCompany('Renewal complete expired');
        $inviter = $this->makeUser($company, 'renewal-complete-inviter');
        [$rawToken] = $this->issueInvite($company, $inviter, $this->uniqueEmail('renewal-complete'));

        $result = $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
        ]);

        self::assertSame([410, 'available'], [$result['status'], $result['json']['renewal']]);
    }

    public function testRenewalRequestsAreRateLimitedPerIp(): void
    {
        $client = static::createClient();

        // The configured default is 30/hour (config/packages/rate_limiter.php), consumed
        // before the token is looked up, so unknown tokens count too.
        for ($i = 0; $i < 30; ++$i) {
            $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/bogus-{$i}/request-renewal");
            self::assertSame(404, $result['status'], "attempt {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'POST', '/api/activation-tokens/bogus-overflow/request-renewal');

        self::assertSame(429, $limited['status']);
        self::assertArrayNotHasKey('code', $limited['json']);
    }

    public function testTheAdminInvitesListCarriesRenewalRequestedAt(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal admin list');
        $adminData = $this->activateUser($client, $this->uniqueEmail('renewal-list-admin'), admin: true, company: $company);
        $admin = $this->entityManager()->find(User::class, $adminData['id']);
        \assert($admin instanceof User);
        [$rawToken, $inviteRecordId] = $this->issueInvite($company, $admin, $this->uniqueEmail('renewal-list'));
        $this->jsonRequest($this->secondClient(), 'POST', "/api/activation-tokens/{$rawToken}/request-renewal");

        $row = $this->adminListRow($client, $inviteRecordId);

        self::assertIsString($row['renewalRequestedAt']);
        self::assertSame('expired', $row['status']);
        self::assertTrue($row['resendable']);
    }

    /**
     * Only each address's newest invite, when it expired unused, can be re-sent, and
     * not once account deletion has scrubbed its address.
     */
    public function testTheAdminInvitesListMarksOnlyTheNewestExpiredInviteResendable(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal resendable');
        $adminData = $this->activateUser($client, $this->uniqueEmail('renewal-resendable-admin'), admin: true, company: $company);
        $admin = $this->entityManager()->find(User::class, $adminData['id']);
        \assert($admin instanceof User);
        $superseded = $this->uniqueEmail('renewal-superseded');
        [, $supersededOld] = $this->issueInvite($company, $admin, $superseded);
        // Usually the same createdAt second as the row above: the id breaks the tie.
        [, $newest] = $this->issueInvite($company, $admin, $superseded);
        [, $pending] = $this->issueInvite($company, $admin, $this->uniqueEmail('renewal-pending'), '+1 day');
        [, $scrubbed] = $this->issueInvite($company, $admin, $this->uniqueEmail('renewal-scrubbed'));
        $this->entityManager()->find(InviteRecord::class, $scrubbed)?->scrubEmail();
        $this->entityManager()->flush();

        self::assertFalse($this->adminListRow($client, $supersededOld)['resendable']);
        self::assertTrue($this->adminListRow($client, $newest)['resendable']);
        self::assertFalse($this->adminListRow($client, $pending)['resendable']);
        self::assertFalse($this->adminListRow($client, $scrubbed)['resendable']);
    }

    /**
     * No Re-send where the expired link itself offers no renewal: the address has an
     * account by now, or another link to it (here a CLI-issued one) still works.
     */
    public function testTheAdminInvitesListDoesNotOfferResendWhereTheLinkWouldNot(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal resend blocked');
        $adminData = $this->activateUser($client, $this->uniqueEmail('renewal-resend-blocked-admin'), admin: true, company: $company);
        $admin = $this->entityManager()->find(User::class, $adminData['id']);
        \assert($admin instanceof User);
        $registered = $this->uniqueEmail('renewal-resend-registered');
        [, $registeredRow] = $this->issueInvite($company, $admin, $registered);
        $this->activateUser($this->secondClient(), $registered, company: $company);
        $withLiveLink = $this->uniqueEmail('renewal-resend-live');
        [, $liveLinkRow] = $this->issueInvite($company, $admin, $withLiveLink);
        $this->issueToken($company, $withLiveLink, '+1 day');

        self::assertFalse($this->adminListRow($client, $registeredRow)['resendable']);
        self::assertFalse($this->adminListRow($client, $liveLinkRow)['resendable']);
    }

    /**
     * The admin list asks InviteRenewal, like the link does: no Re-send for an address
     * with an account in another company (the CompanyFilter would hide it from a plain
     * repository lookup). testAnExpiredSelfRegistrationAfterSignupClosedGoesToTheAdmins
     * covers the self-registration side.
     */
    public function testTheAdminInvitesListUsesTheSameRuleAsTheLink(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Renewal same rule');
        $adminData = $this->activateUser($client, $this->uniqueEmail('renewal-same-rule-admin'), admin: true, company: $company);
        $admin = $this->entityManager()->find(User::class, $adminData['id']);
        \assert($admin instanceof User);
        $elsewhere = $this->uniqueEmail('renewal-elsewhere');
        [, $elsewhereRow] = $this->issueInvite($company, $admin, $elsewhere);
        $this->activateUser($this->secondClient(), $elsewhere, company: $this->makeCompany('Renewal same rule other'));

        self::assertFalse($this->adminListRow($client, $elsewhereRow)['resendable']);
    }

    /** The address decides, not the link: an old CLI-issued link finds the newer invite. */
    public function testAnOldLinkWithoutAnInviteRecordFindsTheNewerInvite(): void
    {
        static::createClient();
        $company = $this->makeCompany('Renewal old cli link');
        $inviter = $this->makeUser($company, 'renewal-old-cli-inviter');
        $email = $this->uniqueEmail('renewal-old-cli');
        $cliToken = $this->issueToken($company, $email, '-2 days');
        $this->issueInvite($company, $inviter, $email);

        $result = $this->jsonRequest($this->secondClient(), 'GET', "/api/activation-tokens/{$cliToken}");

        self::assertSame([410, 'available'], [$result['status'], $result['json']['renewal']]);
    }

    /** @return array<string, mixed> */
    private function adminListRow(KernelBrowser $client, string $inviteRecordId): array
    {
        $result = $this->jsonRequest($client, 'GET', '/api/admin/invites');
        self::assertSame(200, $result['status']);
        $row = current(array_filter($result['json'], fn (array $i) => $i['id'] === $inviteRecordId));
        self::assertIsArray($row);

        return $row;
    }

    private function backdate(string $inviteRecordId, string $createdAt): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE invite_records SET createdAt = ? WHERE id = ?',
            [(new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'), $inviteRecordId],
        );
    }

    /**
     * Persists a user in `$company` directly rather than activating one over HTTP: no
     * request runs, so the tenant filter stays off and the notifier service isn't built
     * yet (testAFailedSendIs503AndReleasesTheClaim replaces it).
     */
    private function makeUser(Company $company, string $label, bool $admin = false, string $locale = 'en'): User
    {
        $company = $this->entityManager()->find(Company::class, $company->getId());
        \assert($company instanceof Company);
        $user = new User($this->uniqueEmail($label), 'auth', 'public', 'private', $company, $admin, $locale);
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    /** Re-fetched first: a request in between leaves the given entity detached. */
    private function deleteUser(User $user): void
    {
        $managed = $this->entityManager()->find(User::class, $user->getId());
        \assert($managed instanceof User);
        $managed->delete();
        $this->entityManager()->flush();
    }

    private function issueToken(Company $company, string $email, string $expiresAt): string
    {
        $company = $this->entityManager()->find(Company::class, $company->getId());
        \assert($company instanceof Company);
        $rawToken = bin2hex(random_bytes(32));
        $this->entityManager()->persist(new ActivationToken(hash('sha256', $rawToken), $email, $company, false, new \DateTimeImmutable($expiresAt)));
        $this->entityManager()->flush();

        return $rawToken;
    }

    /**
     * A token plus its InviteRecord, the way InviteController::create() writes them,
     * expiring at `$expiresAt` (default: an hour ago).
     *
     * @return array{0: string, 1: string} raw token, InviteRecord id
     */
    private function issueInvite(Company $company, ?User $inviter, string $email, string $expiresAt = '-1 hour'): array
    {
        $em = $this->entityManager();
        $company = $em->find(Company::class, $company->getId());
        \assert($company instanceof Company);
        $inviter = null !== $inviter ? $em->find(User::class, $inviter->getId()) : null;
        $rawToken = bin2hex(random_bytes(32));
        $token = new ActivationToken(hash('sha256', $rawToken), $email, $company, false, new \DateTimeImmutable($expiresAt));
        $em->persist($token);
        $inviteRecord = new InviteRecord($token->getId(), $email, $company, $inviter, $token->getExpiresAt());
        $em->persist($inviteRecord);
        $em->flush();

        return [$rawToken, $inviteRecord->getId()];
    }
}
