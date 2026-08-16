<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Phase C of private/cloud-service-plan.md (not tracked in git): the SaaS operator's
 * own cross-company support/moderation tooling. Every route here is gated on
 * `User::isPlatformAdmin()`, not `isAdmin()` — completely orthogonal to AdminController,
 * which is deliberately company-scoped (Phase A) and can never see or manage another
 * company's users. This controller is the one deliberate, narrow exception to that
 * isolation boundary, restricted to a role nothing self-service ever grants (see
 * User::$isPlatformAdmin's own docblock and app:grant-platform-admin).
 *
 * Not linked from anywhere a company admin (or anyone without the flag) can reach —
 * AppHeader.svelte never shows a link to it, unlike the regular /admin link. Reachable
 * by URL for anyone authenticated, same as AdminPanel.svelte's own "shows 'Not
 * authorized' rather than a blank page" precedent, not hidden behind an extra secret.
 */
class PlatformAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/platform-admin/companies', name: 'platform_admin_companies_list', methods: ['GET'])]
    public function listCompanies(Request $request): JsonResponse
    {
        $this->requirePlatformAdmin($request);

        /** @var Company[] $companies */
        $companies = $this->entityManager->getRepository(Company::class)->findAll();

        // One query for every company's user count, grouped in PHP — avoids an N+1
        // COUNT(*) per company, same reasoning AnketaController::bulk() already
        // documents for its own goals-batching query.
        /** @var array<array{cid: string, c: int}> $counts */
        $counts = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(u.company) AS cid, COUNT(u.id) AS c')
            ->from(User::class, 'u')
            ->groupBy('u.company')
            ->getQuery()
            ->getResult();
        $userCountByCompanyId = array_column($counts, 'c', 'cid');

        return new JsonResponse(array_map(fn (Company $company) => [
            'id' => $company->getId(),
            'name' => $company->getName(),
            'registrationMode' => $company->getRegistrationMode(),
            'allowedEmailDomain' => $company->getAllowedEmailDomain(),
            'createdAt' => $company->getCreatedAt()->format(\DATE_ATOM),
            'userCount' => (int) ($userCountByCompanyId[$company->getId()] ?? 0),
        ], $companies));
    }

    /**
     * Every user on the instance, across every company — the one deliberate exception
     * to the company-scoping every other listing in this app now has. Includes which
     * company each row belongs to, unlike AdminController::listUsers() (which doesn't
     * need to say what's already implied by "your own company").
     */
    #[Route('/api/platform-admin/users', name: 'platform_admin_users_list', methods: ['GET'])]
    public function listUsers(Request $request): JsonResponse
    {
        $this->requirePlatformAdmin($request);

        $users = $this->entityManager->getRepository(User::class)->findAll();

        return new JsonResponse(array_map(fn (User $user) => [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'companyId' => $user->getCompany()->getId(),
            'companyName' => $user->getCompany()->getName(),
            'isAdmin' => $user->isAdmin(),
            'isPlatformAdmin' => $user->isPlatformAdmin(),
            'isBlocked' => $user->isBlocked(),
            'createdAt' => $user->getCreatedAt()->format(\DATE_ATOM),
            'deletedAt' => $user->getDeletedAt()?->format(\DATE_ATOM),
        ], $users));
    }

    #[Route('/api/platform-admin/users/{id}/blocked', name: 'platform_admin_user_set_blocked', methods: ['PUT'])]
    public function setBlocked(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $platformAdmin = $this->requirePlatformAdmin($request);

        $target = $this->findUser($id);
        if ($target->getId() === $platformAdmin->getId()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.cannot_block_own_account')], 400);
        }

        $blocked = $request->toArray()['blocked'] ?? null;
        if (!\is_bool($blocked)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_blocked')], 400);
        }

        $target->setBlocked($blocked);
        $this->entityManager->flush();

        return new JsonResponse(['id' => $target->getId(), 'isBlocked' => $target->isBlocked()]);
    }

    /**
     * Grant or revoke platform-admin status on any user, from any company — the
     * "existing platform admin" half of the two ways to get this flag (the other is
     * app:grant-platform-admin). No self-demotion guard, matching AdminController's own
     * setAdmin() precedent: always recoverable via the CLI command either way.
     */
    #[Route('/api/platform-admin/users/{id}/platform-admin', name: 'platform_admin_user_set_platform_admin', methods: ['PUT'])]
    public function setPlatformAdmin(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $this->requirePlatformAdmin($request);

        $target = $this->findUser($id);

        $isPlatformAdmin = $request->toArray()['isPlatformAdmin'] ?? null;
        if (!\is_bool($isPlatformAdmin)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_is_platform_admin')], 400);
        }

        $target->setPlatformAdmin($isPlatformAdmin);
        $this->entityManager->flush();

        return new JsonResponse(['id' => $target->getId(), 'isPlatformAdmin' => $target->isPlatformAdmin()]);
    }

    private function requirePlatformAdmin(Request $request): User
    {
        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            throw new UnauthorizedHttpException('', $this->translator->trans('errors.not_authenticated'));
        }
        if (!$user->isPlatformAdmin()) {
            throw new AccessDeniedHttpException($this->translator->trans('errors.platform_admin_only'));
        }

        return $user;
    }

    /** Unscoped by design — see this class's own docblock for why that's the one deliberate exception here. */
    private function findUser(string $id): User
    {
        $user = $this->entityManager->find(User::class, $id);
        if (null === $user) {
            throw new NotFoundHttpException($this->translator->trans('errors.user_not_found'));
        }

        return $user;
    }
}
