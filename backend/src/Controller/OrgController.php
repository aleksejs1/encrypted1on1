<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\AuthSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a person may see of the company org structure (GitHub issue #268, part of #265):
 * their own manager and their own direct reports, nothing about anyone else. The whole
 * tree is for company admins only (AdminController::listUsers()), and GET /api/users
 * never carries a manager at all.
 */
class OrgController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Not part of GET /api/me: that one is polled often, and this needs a query for the
     * reports. Only the create form asks for it.
     *
     * Blocked and deleted people are left out on both sides (a blocked manager reads as
     * no manager): the form warns about a role that contradicts a reporting line, and a
     * 1:1 with a blocked person can't be created at all (AnketaController::create()), so
     * there is nothing to warn about. The link itself stays stored, see User::$manager.
     *
     * The reports come in email order, only so that the answer is stable; a caller that
     * shows them sorts by what it shows.
     */
    #[Route('/api/me/org', name: 'me_org', methods: ['GET'])]
    public function myOrg(Request $request): JsonResponse
    {
        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            return new JsonResponse(['error' => $this->translator->trans('errors.not_authenticated')], 401);
        }

        // Read-only from here on — see AuthSession::closeForReading()'s docblock.
        $this->authSession->closeForReading($request);

        // One rule for both sides, in the query. The manager is looked up, not read
        // off $user->getManager(): that is a proxy, and one whose row is hidden (another
        // company's, by CompanyFilter) or gone would throw on first use.
        $active = ['isBlocked' => false, 'deletedAt' => null];
        $users = $this->entityManager->getRepository(User::class);
        $managerId = $user->getManager()?->getId();
        $manager = null === $managerId ? null : $users->findOneBy(['id' => $managerId] + $active);
        $reports = $users->findBy(['manager' => $user] + $active, ['email' => 'ASC']);

        return new JsonResponse([
            'manager' => null !== $manager ? self::person($manager) : null,
            'directReports' => array_map(self::person(...), $reports),
        ]);
    }

    /** @return array{id: string, displayName: string, email: string} */
    private static function person(User $user): array
    {
        return ['id' => $user->getId(), 'displayName' => $user->getDisplayName(), 'email' => $user->getEmail()];
    }
}
