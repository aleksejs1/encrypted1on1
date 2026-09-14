<?php

namespace App\Tests\Unit\Security;

use App\Doctrine\CompanyFilter;
use App\Entity\Company;
use App\Entity\User;
use App\Security\AuthSession;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

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
        $session->expects(self::once())->method('set')->with('user_id', $user->getId());
        $session->expects(self::once())->method('migrate');

        $request = new Request();
        $request->setSession($session);

        $authSession = new AuthSession($em);
        $authSession->logIn($request, $user);

        self::assertTrue($filter->hasParameter(CompanyFilter::PARAMETER_NAME));
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

        $authSession = new AuthSession($em);
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

        $authSession = new AuthSession($em);
        $authSession->logOut($request);
    }
}
