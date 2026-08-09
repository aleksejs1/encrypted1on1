<?php

namespace App\Controller;

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
 * Every route here is admin-only (see requireAdmin()) — the account-lifecycle
 * management the spec says CLI-only tooling doesn't scale for HR/rotations
 * (Phase 6g plan). `admin` stays a flag on the account, orthogonal to the
 * employee/manager roles inside any given anketa — no org structure here.
 */
class AdminController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/admin/users', name: 'admin_users_list', methods: ['GET'])]
    public function listUsers(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $users = $this->entityManager->getRepository(User::class)->findAll();

        return new JsonResponse(array_map(fn (User $user) => [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'isAdmin' => $user->isAdmin(),
            'isBlocked' => $user->isBlocked(),
            'createdAt' => $user->getCreatedAt()->format(\DATE_ATOM),
        ], $users));
    }

    #[Route('/api/admin/users/{id}/blocked', name: 'admin_user_set_blocked', methods: ['PUT'])]
    public function setBlocked(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $admin = $this->requireAdmin($request);

        $target = $this->findUser($id);
        if ($target->getId() === $admin->getId()) {
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

    #[Route('/api/admin/users/{id}/admin', name: 'admin_user_set_admin', methods: ['PUT'])]
    public function setAdmin(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $this->requireAdmin($request);

        $target = $this->findUser($id);

        $isAdmin = $request->toArray()['isAdmin'] ?? null;
        if (!\is_bool($isAdmin)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_is_admin')], 400);
        }

        $target->setAdmin($isAdmin);
        $this->entityManager->flush();

        return new JsonResponse(['id' => $target->getId(), 'isAdmin' => $target->isAdmin()]);
    }

    private function requireAdmin(Request $request): User
    {
        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            throw new UnauthorizedHttpException('', $this->translator->trans('errors.not_authenticated'));
        }
        if (!$user->isAdmin()) {
            throw new AccessDeniedHttpException($this->translator->trans('errors.admin_only'));
        }

        return $user;
    }

    private function findUser(string $id): User
    {
        $user = $this->entityManager->find(User::class, $id);
        if (null === $user) {
            throw new NotFoundHttpException($this->translator->trans('errors.user_not_found'));
        }

        return $user;
    }
}
