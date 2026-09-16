<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\CsrfProtectionListener;
use App\Security\CsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class CsrfProtectionListenerTest extends TestCase
{
    private function makeEvent(Request $request, int $requestType = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        $kernel = self::createStub(HttpKernelInterface::class);

        return new ControllerEvent($kernel, static fn (): string => 'noop', $request, $requestType);
    }

    public function testIgnoresSubRequests(): void
    {
        $csrfGuard = $this->createMock(CsrfGuard::class);
        $csrfGuard->expects(self::never())->method('assertValid');

        $request = Request::create('/api/anketas', 'POST');
        $listener = new CsrfProtectionListener($csrfGuard);
        $listener($this->makeEvent($request, HttpKernelInterface::SUB_REQUEST));
    }

    public function testIgnoresNonApiRoutes(): void
    {
        $csrfGuard = $this->createMock(CsrfGuard::class);
        $csrfGuard->expects(self::never())->method('assertValid');

        $request = Request::create('/health', 'POST');
        $listener = new CsrfProtectionListener($csrfGuard);
        $listener($this->makeEvent($request));
    }

    public function testIgnoresSafeMethods(): void
    {
        $csrfGuard = $this->createMock(CsrfGuard::class);
        $csrfGuard->expects(self::never())->method('assertValid');

        $request = Request::create('/api/anketas', 'GET');
        $listener = new CsrfProtectionListener($csrfGuard);
        $listener($this->makeEvent($request));
    }

    public function testIgnoresTheExemptBillingWebhookRoute(): void
    {
        $csrfGuard = $this->createMock(CsrfGuard::class);
        $csrfGuard->expects(self::never())->method('assertValid');

        $request = Request::create('/api/billing/webhook', 'POST');
        $request->attributes->set('_route', 'billing_webhook');
        $listener = new CsrfProtectionListener($csrfGuard);
        $listener($this->makeEvent($request));
    }

    public function testChecksCsrfForAnOrdinaryMutatingApiRoute(): void
    {
        $request = Request::create('/api/anketas', 'POST');
        $request->attributes->set('_route', 'anketa_create');

        $csrfGuard = $this->createMock(CsrfGuard::class);
        $csrfGuard->expects(self::once())->method('assertValid')->with($request);

        $listener = new CsrfProtectionListener($csrfGuard);
        $listener($this->makeEvent($request));
    }
}
