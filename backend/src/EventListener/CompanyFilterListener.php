<?php

namespace App\EventListener;

use App\Doctrine\CompanyFilter;
use App\Security\AuthSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Automatically scopes all tenant-scoped queries to the authenticated user's company
 * via Doctrine's SQLFilter (GitHub issue #69).
 *
 * Runs on KernelEvents::REQUEST after session initialization and routing. Resets any
 * leftover filter state from a prior request first to avoid cross-request state leakage
 * in long-running worker environments (e.g. FrankenPHP worker mode). Also implements
 * ResetInterface for kernel.reset lifecycle integration.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
class CompanyFilterListener implements ResetInterface
{
    /**
     * Set by PlatformAdminController::requirePlatformAdmin() on the request attributes
     * (not restored inline at each of its call sites) so restoration is guaranteed on
     * every exit path — a normal return, an early guard-clause return, or an uncaught
     * exception turned into a response by JsonExceptionListener — rather than depending
     * on every current and future platform-admin action remembering to call a restore
     * method before each of its own returns.
     */
    public const string RESTORE_FOR_COMPANY_ID_ATTRIBUTE = 'app.restore_company_filter_for_company_id';

    public function __construct(
        private readonly AuthSession $authSession,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function reset(): void
    {
        if ($this->entityManager->getFilters()->isEnabled(CompanyFilter::NAME)) {
            $this->entityManager->getFilters()->disable(CompanyFilter::NAME);
        }
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Clean up filter state from any prior request in a persistent worker process
        $this->reset();

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $user = $this->authSession->getCurrentUser($request);
        if (null !== $user) {
            $this->enableFor($request, $user->getCompany()->getId());
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $companyId = $event->getRequest()->attributes->get(self::RESTORE_FOR_COMPANY_ID_ATTRIBUTE);
        if (null !== $companyId) {
            \assert(\is_string($companyId));
            $this->enableFor($event->getRequest(), $companyId);
        }
    }

    private function enableFor(Request $request, string $companyId): void
    {
        $filter = $this->entityManager->getFilters()->enable(CompanyFilter::NAME);
        $filter->setParameter(CompanyFilter::PARAMETER_NAME, $companyId);
        $request->attributes->remove(self::RESTORE_FOR_COMPANY_ID_ATTRIBUTE);
    }
}
