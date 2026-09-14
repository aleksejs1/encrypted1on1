<?php

namespace App\Tests\Unit\EventListener;

use App\Doctrine\CompanyFilter;
use App\Entity\Company;
use App\Entity\User;
use App\EventListener\CompanyFilterListener;
use App\Security\AuthSession;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class CompanyFilterListenerTest extends TestCase
{
    public function testIgnoresSubRequests(): void
    {
        $authSession = $this->createMock(AuthSession::class);
        $authSession->expects(self::never())->method('getCurrentUser');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getFilters');

        $listener = new CompanyFilterListener($authSession, $em);

        $kernel = self::createStub(HttpKernelInterface::class);
        $request = new Request();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $listener($event);
    }

    public function testDisablesFilterAndReturnsIfNoSession(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->expects(self::once())->method('isEnabled')->with(CompanyFilter::NAME)->willReturn(true);
        $filters->expects(self::once())->method('disable')->with(CompanyFilter::NAME);

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $authSession = $this->createMock(AuthSession::class);
        $authSession->expects(self::never())->method('getCurrentUser');

        $listener = new CompanyFilterListener($authSession, $em);

        $kernel = self::createStub(HttpKernelInterface::class);
        $request = new Request(); // no session set
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);
    }

    public function testLeavesFilterDisabledIfUnauthenticated(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->expects(self::once())->method('isEnabled')->with(CompanyFilter::NAME)->willReturn(false);
        $filters->expects(self::never())->method('enable');

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $session = self::createStub(SessionInterface::class);
        $request = new Request();
        $request->setSession($session);

        $authSession = $this->createMock(AuthSession::class);
        $authSession->expects(self::once())->method('getCurrentUser')->with($request)->willReturn(null);

        $listener = new CompanyFilterListener($authSession, $em);

        $kernel = self::createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);
    }

    public function testEnablesAndConfiguresFilterForAuthenticatedUser(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $filter = new CompanyFilter($em);

        $filters = $this->createMock(FilterCollection::class);
        $filters->expects(self::once())->method('isEnabled')->with(CompanyFilter::NAME)->willReturn(false);
        $filters->expects(self::once())
            ->method('enable')
            ->with(CompanyFilter::NAME)
            ->willReturn($filter);

        $em->method('getFilters')->willReturn($filters);

        $company = new Company('Acme');
        $refl = new \ReflectionProperty(Company::class, 'id');
        $refl->setValue($company, 'comp-123');

        $user = new User('user@example.com', 'h', 'p', 'e', $company);

        $session = self::createStub(SessionInterface::class);
        $request = new Request();
        $request->setSession($session);

        $authSession = $this->createMock(AuthSession::class);
        $authSession->expects(self::once())->method('getCurrentUser')->with($request)->willReturn($user);

        $listener = new CompanyFilterListener($authSession, $em);

        $kernel = self::createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);

        self::assertTrue($filter->hasParameter(CompanyFilter::PARAMETER_NAME));
    }
}
