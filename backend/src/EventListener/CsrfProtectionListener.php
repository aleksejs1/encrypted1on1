<?php

namespace App\EventListener;

use App\Security\CsrfGuard;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces CSRF on every state-changing /api/* request, centralized here
 * (GitHub issue #71 fallout) instead of a `$this->csrfGuard->assertValid($request)`
 * call duplicated as the first line of ~30 controller methods. That per-method
 * placement stopped being "first" once #[MapRequestPayload] arrived: Symfony
 * resolves controller arguments (including MapRequestPayload's validation) during
 * kernel.controller_arguments, which runs *after* kernel.controller — so an inline
 * call in the method body ran after body validation, letting a request with an
 * invalid/malformed payload skip the CSRF check (and whatever rate limiter the
 * method consumes next) entirely. Running this on kernel.controller instead
 * guarantees CSRF is checked before argument resolution, for every current and
 * future #[MapRequestPayload] endpoint alike.
 *
 * The one deliberate exception is BillingController::webhook() (Stripe signature
 * verification instead — no browser session or CSRF token involved), matched by
 * route name rather than requiring every other route to opt in.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
class CsrfProtectionListener
{
    private const array EXEMPT_ROUTES = ['billing_webhook'];

    public function __construct(
        private readonly CsrfGuard $csrfGuard,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        if ($request->isMethodSafe()) {
            return;
        }

        if (\in_array($request->attributes->get('_route'), self::EXEMPT_ROUTES, true)) {
            return;
        }

        $this->csrfGuard->assertValid($request);
    }
}
