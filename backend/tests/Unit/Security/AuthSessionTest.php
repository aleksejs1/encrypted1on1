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
        $session->expects(self::exactly(2))->method('set')->willReturnCallback(
            static function (string $name, mixed $value) use (&$stored): void {
                $stored[$name] = $value;
            },
        );
        $session->expects(self::once())->method('migrate');

        $request = new Request();
        $request->setSession($session);

        $authSession = new AuthSession($em, new MockClock('2026-10-03 12:00:00'));
        $authSession->logIn($request, $user);

        self::assertTrue($filter->hasParameter(CompanyFilter::PARAMETER_NAME));
        self::assertSame(
            ['user_id' => $user->getId(), 'last_active_at' => (new \DateTimeImmutable('2026-10-03 12:00:00'))->getTimestamp()],
            $stored,
        );
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
