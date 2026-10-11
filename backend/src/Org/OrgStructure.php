<?php

namespace App\Org;

use App\Entity\User;

/**
 * The rules of the company org structure (GitHub issue #265): who may be whose manager.
 * The one place they live, for a single assignment from the admin panel and for a batch
 * alike, so the two can't drift apart.
 *
 * No locking: two admins changing the tree at the same moment can still produce a cycle
 * between them, and an assignment racing the manager's account deletion can leave a link
 * to or from the deleted account (an admin clears it by hand). Both races are accepted (SQLite
 * has no SELECT … FOR UPDATE, and this is a rare, admin-only action), which is why every
 * walk up the tree below stops at a person it has already seen rather than trusting the
 * stored tree to be free of cycles.
 */
final class OrgStructure
{
    /** @throws OrgStructureException */
    public function assign(User $user, ?User $manager): void
    {
        $violation = $this->violations([[$user, $manager]])[$user->getId()] ?? null;
        if (null !== $violation) {
            throw new OrgStructureException($violation);
        }

        $user->setManager($manager);
    }

    /**
     * Writes a batch that violations() has nothing against, all of it or none: the
     * import's way in, so that this class stays the only writer of reporting lines
     * besides AccountDeleter.
     *
     * @param list<array{User, ?User}> $assignments
     *
     * @throws OrgStructureException for the first assignment that can't be applied; nothing is changed then
     */
    public function assignAll(array $assignments): void
    {
        foreach ($this->violations($assignments) as $violation) {
            throw new OrgStructureException($violation);
        }
        foreach ($assignments as [$user, $manager]) {
            $user->setManager($manager);
        }
    }

    /**
     * Checks a batch of assignments against the tree as it would be with all the valid
     * ones applied, so a swap (A under B, B under A's old manager) in one batch is not a
     * false cycle, and a cycle made only by two rows together is still found. A row
     * rejected for its own sake (a blocked manager, say) counts as not applied: the
     * person keeps their stored manager for everyone else's cycle check. Changes
     * nothing. A person listed twice keeps the last row.
     *
     * A Cycle in the answer means the batch as a whole must not be applied: the rows of a
     * cycle can't be told apart from the rows that merely lean on them, so applying "the
     * rest" could still store one. Rows rejected for any other reason can simply be skipped.
     *
     * A row that repeats the stored manager is always fine, whatever has happened to
     * that manager since: blocking a manager leaves their reports in place.
     *
     * @param list<array{User, ?User}> $assignments each a person and their new manager, null for none
     *
     * @return array<string, OrgStructureError> the rejected ones, by the person's id; empty when all are fine
     */
    public function violations(array $assignments): array
    {
        /** @var array<string, User> $people */
        $people = [];
        /** @var array<string, ?User> $newManagers */
        $newManagers = [];
        foreach ($assignments as [$user, $manager]) {
            $people[$user->getId()] = $user;
            $newManagers[$user->getId()] = $manager;
        }

        $violations = [];
        foreach ($newManagers as $userId => $manager) {
            $violation = $this->ownViolation($people[$userId], $manager);
            if (null !== $violation) {
                $violations[$userId] = $violation;
                unset($newManagers[$userId]);
            }
        }
        foreach ($newManagers as $userId => $manager) {
            if (null !== $manager && !$this->isStoredManager($people[$userId], $manager) && $this->closesCycle($people[$userId], $manager, $newManagers)) {
                $violations[$userId] = OrgStructureError::Cycle;
            }
        }

        return $violations;
    }

    /** What is wrong with this one row, whatever the rest of the tree looks like. */
    private function ownViolation(User $user, ?User $manager): ?OrgStructureError
    {
        // Clearing is always allowed, for a deleted account too: it is how an admin removes
        // a link that the race with account deletion (see the class docblock) left behind.
        if (null === $manager || $this->isStoredManager($user, $manager)) {
            return null;
        }
        if (null !== $user->getDeletedAt()) {
            return OrgStructureError::PersonDeleted;
        }
        if ($manager->getId() === $user->getId()) {
            return OrgStructureError::OwnManager;
        }
        // delete() also blocks the account; the deletedAt check doesn't lean on that.
        if ($manager->getCompany() !== $user->getCompany() || $manager->isBlocked() || null !== $manager->getDeletedAt()) {
            return OrgStructureError::ManagerUnavailable;
        }

        return null;
    }

    private function isStoredManager(User $user, User $manager): bool
    {
        return $user->getManager()?->getId() === $manager->getId();
    }

    /**
     * Each person has at most one manager, so the only way to close a cycle is for the
     * walk up from the new manager to come back to this person.
     *
     * @param array<string, ?User> $newManagers
     */
    private function closesCycle(User $user, User $manager, array $newManagers): bool
    {
        $seen = [];
        for ($current = $manager; null !== $current; $current = $this->managerOf($current, $newManagers)) {
            if ($current->getId() === $user->getId()) {
                return true;
            }
            if (isset($seen[$current->getId()])) {
                break;
            }
            $seen[$current->getId()] = true;
        }

        return false;
    }

    /** @param array<string, ?User> $newManagers */
    private function managerOf(User $user, array $newManagers): ?User
    {
        return \array_key_exists($user->getId(), $newManagers) ? $newManagers[$user->getId()] : $user->getManager();
    }
}
