<?php

namespace App\EventListener;

use App\Doctrine\CompanyFilter;
use App\Security\AuthSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
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
class CompanyFilterListener implements ResetInterface
{
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
            $filter = $this->entityManager->getFilters()->enable(CompanyFilter::NAME);
            $filter->setParameter(CompanyFilter::PARAMETER_NAME, $user->getCompany()->getId());
        }
    }
}
