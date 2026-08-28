<?php

namespace App\Controller;

use App\Account\AccountDeleter;
use App\Entity\Company;
use App\Entity\User;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use App\Security\RequiresCompanyAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every route here is admin-only (see requireAdmin(), from RequiresCompanyAdmin) —
 * the account-lifecycle management the spec says CLI-only tooling doesn't scale for
 * HR/rotations (Phase 6g plan). `admin` stays a flag on the account, orthogonal to
 * the employee/manager roles inside any given anketa.
 *
 * Scoped to the requesting admin's own company throughout (private/cloud-service-plan.md,
 * not tracked in git, Phase A) — `isAdmin` is a *company* admin now, not a platform-wide
 * one; nothing here lets an admin see or manage another company's users. A genuine
 * platform-level superadmin role, for the SaaS operator's own cross-company support
 * needs, is deliberately out of scope for this phase (see the plan's Phase C).
 */
class AdminController
{
    use RequiresCompanyAdmin;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
        private readonly TranslatorInterface $translator,
        private readonly bool $cloudMode,
    ) {
    }

    #[Route('/api/admin/users', name: 'admin_users_list', methods: ['GET'])]
    public function listUsers(Request $request): JsonResponse
    {
        $admin = $this->requireAdmin($request);

        $users = $this->entityManager->getRepository(User::class)->findBy(['company' => $admin->getCompany()]);

        return new JsonResponse(array_map(fn (User $user) => [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'isAdmin' => $user->isAdmin(),
            'isBlocked' => $user->isBlocked(),
            'createdAt' => $user->getCreatedAt()->format(\DATE_ATOM),
            // Deletion is irreversible (unlike isBlocked), so this is read-only audit
            // visibility, not a toggle — deleted rows still show up here on purpose,
            // unlike GET /api/users (see ExcludeDeletedUsersExtension).
            'deletedAt' => $user->getDeletedAt()?->format(\DATE_ATOM),
        ], $users));
    }

    #[Route('/api/admin/users/{id}/blocked', name: 'admin_user_set_blocked', methods: ['PUT'])]
    public function setBlocked(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $admin = $this->requireAdmin($request);

        $target = $this->findUser($id, $admin);
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
        $admin = $this->requireAdmin($request);

        $target = $this->findUser($id, $admin);

        $isAdmin = $request->toArray()['isAdmin'] ?? null;
        if (!\is_bool($isAdmin)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_is_admin')], 400);
        }

        $target->setAdmin($isAdmin);
        $this->entityManager->flush();

        return new JsonResponse(['id' => $target->getId(), 'isAdmin' => $target->isAdmin()]);
    }

    /**
     * Frees the seat a blocked-but-not-deleted departed employee still occupies
     * (SeatLimitChecker deliberately keeps counting a merely-blocked user — see its own
     * docblock — since blocking is reversible and shouldn't itself free capacity). Reuses
     * User::delete(), the same anonymization-in-place self-service account deletion
     * already uses, so a company admin gets no destructive capability beyond what a user
     * already has over their own account — including clearing that user's own
     * never-shared draft blobs first, exactly like AuthController::deleteAccount() does,
     * since those are unreadable by anyone else and "delete this account" should mean the
     * same thing regardless of who triggers it. Requires the target already be blocked
     * first — a deliberate two-step (block, confirm, delete) rather than a single
     * irreversible action reachable from an active account's row.
     */
    #[Route('/api/admin/users/{id}', name: 'admin_user_delete', methods: ['DELETE'])]
    public function deleteUser(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $admin = $this->requireAdmin($request);

        $target = $this->findUser($id, $admin);
        if ($target->getId() === $admin->getId()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.cannot_delete_own_account')], 400);
        }
        if (!$target->isBlocked()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.user_must_be_blocked_before_deletion')], 400);
        }

        AccountDeleter::delete($target, $this->entityManager);
        $this->entityManager->flush();

        // email/displayName are returned post-anonymization (not just deletedAt) so the
        // admin panel can show the real result in place, instead of the stale
        // pre-deletion values, without duplicating User::delete()'s own anonymization
        // format on the frontend.
        return new JsonResponse([
            'id' => $target->getId(),
            'email' => $target->getEmail(),
            'displayName' => $target->getDisplayName(),
            'deletedAt' => $target->getDeletedAt()?->format(\DATE_ATOM),
        ]);
    }

    /**
     * Lets a company admin configure who can invite and the email-domain restriction
     * (private/cloud-service-plan.md, not tracked in git) — previously only settable via
     * raw SQL (see Company's own former docblock). `domain` (open self-registration) is
     * deliberately rejected under CLOUD_MODE, not just hidden in the admin panel's own
     * UI: SignupController::signup()/info() already refuse self-registration globally
     * once CLOUD_MODE is on, regardless of what any one company's registrationMode says
     * — resolving "which company" from an anonymous visitor's email domain across many
     * companies is real, undesigned future work (see SignupController's own docblock),
     * so setting a cloud company to `domain` would silently do nothing but confuse
     * whoever set it. Self-hosted deployments (CLOUD_MODE off) keep full access to all
     * three modes, `domain` included — that path genuinely works there. This endpoint
     * only ever changes these two fields, never anything billing/seat-related.
     */
    #[Route('/api/admin/company-settings', name: 'admin_company_settings_update', methods: ['PUT'])]
    public function updateCompanySettings(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $admin = $this->requireAdmin($request);

        $body = $request->toArray();

        $registrationMode = $body['registrationMode'] ?? null;
        if (!\is_string($registrationMode) || !\in_array($registrationMode, Company::REGISTRATION_MODES, true)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'registrationMode'])], 400);
        }
        if ('domain' === $registrationMode && $this->cloudMode) {
            return new JsonResponse(['error' => $this->translator->trans('errors.domain_mode_unavailable_in_cloud')], 400);
        }

        $allowedEmailDomain = $body['allowedEmailDomain'] ?? null;
        if (!\is_string($allowedEmailDomain)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'allowedEmailDomain'])], 400);
        }

        $company = $admin->getCompany();
        $company->updateSettings($registrationMode, trim($allowedEmailDomain));
        $this->entityManager->flush();

        return new JsonResponse([
            'registrationMode' => $company->getRegistrationMode(),
            'allowedEmailDomain' => $company->getAllowedEmailDomain(),
        ]);
    }

    /**
     * $admin is the requesting admin, not the target — a target outside $admin's own
     * company is treated identically to a nonexistent one (same NotFoundHttpException,
     * same message), so this never reveals whether a given id belongs to another
     * company's user versus not existing at all.
     *
     * Also rejects an already-deleted target for every one of this controller's mutating
     * endpoints (setBlocked/setAdmin/deleteUser, the only callers) in one place — a
     * deleted account's isBlocked/isAdmin are forced by User::delete() specifically for
     * defense-in-depth, and letting any of these endpoints flip them back would undo that.
     */
    private function findUser(string $id, User $admin): User
    {
        $user = $this->entityManager->find(User::class, $id);
        if (null === $user || $user->getCompany() !== $admin->getCompany()) {
            throw new NotFoundHttpException($this->translator->trans('errors.user_not_found'));
        }
        if (null !== $user->getDeletedAt()) {
            throw new BadRequestHttpException($this->translator->trans('errors.user_already_deleted'));
        }

        return $user;
    }
}
