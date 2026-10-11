<?php

namespace App\Org;

use App\Entity\User;

/**
 * Turns the rows of an org structure import (GitHub issue #271) into a plan: which
 * reporting lines change, and which rows can't be applied and why. Changes nothing
 * itself, so a dry run and a real import share every line of it.
 *
 * A partial update: people the rows don't name keep their manager. The rules for an
 * assignment are OrgStructure's, the same ones the admin panel's single assignment
 * goes through; this class only resolves emails to people and settles rows that name
 * the same person twice.
 */
final class OrgStructureImport
{
    public const MAX_ROWS = 1000;

    public function __construct(private readonly OrgStructure $orgStructure)
    {
    }

    /**
     * @param list<User>                                                $companyUsers every user of the one company the import is for
     * @param list<array{employeeEmail: string, managerEmail: ?string}> $rows         a null or empty managerEmail clears the manager
     */
    public function plan(array $companyUsers, array $rows): OrgImportPlan
    {
        $byEmail = $this->byEmail($companyUsers);
        /** @var array<int, OrgImportProblem> $problems by row index */
        $problems = [];
        $assignments = $this->assignments($byEmail, $this->rowsByPerson($byEmail, $rows, $problems), $problems);

        $violations = $this->orgStructure->violations(array_map(
            static fn (array $a): array => [$a['user'], $a['manager']],
            array_values($assignments),
        ));

        $changes = [];
        $unchanged = 0;
        foreach ($assignments as $personId => $assignment) {
            $violation = $violations[$personId] ?? null;
            if (null !== $violation) {
                $problems += array_fill_keys($assignment['rows'], self::problemFor($violation));
            } elseif ($assignment['user']->getManager()?->getId() === $assignment['manager']?->getId()) {
                ++$unchanged;
            } else {
                $changes[] = [$assignment['user'], $assignment['manager']];
            }
        }

        ksort($problems);

        return new OrgImportPlan(
            \count($rows),
            $changes,
            $unchanged,
            array_map(
                static fn (int $row, OrgImportProblem $problem): array => ['row' => $row, 'problem' => $problem],
                array_keys($problems),
                $problems,
            ),
        );
    }

    /**
     * The rows about each person the company has, with the manager address each row
     * gives, lowercased ('' for none). Rows about nobody are recorded in $problems.
     *
     * @param array<string, list<User>>                                 $byEmail
     * @param list<array{employeeEmail: string, managerEmail: ?string}> $rows
     * @param array<int, OrgImportProblem>                              $problems
     *
     * @return array<string, array{user: User, managerEmails: non-empty-array<int, string>}> by the person's id; managerEmails by row index
     */
    private function rowsByPerson(array $byEmail, array $rows, array &$problems): array
    {
        $byPerson = [];
        foreach ($rows as $index => $row) {
            $employee = $this->find($byEmail, $row['employeeEmail']);
            if (!$employee instanceof User) {
                $problems[$index] = $employee ?? OrgImportProblem::EmployeeNotFound;
                continue;
            }
            $byPerson[$employee->getId()]['user'] = $employee;
            $byPerson[$employee->getId()]['managerEmails'][$index] = self::normalize($row['managerEmail'] ?? '');
        }

        return $byPerson;
    }

    /**
     * One assignment per person. The same row twice is one assignment, and whatever
     * is wrong with it is said of each of its rows. Rows that give one person
     * different managers are all dropped, since none can be preferred, and that is
     * settled by the addresses as written, before any is looked up: a row naming an
     * unknown manager disagrees with a row naming a known one all the same.
     *
     * @param array<string, list<User>>                                                     $byEmail
     * @param array<string, array{user: User, managerEmails: non-empty-array<int, string>}> $byPerson
     * @param array<int, OrgImportProblem>                                                  $problems
     *
     * @return array<string, array{rows: list<int>, user: User, manager: ?User}> by the person's id
     */
    private function assignments(array $byEmail, array $byPerson, array &$problems): array
    {
        $assignments = [];
        foreach ($byPerson as $personId => ['user' => $user, 'managerEmails' => $managerEmails]) {
            $rows = array_keys($managerEmails);
            $distinct = array_values(array_unique($managerEmails));
            $manager = null;
            $problem = null;
            if (\count($distinct) > 1) {
                $problem = OrgImportProblem::ConflictingRows;
            } elseif ('' !== $distinct[0]) {
                $manager = $this->find($byEmail, $distinct[0]);
                if (!$manager instanceof User) {
                    $problem = $manager ?? OrgImportProblem::ManagerNotFound;
                    $manager = null;
                }
            }
            if (null !== $problem) {
                $problems += array_fill_keys($rows, $problem);
                continue;
            }
            $assignments[$personId] = ['rows' => $rows, 'user' => $user, 'manager' => $manager];
        }

        return $assignments;
    }

    /**
     * Stored emails keep the case they were typed in, so both sides are lowercased
     * here; a client lowercasing its own side would not be enough. Trimmed of what a
     * spreadsheet export leaves around a cell too: a no-break space, a zero-width
     * space, a byte-order mark.
     */
    private static function normalize(string $email): string
    {
        $edge = '[\s\x{00A0}\x{200B}\x{FEFF}]+';

        return mb_strtolower(preg_replace("/^{$edge}|{$edge}$/u", '', $email) ?? trim($email));
    }

    /**
     * Deleted accounts are left out: their address is a placeholder nobody would
     * write, and they can't be given a manager.
     *
     * @param list<User> $users
     *
     * @return array<string, list<User>>
     */
    private function byEmail(array $users): array
    {
        $byEmail = [];
        foreach ($users as $user) {
            if (null === $user->getDeletedAt()) {
                $byEmail[self::normalize($user->getEmail())][] = $user;
            }
        }

        return $byEmail;
    }

    /**
     * The one person with this address, null for nobody, or AmbiguousEmail when two
     * accounts differ only by case (SQLite's unique index allows that).
     *
     * @param array<string, list<User>> $byEmail
     */
    private function find(array $byEmail, string $email): User|OrgImportProblem|null
    {
        $matches = $byEmail[self::normalize($email)] ?? [];

        return match (\count($matches)) {
            0 => null,
            1 => $matches[0],
            default => OrgImportProblem::AmbiguousEmail,
        };
    }

    private static function problemFor(OrgStructureError $violation): OrgImportProblem
    {
        return match ($violation) {
            OrgStructureError::OwnManager => OrgImportProblem::OwnManager,
            OrgStructureError::ManagerUnavailable => OrgImportProblem::ManagerUnavailable,
            OrgStructureError::Cycle => OrgImportProblem::Cycle,
            // Not reachable: byEmail() leaves deleted accounts out.
            OrgStructureError::PersonDeleted => OrgImportProblem::EmployeeNotFound,
        };
    }
}
