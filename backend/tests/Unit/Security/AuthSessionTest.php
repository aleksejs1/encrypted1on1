<?php

namespace App\Tests\Unit\Security;

use App\Doctrine\CompanyFilter;
use App\Entity\Company;
use App\Entity\User;
use App\Security\AuthSession;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class AuthSessionTest extends TestCase
{
    public function testLogInEnablesAndConfiguresCompanyFilter(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $filter = new CompanyFilter($em);

        $filters = $this->createMock(FilterCollection::class);
        $filters->expects(self::once())
            ->method('enable')
            ->with(CompanyFilter::NAME)
            ->willReturn($filter);

        $em->method('getFilters')->willReturn($filters);

        $company = new Company('Acme');
        $refl = new \ReflectionProperty(Company::class, 'id');
        $refl->setValue($company, 'comp-456');
        $user = new User('user@example.com', 'h', 'p', 'e', $company);

        $session = $this->createMock(SessionInterface::class);
        $stored = [];
        $calls = [];
        $session->expects(self::exactly(3))->method('set')->willReturnCallback(
            static function (string $name, mixed $value) use (&$stored, &$calls): void {
                $calls[] = 'set';
                $stored[$name] = $value;
            },
        );
        // An ordinary login clears a remembered end left by an earlier one, and asks
        // for a cookie that ends with the browser.
        $session->expects(self::once())->method('remove')->with('remembered_until');
        $session->expects(self::once())->method('migrate')->with(false, 0)->willReturnCallback(
            static function () use (&$calls): bool {
                $calls[] = 'migrate';

                return true;
            },
        );

        $request = new Request();
        $request->setSession($session);

        $authSession = new AuthSession($em, new MockClock('2026-10-03 12:00:00'));
        $authSession->logIn($request, $user);

        // The id changes before the login is written: migrate() may save the session
        // under its old id, which must not hold a login.
        self::assertSame(['migrate', 'set', 'set', 'set'], $calls);
        self::assertTrue($filter->hasParameter(CompanyFilter::PARAMETER_NAME));
        self::assertSame(
            [
                'user_id' => $user->getId(),
                'last_active_at' => (new \DateTimeImmutable('2026-10-03 12:00:00'))->getTimestamp(),
                'credential_stamp' => hash('sha256', 'h'),
            ],
            $stored,
        );
    }

    public function testARememberedLogInStoresItsEndAndAsksForAThirtyDayCookie(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $stored = [];
        $session->method('set')->willReturnCallback(
            static function (string $name, mixed $value) use (&$stored): void {
                $stored[$name] = $value;
            },
        );
        $session->expects(self::never())->method('remove');
        $session->expects(self::once())->method('migrate')->with(false, AuthSession::REMEMBER_SECONDS);

        $request = new Request();
        $request->setSession($session);

        $clock = new MockClock('2026-10-03 12:00:00');
        new AuthSession($this->entityManagerFinding(null), $clock)->logIn($request, $this->user(), true);

        self::assertSame($clock->now()->getTimestamp() + AuthSession::REMEMBER_SECONDS, $stored['remembered_until']);
    }

    public function testARememberedSessionIgnoresTheIdleTimeoutUntilItsEnd(): void
    {
        $clock = new MockClock('2026-10-03 12:00:00');
        $user = $this->user();
        $request = $this->loggedInRequest($user, $clock, remember: true);
        $authSession = new AuthSession($this->entityManagerFinding($user), $clock);

        self::assertSame(AuthSession::REMEMBER_SECONDS, $authSession->rememberedSecondsLeft($request));

        // Exactly at its end, after 30 days without a single request: still valid.
        $clock->sleep(AuthSession::REMEMBER_SECONDS);
        self::assertSame($user, $authSession->getCurrentUser($request));
        // Activity doesn't move the end.
        self::assertSame(0, $authSession->rememberedSecondsLeft($request));

        $clock->sleep(1);
        self::assertNull($authSession->getCurrentUser($request));
        self::assertNull($authSession->rememberedSecondsLeft($request));
        self::assertSame(['unrelated' => 'kept'], $request->getSession()->all());
    }

    public function testAnOrdinarySessionIsNotRemembered(): void
    {
        $clock = new MockClock('2026-10-03 12:00:00');
        $user = $this->user();
        $request = $this->loggedInRequest($user, $clock, remember: false);

        self::assertNull(new AuthSession($this->entityManagerFinding($user), $clock)->rememberedSecondsLeft($request));
    }

    public function testLoggingInAgainWithoutRememberingDropsTheRememberedEnd(): void
    {
        $clock = new MockClock('2026-10-03 12:00:00');
        $user = $this->user();
        $request = $this->loggedInRequest($user, $clock, remember: true);
        $authSession = new AuthSession($this->entityManagerFinding($user), $clock);

        $authSession->logIn($request, $user);

        self::assertNull($authSession->rememberedSecondsLeft($request));
        $clock->sleep(AuthSession::IDLE_TIMEOUT_SECONDS + 1);
        self::assertNull($authSession->getCurrentUser($request));
    }

    public function testASessionOpenedUnderAnotherPasswordIsLoggedOut(): void
    {
        $clock = new MockClock('2026-10-03 12:00:00');
        $user = $this->user();
        $request = $this->loggedInRequest($user, $clock, remember: true);
        $authSession = new AuthSession($this->entityManagerFinding($user), $clock);
        self::assertSame($user, $authSession->getCurrentUser($request));

        $user->changePassword('new-auth-hash', 'e2');

        self::assertNull($authSession->getCurrentUser($request));
        self::assertSame(['unrelated' => 'kept'], $request->getSession()->all());
    }

    public function testTheSessionThatChangedThePasswordStaysLoggedIn(): void
    {
        $clock = new MockClock('2026-10-03 12:00:00');
        $user = $this->user();
        $request = $this->loggedInRequest($user, $clock, remember: false);
        $authSession = new AuthSession($this->entityManagerFinding($user), $clock);

        $user->changePassword('new-auth-hash', 'e2');
        $authSession->refreshCredentialStamp($request, $user);

        self::assertSame($user, $authSession->getCurrentUser($request));
    }

    public function testASessionFromBeforeStampsExistedStaysLoggedInAndGetsOne(): void
    {
        $clock = new MockClock('2026-10-03 12:00:00');
        $user = $this->user();
        $request = $this->loggedInRequest($user, $clock, remember: false);
        $request->getSession()->remove('credential_stamp');
        $authSession = new AuthSession($this->entityManagerFinding($user), $clock);

        self::assertSame($user, $authSession->getCurrentUser($request));
        self::assertSame(hash('sha256', 'h'), $request->getSession()->get('credential_stamp'));

        // From then on it ends with a password change like any other.
        $user->changePassword('new-auth-hash', 'e2');
        self::assertNull($authSession->getCurrentUser($request));
    }

    public function testASessionWithAMalformedCredentialStampIsLoggedOut(): void
    {
        $clock = new MockClock('2026-10-03 12:00:00');
        $user = $this->user();
        $request = $this->loggedInRequest($user, $clock, remember: false);
        $request->getSession()->set('credential_stamp', 123);

        self::assertNull(new AuthSession($this->entityManagerFinding($user), $clock)->getCurrentUser($request));
        self::assertFalse($request->getSession()->has('user_id'));
    }

    private function user(): User
    {
        return new User('user@example.com', 'h', 'p', 'e', new Company('Acme'));
    }

    private function entityManagerFinding(?User $user): EntityManagerInterface
    {
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($user);
        $filters = self::createStub(FilterCollection::class);
        $filters->method('enable')->willReturn(new CompanyFilter($em));
        $em->method('getFilters')->willReturn($filters);

        return $em;
    }

    /** A request whose session logIn() has already been called on, plus one unrelated value. */
    private function loggedInRequest(User $user, MockClock $clock, bool $remember): Request
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('unrelated', 'kept');
        $request = new Request();
        $request->setSession($session);

        new AuthSession($this->entityManagerFinding($user), $clock)->logIn($request, $user, $remember);

        return $request;
    }

    public function testLogOutDisablesFilterIfEnabled(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->expects(self::once())->method('isEnabled')->with(CompanyFilter::NAME)->willReturn(true);
        $filters->expects(self::once())->method('disable')->with(CompanyFilter::NAME);

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('invalidate');

        $request = new Request();
        $request->setSession($session);

        $authSession = new AuthSession($em, new MockClock('2026-10-03 12:00:00'));
        $authSession->logOut($request);
    }

    public function testLogOutDoesNotDisableFilterIfNotEnabled(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->expects(self::once())->method('isEnabled')->with(CompanyFilter::NAME)->willReturn(false);
        $filters->expects(self::never())->method('disable');

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('invalidate');

        $request = new Request();
        $request->setSession($session);

        $authSession = new AuthSession($em, new MockClock('2026-10-03 12:00:00'));
        $authSession->logOut($request);
    }

    public function testALoggedInSessionWithoutAnActivityTimestampIsLoggedOut(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        // Rejected before the user is even looked up.
        $em->expects(self::never())->method('find');

        $session = new Session(new MockArraySessionStorage());
        $session->set('user_id', 'user-1');
        $session->set('unrelated', 'kept');

        $request = new Request();
        $request->setSession($session);

        $authSession = new AuthSession($em, new MockClock('2026-10-03 12:00:00'));

        self::assertNull($authSession->getCurrentUser($request));
        self::assertFalse($session->has('user_id'));
        // Only the login goes: the rest of the session holds the CSRF secret.
        self::assertSame('kept', $session->get('unrelated'));
    }

    public function testAnIdleSessionLosesItsLoginAndItsTimestampButNothingElse(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('find');
        // The tenant filter is left as it is: CompanyFilterListener resets it per request.
        $em->expects(self::never())->method('getFilters');

        $clock = new MockClock('2026-10-03 12:00:00');
        $session = new Session(new MockArraySessionStorage());
        $session->set('user_id', 'user-1');
        $session->set('last_active_at', $clock->now()->getTimestamp() - AuthSession::IDLE_TIMEOUT_SECONDS - 1);
        $session->set('unrelated', 'kept');

        $request = new Request();
        $request->setSession($session);

        self::assertNull(new AuthSession($em, $clock)->getCurrentUser($request));
        self::assertSame(['unrelated' => 'kept'], $session->all());
    }

    public function testAnAnonymousSessionIsLeftAlone(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('find');
        $em->expects(self::never())->method('getFilters');

        $session = new Session(new MockArraySessionStorage());
        $session->set('unrelated', 'kept');

        $request = new Request();
        $request->setSession($session);

        $authSession = new AuthSession($em, new MockClock('2026-10-03 12:00:00'));

        self::assertNull($authSession->getCurrentUser($request));
        self::assertSame('kept', $session->get('unrelated'));
        self::assertFalse($session->has('last_active_at'));
    }
}
